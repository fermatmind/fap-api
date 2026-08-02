<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use App\Support\OrgContext;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Exact-SHA authority for W3 English Articles; it does not own discoverability. */
/** @review-surface article */
final class ArticleCmsPromotionAuthority
{
    private const SNAPSHOT_FIELDS = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];

    private const ARTICLE_STATE_FIELDS = ['org_id', 'slug', 'locale', 'title', 'excerpt', 'content_md', 'content_html', 'cover_image_alt', 'related_test_slug', 'voice', 'voice_order', 'status', 'is_public', 'is_indexable', 'sitemap_eligible', 'llms_eligible', 'published_at'];

    public function __construct(
        private readonly ArticleController $publicApi,
        private readonly SeoDiscoverabilityCacheInvalidator $discoverabilityCache,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
        private readonly ArticleTranslationRevisionWorkspace $revisionWorkspace,
    ) {}

    /** @return array{targets:list<array<string,mixed>>,package_sha256:string} */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'W3' || $context->subscope !== 'articles') {
            throw new DomainException('article_promotion_context_invalid');
        }
        if (is_file($context->packageDirectory.'/promotion_manifest.json')) {
            return $this->inspectExternalFrozenPackage($context);
        }
        $manifestBytes = $this->read($context->packageDirectory, 'manifest.json');
        $manifest = $this->decode($manifestBytes, 'article_promotion_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== 'fermatmind.article_cms_promotion.v2'
            || ($manifest['lane'] ?? null) !== 'W3' || ($manifest['subscope'] ?? null) !== 'articles'
            || ($manifest['locale'] ?? null) !== 'en' || ! is_array($manifest['payloads'] ?? null)
            || ! is_array($manifest['permissions'] ?? null)) {
            throw new DomainException('article_promotion_manifest_contract_invalid');
        }
        foreach ($manifest['permissions'] as $value) {
            if ($value !== false) {
                throw new DomainException('article_promotion_permission_escalation');
            }
        }
        $payloads = $manifest['payloads'];
        usort($payloads, static fn (array $a, array $b): int => ((string) ($a['path'] ?? '')) <=> ((string) ($b['path'] ?? '')));
        $chain = '';
        $assets = null;
        $paths = [];
        foreach ($payloads as $payload) {
            $path = trim((string) ($payload['path'] ?? ''));
            $sha = strtolower(trim((string) ($payload['sha256'] ?? '')));
            if ($path === '' || basename($path) !== $path || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                throw new DomainException('article_promotion_payload_declaration_invalid');
            }
            $bytes = $this->read($context->packageDirectory, $path);
            if (! hash_equals($sha, hash('sha256', $bytes))) {
                throw new DomainException('article_promotion_payload_hash_invalid');
            }
            $paths[] = $path;
            $chain .= $path."\n".$sha."\n";
            if ($path === 'assets.json') {
                $assets = $this->decode($bytes, 'article_promotion_assets_invalid');
            }
        }
        // External W9 evidence binds to the frozen payload SHA; it is not part
        // of the producer package hash, otherwise its report hash would create
        // a circular self-reference.
        $chainManifest = $manifest;
        unset($chainManifest['package_sha256'], $chainManifest['quality_gates']);
        $packageSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($chainManifest))."\n".$chain);
        if ($paths !== ['assets.json'] || ! hash_equals($packageSha, $context->packageSha256) || ! hash_equals($packageSha, strtolower((string) ($manifest['package_sha256'] ?? '')))) {
            throw new DomainException('article_promotion_package_sha_invalid');
        }
        $rows = is_array($assets['assets'] ?? null) ? $assets['assets'] : null;
        if (! is_array($rows) || count($rows) !== $context->expectedRowCount || (int) ($manifest['expected_row_count'] ?? -1) !== $context->expectedRowCount) {
            throw new DomainException('article_promotion_target_count_invalid');
        }
        $this->assertW9($manifest, $packageSha, count($rows));
        $targets = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('article_promotion_target_invalid');
            }
            $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
            $org = (int) ($identity['org_id'] ?? -1);
            $slug = trim((string) ($identity['slug'] ?? ''));
            $locale = (string) ($identity['locale'] ?? '');
            $key = $org.':'.$locale.':'.$slug;
            if ($org < 0 || $slug === '' || $locale !== 'en' || isset($seen[$key])) {
                throw new DomainException('article_promotion_target_identity_invalid');
            }
            $seen[$key] = true;
            $snapshot = is_array($row['snapshot'] ?? null) ? $row['snapshot'] : [];
            $this->assertSnapshot($snapshot);
            if (str_starts_with($slug, 'big-five-') && preg_match('/\|\s*FermatMind\s*$/i', $snapshot['seo_title']) === 1) {
                throw new DomainException('article_promotion_seo_title_normalization_invalid');
            }
            $this->assertNoPrivatePayload($row);
            $article = Article::query()->withoutGlobalScopes()->where(['org_id' => $org, 'slug' => $slug, 'locale' => 'en'])->first();
            $publishedRevision = $article instanceof Article && $article->published_revision_id
                ? ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($article->published_revision_id)->where('article_id', $article->id)->first()
                : null;
            if (! $article instanceof Article || (string) $article->status !== 'published' || ! (bool) $article->is_public || ! $publishedRevision instanceof ArticleTranslationRevision || (string) $publishedRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                throw new DomainException('article_promotion_target_not_public_authority');
            }
            $targets[] = ['article' => $article, 'identity' => ['org_id' => $org, 'locale' => 'en', 'slug' => $slug], 'asset_key' => $key, 'snapshot' => $snapshot, 'source_hash' => hash('sha256', PromotionContextFactory::canonicalJson($row))];
        }
        usort($targets, static fn (array $a, array $b): int => $a['asset_key'] <=> $b['asset_key']);

        return ['targets' => $targets, 'package_sha256' => $packageSha];
    }

    /**
     * Reads the non-symlinked W3 producer snapshot without recomputing its
     * package identity. The promoted payload remains the exact fap-web package
     * named by external evidence; this backend envelope is only an executable
     * authority binding.
     *
     * @return array{targets:list<array<string,mixed>>,package_sha256:string}
     */
    private function inspectExternalFrozenPackage(PromotionContext $context): array
    {
        $manifest = $this->decode($this->read($context->packageDirectory, 'promotion_manifest.json'), 'article_promotion_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== 'fermatmind.article_cms_external_promotion.v3'
            || ($manifest['lane'] ?? null) !== 'W3' || ($manifest['subscope'] ?? null) !== 'articles'
            || ($manifest['locale'] ?? null) !== 'en' || (int) ($manifest['expected_row_count'] ?? 0) !== $context->expectedRowCount
            || ! is_array($manifest['permissions'] ?? null) || ($manifest['frozen_package_directory'] ?? null) !== 'frozen_package'
            || ($manifest['external_evidence'] ?? null) !== 'external_package_evidence.json') {
            throw new DomainException('article_promotion_manifest_contract_invalid');
        }
        foreach ($manifest['permissions'] as $value) {
            if ($value !== false) {
                throw new DomainException('article_promotion_permission_escalation');
            }
        }
        $evidence = $this->decode($this->read($context->packageDirectory, 'external_package_evidence.json'), 'article_promotion_external_evidence_invalid');
        if (($evidence['schema_version'] ?? null) !== 'fermatmind.en_content_parity_external_package_evidence.v2'
            || ($evidence['lane_id'] ?? null) !== 'W3' || ($evidence['subscope_id'] ?? null) !== 'W3-ARTICLES'
            || ($evidence['resource'] ?? null) !== 'Article'
            || ($evidence['producer']['source_repository'] ?? null) !== 'fap-web'
            || preg_match('/\A[a-f0-9]{40}\z/', (string) ($evidence['producer']['source_commit_sha'] ?? '')) !== 1
            || ($evidence['producer']['source_package_path'] ?? null) !== 'generated/en-content-parity/W3-editorial-cms/articles'
            || ! hash_equals((string) ($evidence['producer']['package_sha256'] ?? ''), $context->packageSha256)
            || ! hash_equals((string) ($manifest['effective_package_sha256'] ?? ''), $context->packageSha256)) {
            throw new DomainException('article_promotion_external_evidence_invalid');
        }
        $payloads = is_array($evidence['immutable_payloads'] ?? null) ? $evidence['immutable_payloads'] : [];
        $expectedPaths = [
            'frozen_package/assets.jsonl', 'frozen_package/claim_boundary_report.json', 'frozen_package/dry_run_readiness.json',
            'frozen_package/editorial_review.json', 'frozen_package/handoff.md', 'frozen_package/master_manifest_patch.candidate.json',
            'frozen_package/scope_manifest.json', 'frozen_package/sha256_manifest.json', 'frozen_package/source_ledger.json',
            'frozen_package/translation_map.json',
        ];
        $declared = [];
        foreach ($payloads as $payload) {
            $path = (string) ($payload['path'] ?? '');
            $sha = (string) ($payload['sha256'] ?? '');
            if (! in_array($path, $expectedPaths, true) || isset($declared[$path]) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1
                || ! hash_equals($sha, hash('sha256', $this->readRelative($context->packageDirectory, $path)))) {
                throw new DomainException('article_promotion_external_inventory_invalid');
            }
            $declared[$path] = $sha;
        }
        ksort($declared, SORT_STRING);
        $sortedExpected = $expectedPaths;
        sort($sortedExpected, SORT_STRING);
        $expectedPhysicalFiles = array_merge(['external_package_evidence.json', 'promotion_manifest.json'], $sortedExpected);
        sort($expectedPhysicalFiles, SORT_STRING);
        if (array_keys($declared) !== $sortedExpected || $this->packageFiles($context->packageDirectory) !== $expectedPhysicalFiles) {
            throw new DomainException('article_promotion_external_inventory_invalid');
        }
        $packageManifest = (array) ($evidence['frozen_package_manifest'] ?? []);
        if (($packageManifest['path'] ?? null) !== 'frozen_package/sha256_manifest.json'
            || ! hash_equals((string) ($packageManifest['sha256'] ?? ''), $declared['frozen_package/sha256_manifest.json'])) {
            throw new DomainException('article_promotion_external_evidence_invalid');
        }
        $frozenManifest = $this->decode($this->readRelative($context->packageDirectory, 'frozen_package/sha256_manifest.json'), 'article_promotion_external_evidence_invalid');
        if (($frozenManifest['schema_version'] ?? null) !== 'fermatmind.en_content_parity_package_sha256_manifest.v1'
            || ($frozenManifest['lane_id'] ?? null) !== 'W3' || ($frozenManifest['subscope_id'] ?? null) !== 'W3-ARTICLES'
            || ! hash_equals((string) ($frozenManifest['package_sha256'] ?? ''), $context->packageSha256)) {
            throw new DomainException('article_promotion_package_sha_invalid');
        }
        foreach ((array) ($frozenManifest['files'] ?? []) as $file) {
            $path = 'frozen_package/'.(string) ($file['path'] ?? '');
            if (! isset($declared[$path]) || ! hash_equals((string) ($file['sha256'] ?? ''), $declared[$path])) {
                throw new DomainException('article_promotion_external_inventory_invalid');
            }
        }
        $w9 = (array) ($evidence['independent_w9'] ?? []);
        if (($w9['path'] ?? null) !== 'articles/d70e468b/independent_qa_report.json'
            || ! hash_equals((string) ($w9['sha256'] ?? ''), 'a286486e040b410a28224732e6a4cf61d42255db43e92bba3905bdf0af52caf4')
            || (int) ($w9['expected_row_count'] ?? 0) !== $context->expectedRowCount) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $w9Bytes = $this->readRelative((string) config('content_promotion.w9_authority_root'), (string) $w9['path']);
        if (! hash_equals((string) $w9['sha256'], hash('sha256', $w9Bytes))) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $w9Report = $this->decode($w9Bytes, 'article_promotion_w9_evidence_incomplete');
        if (($w9Report['schema_version'] ?? null) !== 'fermatmind.en_content_parity_independent_qa_report.v1'
            || ($w9Report['qa_lane_id'] ?? null) !== 'W9' || ($w9Report['producer_lane_id'] ?? null) !== 'W3'
            || ($w9Report['subscope_id'] ?? null) !== 'W3-ARTICLES' || ($w9Report['verdict'] ?? null) !== 'PASS'
            || ! hash_equals((string) ($w9Report['package_sha256'] ?? ''), $context->packageSha256)
            || (int) ($w9Report['reviewed_row_count'] ?? 0) !== $context->expectedRowCount) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $ledger = $this->decode($this->readRelative($context->packageDirectory, 'frozen_package/source_ledger.json'), 'article_promotion_assets_invalid');
        $rows = is_array($ledger['rows'] ?? null) ? $ledger['rows'] : [];
        if (count($rows) !== $context->expectedRowCount) {
            throw new DomainException('article_promotion_target_count_invalid');
        }
        $targets = [];
        $seen = [];
        foreach ($rows as $row) {
            $sourceId = (int) ($row['source_article_id'] ?? 0);
            $sourceRevisionId = (int) ($row['source_revision_id'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            $pair = trim((string) ($row['translation_pair_identity'] ?? ''));
            $key = '0:en:'.$slug;
            $snapshot = ['title' => $row['candidate_title'] ?? null, 'excerpt' => $row['candidate_excerpt'] ?? null, 'content_md' => $row['candidate_content_md'] ?? null, 'seo_title' => null, 'seo_description' => null];
            if ($sourceId < 1 || $sourceRevisionId < 1 || $slug === '' || $pair === '' || isset($seen[$key])
                || ($row['target_locale'] ?? null) !== 'en' || ($row['source_locale'] ?? null) !== 'zh-CN'
                || ($row['target_publication_status'] ?? null) !== 'candidate_only_not_imported') {
                throw new DomainException('article_promotion_target_identity_invalid');
            }
            $this->assertExternalSnapshot($snapshot);
            $source = Article::query()->withoutGlobalScopes()->find($sourceId);
            $sourceRevision = $source instanceof Article ? ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($sourceRevisionId)->where('article_id', $source->id)->first() : null;
            if (! $source instanceof Article || ! $sourceRevision instanceof ArticleTranslationRevision || (string) $source->locale !== 'zh-CN'
                || (string) $source->status !== 'published' || ! (bool) $source->is_public
                || (string) $sourceRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                || (int) $source->published_revision_id !== $sourceRevisionId
                || ! hash_equals((string) $source->translation_group_id, $pair)) {
                throw new DomainException('article_promotion_translation_source_drift');
            }
            $article = Article::query()->withoutGlobalScopes()->where(['org_id' => $source->org_id, 'slug' => $slug, 'locale' => 'en'])->first();
            if ($article instanceof Article && ((int) $article->source_article_id !== (int) $source->id
                || ! hash_equals((string) $article->translation_group_id, $pair)
                || (string) $article->source_locale !== 'zh-CN')) {
                throw new DomainException('article_promotion_translation_pair_invalid');
            }
            $seen[$key] = true;
            $targets[] = ['article' => $article, 'source_article' => $source, 'source_revision' => $sourceRevision,
                'identity' => ['org_id' => (int) $source->org_id, 'locale' => 'en', 'slug' => $slug], 'asset_key' => ((int) $source->org_id).':en:'.$slug,
                'translation_pair_identity' => $pair, 'candidate_only' => true, 'snapshot' => $snapshot,
                'source_hash' => hash('sha256', PromotionContextFactory::canonicalJson($row))];
        }
        usort($targets, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        return ['targets' => $targets, 'package_sha256' => $context->packageSha256];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        $result = DB::transaction(function () use ($context, $package): array {
            $created = 0;
            foreach ($package['targets'] as $target) {
                $article = $target['article'] instanceof Article
                    ? Article::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($target['article']->id)
                    : $this->createCandidateArticle($target);
                $revision = $this->exactRevision($article, $context, $target);
                if ($revision instanceof ArticleTranslationRevision) {
                    if ((int) $article->working_revision_id !== (int) $revision->id && (int) $article->published_revision_id !== (int) $revision->id) {
                        throw new DomainException('article_promotion_revision_collision');
                    }

                    continue;
                }
                if ($article->working_revision_id !== null) {
                    throw new DomainException('article_promotion_foreign_working_revision');
                }
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create($this->revisionPayload($article, $context, $target));
                $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
                ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
                    'org_id' => $article->org_id, 'article_id' => $article->id, 'slug' => $article->slug, 'locale' => 'en', 'title' => $revision->title,
                    'content_track' => 'content-promotion-w3-articles', 'status' => ArticleEditorialPackageImport::STATUS_IMPORTED, 'intended_status' => ArticleTranslationRevision::STATUS_APPROVED,
                    'validation_summary_json' => ['exact_package_sha256' => $context->packageSha256, 'asset_key' => $target['asset_key']],
                    'exactness_json' => ['authority_source_hash' => $target['source_hash'], 'authority_package_sha256' => $context->packageSha256],
                    'body_hash' => hash('sha256', (string) $revision->content_md), 'references_count' => 0, 'imported_by' => null,
                ]);
                $created++;
            }

            return ['created_count' => $created, 'unchanged_count' => count($package['targets']) - $created, 'readback_count' => count($package['targets'])];
        }, 3);

        return $result;
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    /** @param list<array<string,mixed>> $beforePublicationRows */
    public function publish(PromotionContext $context, array $beforePublicationRows): array
    {
        $package = $this->inspect($context);
        $beforeByAsset = [];
        foreach ($beforePublicationRows as $row) {
            $assetKey = (string) ($row['asset_key'] ?? '');
            if ((string) ($row['package_sha256'] ?? '') !== $context->packageSha256 || $assetKey === '' || isset($beforeByAsset[$assetKey])) {
                throw new DomainException('article_promotion_seo_precondition_invalid');
            }
            $beforeByAsset[$assetKey] = $row;
        }

        $result = DB::transaction(function () use ($context, $package, $beforeByAsset): array {
            $changed = 0;
            foreach ($package['targets'] as $target) {
                $article = Article::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($target['article']->id);
                $revision = $this->exactRevision($article, $context, $target);
                if (! $revision instanceof ArticleTranslationRevision) {
                    throw new DomainException('article_promotion_draft_missing');
                }
                if ((int) $article->published_revision_id === (int) $revision->id && $article->working_revision_id === null) {
                    continue;
                }
                if ((int) $article->working_revision_id !== (int) $revision->id || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED
                    || ! hash_equals((string) data_get($revision->authority_metadata_json, 'article_state_sha256'), $this->articleStateHash($article))) {
                    throw new DomainException('article_promotion_working_revision_invalid');
                }
                if (! hash_equals(PromotionContextFactory::canonicalJson((array) $target['snapshot']), PromotionContextFactory::canonicalJson([
                    'title' => $revision->title, 'excerpt' => $revision->excerpt, 'content_md' => $revision->content_md,
                    'seo_title' => $revision->seo_title, 'seo_description' => $revision->seo_description,
                ]))) {
                    throw new DomainException('article_promotion_revision_payload_drift');
                }
                $this->assertTranslationSourcePrecondition($article, $revision);
                $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->lockForUpdate()
                    ->where('org_id', $article->org_id)
                    ->where('article_id', $article->id)
                    ->where('locale', $article->locale)
                    ->first();
                $this->assertSeoPrecondition($seo, $beforeByAsset[$target['asset_key']] ?? null);
                if ($article->published_revision_id) {
                    ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($article->published_revision_id)->where('article_id', $article->id)->update(['revision_status' => ArticleTranslationRevision::STATUS_STALE]);
                }
                $publishedAt = Carbon::parse((string) data_get($beforeByAsset, $target['asset_key'].'.publication_timestamp'));
                $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => $publishedAt])->saveQuietly();
                $projection = ['title' => $revision->title, 'excerpt' => $revision->excerpt, 'content_md' => $revision->content_md, 'content_html' => null, 'published_revision_id' => $revision->id, 'working_revision_id' => null];
                if (($target['candidate_only'] ?? false) === true) {
                    $projection += ['status' => 'published', 'is_public' => true, 'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED, 'published_at' => $publishedAt];
                }
                $article->forceFill($projection)->save();
                $this->syncExistingSeoMeta($revision, $seo);
                $this->logPublication($article);
                $changed++;
            }

            return ['changed_count' => $changed, 'unchanged_count' => count($package['targets']) - $changed, 'readback_count' => count($package['targets'])];
        }, 3);
        $this->invalidateDiscoverabilityCaches();

        return $result;
    }

    /** @return array{readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        foreach ($package['targets'] as $target) {
            $article = $target['article'] instanceof Article ? Article::query()->withoutGlobalScopes()->find($target['article']->id) : null;
            $revision = $article instanceof Article ? $this->exactRevision($article, $context, $target) : null;
            if (! $article instanceof Article || ! $revision instanceof ArticleTranslationRevision || $article->working_revision_id !== null || (int) $article->published_revision_id !== (int) $revision->id || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                throw new DomainException('article_promotion_public_projection_invalid');
            }
            $response = $this->publicApi->show(Request::create('/api/v0.5/articles/'.$article->slug, 'GET', ['locale' => 'en', 'org_id' => $article->org_id]), $article->slug);
            $payload = $response->getData(true);
            $publicSnapshot = ['title' => data_get($payload, 'article.title'), 'excerpt' => data_get($payload, 'article.excerpt'), 'content_md' => data_get($payload, 'article.content_md')];
            $expectedSnapshot = array_intersect_key((array) $target['snapshot'], $publicSnapshot);
            if (($target['candidate_only'] ?? false) !== true) {
                $publicSnapshot += ['seo_title' => $revision->seo_title, 'seo_description' => $revision->seo_description];
                $expectedSnapshot = (array) $target['snapshot'];
            }
            if (($payload['ok'] ?? false) !== true || ! hash_equals(PromotionContextFactory::canonicalJson($expectedSnapshot), PromotionContextFactory::canonicalJson($publicSnapshot))) {
                throw new DomainException('article_promotion_public_api_readback_invalid');
            }
            if (($target['candidate_only'] ?? false) !== true && (! hash_equals((string) $revision->seo_title, (string) data_get($payload, 'seo_surface_v1.title'))
                || ! hash_equals((string) $revision->seo_description, (string) data_get($payload, 'seo_surface_v1.description'))
                || ! hash_equals((string) $revision->seo_title, (string) data_get($payload, 'seo_surface_v1.og_payload.title'))
                || ! hash_equals((string) $revision->seo_description, (string) data_get($payload, 'seo_surface_v1.og_payload.description')))) {
                throw new DomainException('article_promotion_public_seo_readback_invalid');
            }
        }

        return ['readback_count' => count($package['targets'])];
    }

    /** @param array<string,mixed> $target */
    private function exactRevision(Article $article, PromotionContext $context, array $target): ?ArticleTranslationRevision
    {
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $target['asset_key'])->first();
        $metadata = $revision instanceof ArticleTranslationRevision && is_array($revision->authority_metadata_json)
            ? $revision->authority_metadata_json
            : [];
        if ($revision instanceof ArticleTranslationRevision && ((int) $revision->article_id !== (int) $article->id || ! hash_equals((string) $revision->authority_source_hash, $target['source_hash']) || ! hash_equals(PromotionContextFactory::canonicalJson((array) ($metadata['snapshot'] ?? [])), PromotionContextFactory::canonicalJson($target['snapshot'])))) {
            throw new DomainException('article_promotion_revision_collision');
        }

        return $revision;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function revisionPayload(Article $article, PromotionContext $context, array $target): array
    {
        $snapshot = $target['snapshot'];
        $sourceRevision = $target['source_revision'] ?? null;
        $revisionSourceHash = $sourceRevision instanceof ArticleTranslationRevision
            ? (string) $sourceRevision->source_version_hash
            : ($article->isSourceArticle()
            ? $this->projectedSourceVersionHash($article, $snapshot)
            : ($this->revisionWorkspace->sourceVersionHashFor($article) ?: $article->source_version_hash));

        return ['org_id' => $article->org_id, 'article_id' => $article->id, 'source_article_id' => $article->source_article_id ?: $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => $article->source_locale ?: 'en', 'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1, 'revision_status' => ArticleTranslationRevision::STATUS_APPROVED, 'source_version_hash' => $revisionSourceHash, 'translated_from_version_hash' => $article->isSourceArticle() ? $revisionSourceHash : ($article->translated_from_version_hash ?: $revisionSourceHash), 'supersedes_revision_id' => $article->working_revision_id ?: $article->published_revision_id, 'authority_asset_key' => $target['asset_key'], 'authority_source_package' => 'content-promotion/W3/articles', 'authority_source_hash' => $target['source_hash'], 'authority_package_sha256' => $context->packageSha256, 'authority_metadata_json' => ['snapshot' => $snapshot, 'article_state_sha256' => $this->articleStateHash($article), 'candidate_only' => (bool) ($target['candidate_only'] ?? false)], 'title' => $snapshot['title'], 'excerpt' => $snapshot['excerpt'], 'content_md' => $snapshot['content_md'], 'seo_title' => $snapshot['seo_title'], 'seo_description' => $snapshot['seo_description'], 'approved_at' => now()];
    }

    /** @param array<string,mixed> $target */
    private function createCandidateArticle(array $target): Article
    {
        $source = $target['source_article'] ?? null;
        if (! $source instanceof Article) {
            throw new DomainException('article_promotion_translation_source_drift');
        }
        $lockedSource = Article::query()->withoutGlobalScopes()->lockForUpdate()->find($source->id);
        if (! $lockedSource instanceof Article || (string) $lockedSource->status !== 'published' || ! (bool) $lockedSource->is_public) {
            throw new DomainException('article_promotion_translation_source_drift');
        }
        $snapshot = (array) $target['snapshot'];

        return Article::query()->withoutGlobalScopes()->create([
            'org_id' => $lockedSource->org_id, 'category_id' => $lockedSource->category_id, 'author_admin_user_id' => $lockedSource->author_admin_user_id,
            'author_name' => $lockedSource->author_name, 'reviewer_name' => $lockedSource->reviewer_name, 'reading_minutes' => $lockedSource->reading_minutes,
            'slug' => $target['identity']['slug'], 'locale' => 'en', 'translation_group_id' => $target['translation_pair_identity'],
            'source_locale' => 'zh-CN', 'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            'source_article_id' => $lockedSource->id, 'translated_from_article_id' => $lockedSource->id,
            'translated_from_version_hash' => $lockedSource->source_version_hash, 'title' => $snapshot['title'], 'excerpt' => $snapshot['excerpt'],
            'content_md' => $snapshot['content_md'], 'content_html' => null, 'cover_image_url' => null, 'cover_image_alt' => null,
            'cover_image_width' => null, 'cover_image_height' => null, 'cover_image_variants' => null, 'related_test_slug' => $target['related_test_slug'] ?? null,
            'voice' => $lockedSource->voice, 'voice_order' => $lockedSource->voice_order, 'status' => 'draft', 'is_public' => false,
            'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => null,
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(array $snapshot): void
    {
        $keys = array_keys($snapshot);
        sort($keys);
        $expected = self::SNAPSHOT_FIELDS;
        sort($expected);
        if ($keys !== $expected || ! is_string($snapshot['title']) || ! is_string($snapshot['content_md']) || ! is_string($snapshot['excerpt']) || ! is_string($snapshot['seo_title']) || ! is_string($snapshot['seo_description']) || trim($snapshot['title']) === '' || trim($snapshot['content_md']) === '' || trim($snapshot['seo_title']) === '' || trim($snapshot['seo_description']) === '' || mb_strlen($snapshot['title']) > 255 || mb_strlen($snapshot['seo_title']) > 60 || mb_strlen($snapshot['seo_description']) > 160) {
            throw new DomainException('article_promotion_snapshot_invalid');
        }
        if (preg_match('/[\p{Han}]/u', implode("\n", $snapshot)) === 1) {
            throw new DomainException('article_promotion_cjk_leakage');
        }
        try {
            $this->articleBodyHeadingGuard->assertNoBodyH1($snapshot['content_md']);
        } catch (\InvalidArgumentException $exception) {
            throw new DomainException('article_promotion_body_h1_invalid', previous: $exception);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertExternalSnapshot(array $snapshot): void
    {
        if (! is_string($snapshot['title'] ?? null) || ! is_string($snapshot['excerpt'] ?? null)
            || ! is_string($snapshot['content_md'] ?? null) || trim((string) $snapshot['title']) === ''
            || trim((string) $snapshot['content_md']) === '' || mb_strlen((string) $snapshot['title']) > 255
            || preg_match('/[\p{Han}]/u', implode("\n", array_filter($snapshot, 'is_string'))) === 1) {
            throw new DomainException('article_promotion_snapshot_invalid');
        }
        $this->articleBodyHeadingGuard->assertNoBodyH1((string) $snapshot['content_md']);
        $this->assertNoPrivatePayload($snapshot);
    }

    private function assertNoPrivatePayload(mixed $value, ?string $key = null): void
    {
        if (is_string($key) && preg_match('/(?:attempt|report|order|payment|token|user|score|percentile)/i', $key) === 1) {
            throw new DomainException('article_promotion_private_payload_invalid');
        }
        if (is_string($value) && preg_match('~/(?:account|attempts?|checkout|history|orders?|payments?|pay|private|recovery|reports?|results?|shares?)(?:/|[?#\s]|$)|[?&](?:token|attempt(?:_id)?|report(?:_id)?|order(?:_id)?|payment(?:_id)?|checkout(?:_id)?|share(?:_id)?|user(?:_id)?)=~i', $value) === 1) {
            throw new DomainException('article_promotion_private_payload_invalid');
        }
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nested) {
                $this->assertNoPrivatePayload($nested, (string) $nestedKey);
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertW9(array $manifest, string $packageSha, int $rows): void
    {
        $gate = data_get($manifest, 'quality_gates.independent_w9');
        $rootConfig = (string) config('content_promotion.w9_authority_root');
        if (! is_array($gate) || ($gate['status'] ?? null) !== 'pass' || ! is_string($gate['report_ref'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', (string) ($gate['report_sha256'] ?? '')) !== 1) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $root = realpath(str_starts_with($rootConfig, DIRECTORY_SEPARATOR) ? $rootConfig : base_path($rootConfig));
        $ref = (string) $gate['report_ref'];
        if ($root === false || basename($ref) !== $ref) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $bytes = $this->read($root, $ref);
        if (! hash_equals((string) $gate['report_sha256'], hash('sha256', $bytes))) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
        $report = $this->decode($bytes, 'article_promotion_w9_evidence_incomplete');
        if (($report['schema_version'] ?? null) !== 'fermatmind.en_parity.independent_w9_report.v1' || ($report['review_kind'] ?? null) !== 'independent_w9' || ($report['verdict'] ?? null) !== 'PASS' || ($report['package_sha256'] ?? null) !== $packageSha || ($report['lane_id'] ?? null) !== 'W3' || ($report['subscope'] ?? null) !== 'articles' || (int) ($report['reviewed_row_count'] ?? 0) !== $rows) {
            throw new DomainException('article_promotion_w9_evidence_incomplete');
        }
    }

    private function articleStateHash(Article $article): string
    {
        $state = [];
        foreach (self::ARTICLE_STATE_FIELDS as $field) {
            $state[$field] = $article->getAttribute($field);
        }

        return hash('sha256', PromotionContextFactory::canonicalJson($state));
    }

    /** @param array<string,mixed> $snapshot */
    private function projectedSourceVersionHash(Article $article, array $snapshot): string
    {
        return Article::sourceVersionHashFromPayload([
            'locale' => $article->locale,
            'title' => $snapshot['title'],
            'excerpt' => $snapshot['excerpt'],
            'content_md' => $snapshot['content_md'],
            'content_html' => null,
            'cover_image_alt' => $article->cover_image_alt,
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ]);
    }

    public function invalidateDiscoverabilityCaches(): void
    {
        $this->discoverabilityCache->flushArticleDiscoverabilityCaches();
    }

    private function logPublication(Article $article): void
    {
        $orgContext = app(OrgContext::class);
        $prior = [$orgContext->orgId(), $orgContext->userId(), $orgContext->role(), $orgContext->anonId(), $orgContext->contextKind()];
        $orgContext->set((int) $article->org_id, $prior[1], $prior[2], $prior[3], OrgContext::deriveContextKind((int) $article->org_id));
        try {
            ContentReleaseAudit::log('article', $article, 'content_promotion_w3_articles_exact_package', false);
        } finally {
            $orgContext->set($prior[0], $prior[1], $prior[2], $prior[3], $prior[4]);
        }
    }

    /** @param array<string,mixed>|null $row */
    private function assertSeoPrecondition(?ArticleSeoMeta $seo, ?array $row): void
    {
        if (! is_array($row)) {
            throw new DomainException('article_promotion_seo_precondition_invalid');
        }
        $before = (array) ($row['seo_before'] ?? []);
        $expectedId = (int) ($before['id'] ?? 0);
        $expected = (int) ($before['id'] ?? 0) > 0 ? (array) ($before['values'] ?? []) : [];
        $actual = $seo instanceof ArticleSeoMeta ? $this->seoState($seo) : [];
        if ($expectedId !== (int) ($seo?->id ?? 0) || ! hash_equals(PromotionContextFactory::canonicalJson($expected), PromotionContextFactory::canonicalJson($actual))) {
            throw new DomainException('article_promotion_seo_precondition_drift');
        }
    }

    private function assertTranslationSourcePrecondition(Article $article, ArticleTranslationRevision $revision): void
    {
        if ($article->isSourceArticle()) {
            return;
        }
        $sourceId = (int) ($article->source_article_id ?: $article->translated_from_article_id);
        $source = $sourceId > 0
            ? Article::query()->withoutGlobalScopes()->lockForUpdate()->find($sourceId)
            : null;
        $sourceRevision = $source instanceof Article && $source->working_revision_id
            ? ArticleTranslationRevision::query()->withoutGlobalScopes()->lockForUpdate()
                ->where('article_id', $source->id)->find($source->working_revision_id)
            : null;
        $sourceHash = $sourceRevision instanceof ArticleTranslationRevision && filled($sourceRevision->source_version_hash)
            ? (string) $sourceRevision->source_version_hash
            : (string) ($source?->source_version_hash ?? '');
        if (! $source instanceof Article || $sourceHash === '' || (int) $revision->source_article_id !== (int) $source->id
            || ! hash_equals($sourceHash, (string) $revision->source_version_hash)
            || ! hash_equals($sourceHash, (string) $revision->translated_from_version_hash)) {
            throw new DomainException('article_promotion_translation_source_drift');
        }
    }

    private function syncExistingSeoMeta(ArticleTranslationRevision $revision, ?ArticleSeoMeta $seo): void
    {
        $updates = [];
        if (filled($revision->seo_title)) {
            $updates['seo_title'] = (string) $revision->seo_title;
            $updates['og_title'] = (string) $revision->seo_title;
        }
        if (filled($revision->seo_description)) {
            $updates['seo_description'] = (string) $revision->seo_description;
            $updates['og_description'] = (string) $revision->seo_description;
        }
        if ($updates !== [] && $seo instanceof ArticleSeoMeta) {
            $seo->forceFill($updates)->saveQuietly();
        }
    }

    /** @return array<string,mixed> */
    private function seoState(ArticleSeoMeta $seo): array
    {
        return [
            'seo_title' => $seo->seo_title,
            'seo_description' => $seo->seo_description,
            'og_title' => $seo->og_title,
            'og_description' => $seo->og_description,
        ];
    }

    private function read(string $root, string $name): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        $realRoot = realpath($root);
        $resolved = realpath($path);
        if ($name === '' || basename($name) !== $name || $realRoot === false || $resolved === false || dirname($resolved) !== $realRoot || is_link($path) || ! is_file($path)) {
            throw new DomainException('article_promotion_payload_missing');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new DomainException('article_promotion_payload_missing');
        }

        return $bytes;
    }

    private function readRelative(string $root, string $path): string
    {
        $root = str_starts_with($root, DIRECTORY_SEPARATOR) ? $root : base_path($root);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#\A[A-Za-z0-9._/-]+\z#', $path) !== 1) {
            throw new DomainException('article_promotion_payload_missing');
        }
        $realRoot = realpath($root);
        $candidate = $root.DIRECTORY_SEPARATOR.$path;
        $resolved = realpath($candidate);
        if ($realRoot === false || $resolved === false || ! str_starts_with($resolved, $realRoot.DIRECTORY_SEPARATOR) || ! is_file($candidate) || is_link($candidate)) {
            throw new DomainException('article_promotion_payload_missing');
        }
        $cursor = $root;
        foreach (explode('/', $path) as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor)) {
                throw new DomainException('article_promotion_payload_missing');
            }
        }
        $bytes = file_get_contents($candidate);
        if (! is_string($bytes)) {
            throw new DomainException('article_promotion_payload_missing');
        }

        return $bytes;
    }

    /** @return list<string> */
    private function packageFiles(string $root): array
    {
        $realRoot = realpath($root);
        if ($realRoot === false || is_link($root)) {
            throw new DomainException('article_promotion_external_inventory_invalid');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                throw new DomainException('article_promotion_external_inventory_invalid');
            }
            $path = substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            if (! is_string($path) || $path === '') {
                throw new DomainException('article_promotion_external_inventory_invalid');
            }
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException($error);
        }
        if (! is_array($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }
}
