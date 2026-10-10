<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentMaterialDecision;
use App\Models\ContentReleaseSnapshot;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\IqPublicArticlePackage;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Publishes the fixed IQ Article package while retaining foreign CMS drafts. */
final class IqPublicArticlePromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'IQ-PUBLIC-ARTICLES';

    private const PACK = 'iq-public-articles';

    private const ARTICLE_COPY = ['title', 'excerpt', 'content_md', 'content_html', 'source_version_hash', 'translated_from_version_hash'];

    private const SEO_COPY = ['seo_title', 'seo_description', 'og_title', 'og_description'];

    private const FAQ_PATHS = ['answer_surface_policy', 'answer_surface_visibility', 'answer_surface_v1.faq_items', 'iq_article_exact_package_v1'];

    public function __construct(
        private readonly IqPublicArticlePackage $package,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly ArticleMaterialDecisionService $decisions,
        private readonly SeoDiscoverabilityCacheInvalidator $cache,
        private readonly ArticleController $publicApi,
    ) {}

    public function id(): string
    {
        return 'iq_public_articles_v1';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W3' && $subscope === self::SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $state = $this->state($rows, false);
        $this->assertPreflight($rows, $state, $context);
        if ($this->sourceSnapshots($context)->isNotEmpty()) {
            try {
                $this->assertPublished($rows, $context);
                $this->assertPublicationOwner($rows, (array) $this->sourceSnapshots($context)->first()->meta_json);
            } catch (Throwable $error) {
                // Failed releases are terminal. The delivery contract requires
                // a new corrective commit, never a retry of the failed SHA.
                throw new DomainException('iq_article_failed_source_requires_new_commit', previous: $error);
            }
        }

        return $this->result($context, 0, 0, null, $state);
    }

    public function draftImport(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'content_promotion_preflight_receipt');
        $written = 0;
        $createdCount = 0;
        DB::transaction(function () use ($rows, $context, $previous, &$written, &$createdCount): void {
            $before = $this->state($rows, true);
            $this->assertState($previous, $before);
            $this->assertPreflight($rows, $before, $context);
            foreach ($rows as $row) {
                $article = $this->article($row, true);
                $created = false;
                if (! $article) {
                    $source = $row['identity']['locale'] === 'en'
                        ? $this->article(['identity' => [...$row['identity'], 'locale' => 'zh-CN']], true)
                        : null;
                    if ($row['identity']['locale'] === 'en' && ! $source) {
                        throw new DomainException('iq_article_source_pair_missing');
                    }
                    $article = Article::query()->withoutGlobalScopes()->create([
                        ...$row['identity'], 'source_locale' => 'zh-CN', 'author_name' => 'FermatMind',
                        'translation_status' => $source ? Article::TRANSLATION_STATUS_APPROVED : Article::TRANSLATION_STATUS_SOURCE,
                        'source_article_id' => $source?->id, 'translated_from_article_id' => $source?->id,
                        'translation_group_id' => $source?->translation_group_id,
                        'title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'], 'content_md' => $row['snapshot']['content_md'],
                        'status' => 'draft', 'is_public' => false, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false,
                    ]);
                    ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                        'org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale,
                        'seo_title' => $row['snapshot']['seo_title'], 'seo_description' => $row['snapshot']['seo_description'], 'is_indexable' => false,
                    ]);
                    $created = true;
                    $createdCount++;
                }
                $existingRevision = $this->revision($article, $context);
                if ($existingRevision) {
                    $this->assertRevision($row, $article, $existingRevision, $context, $this->sourceRow($rows, $row)['snapshot']);

                    continue;
                }
                $sourceRow = $this->sourceRow($rows, $row);
                $source = $this->article($sourceRow, true);
                if (! $source || ($article->locale === 'en' && ((int) $article->source_article_id !== (int) $source->id || $article->translation_group_id !== $source->translation_group_id))) {
                    throw new DomainException('iq_article_source_pair_invalid');
                }
                $sourceHash = $this->projectedHash($source, $sourceRow['snapshot']);
                ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                    'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source->id,
                    'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale, 'source_locale' => 'zh-CN',
                    'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1,
                    'revision_status' => ArticleTranslationRevision::STATUS_APPROVED, 'source_version_hash' => $sourceHash,
                    'translated_from_version_hash' => $article->locale === 'en' ? $sourceHash : null,
                    'supersedes_revision_id' => $article->published_revision_id,
                    'authority_asset_key' => $this->key($row), 'authority_source_package' => IqPublicArticlePackage::PACKAGE,
                    'authority_source_hash' => $row['snapshot_sha256'], 'authority_package_sha256' => $context->packageSha256,
                    'authority_metadata_json' => ['snapshot' => $row['snapshot'], 'approval_kind' => 'verified_automated_exact_package', 'source_commit' => $context->sourceCommit, 'created_identity_by_package' => $created],
                    ...Arr::only($row['snapshot'], ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description']),
                    'approved_at' => now(),
                ]);
                // The package draft is separate from the operator's working pointer.
                $written++;
            }
        }, 3);

        return $this->result($context, $written, 0, null, $this->state($rows, false), $createdCount);
    }

    public function publish(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'cms_draft_import_receipt');
        $reference = null;
        $written = 0;
        $admittedState = null;
        DB::transaction(function () use ($rows, $context, $previous, &$reference, &$admittedState): void {
            $before = $this->state($rows, true);
            $existing = $this->snapshot($context);
            if ($existing) {
                try {
                    $this->assertPublished($rows, $context);
                    $this->assertPublicationOwner($rows, (array) $existing->meta_json);
                } catch (Throwable $error) {
                    throw new DomainException('iq_article_failed_source_requires_new_commit', previous: $error);
                }
                $reference = 'content-release-snapshot:'.$existing->id;

                return;
            }
            $this->assertState($previous, $before);
            $alreadyPublished = true;
            foreach ($rows as $row) {
                $article = $this->article($row, true);
                $revision = $article ? $this->revision($article, $context) : null;
                $alreadyPublished = $alreadyPublished && $revision && (int) $article->published_revision_id === (int) $revision->id;
            }
            if ($alreadyPublished) {
                $this->assertPublished($rows, $context);

                return;
            }
            if ($this->sourceSnapshots($context)->isNotEmpty()) {
                throw new DomainException('iq_article_failed_source_requires_new_commit');
            }
            $reference = $this->snapshots->capture($context, $this->targets($rows), self::PACK, 'before_publication', array_values($before), array_column($rows, 'identity'), [
                'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
                'executor_release_sha256' => $context->executorReleaseSha256,
            ]);
            $admittedState = $before;
        }, 3);
        // Persist the existing immutable recovery authority before business
        // writes, so transaction failure cannot erase the failed source SHA.
        if ($admittedState !== null) {
            DB::transaction(function () use ($rows, $context, $admittedState, $reference, &$written): void {
                $current = $this->state($rows, true);
                $this->assertState(['target_state_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($admittedState))], $current);
                $this->assertPreflight($rows, $current, $context);
                foreach ($rows as $row) {
                    $article = $this->article($row, true);
                    $revision = $article ? $this->revision($article, $context) : null;
                    if (! $article || ! $revision || $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                        throw new DomainException('iq_article_exact_draft_missing');
                    }
                    $this->assertRevision($row, $article, $revision, $context, $this->sourceRow($rows, $row)['snapshot']);
                    $prior = $article->publishedRevision;
                    if ($prior) {
                        $prior->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_STALE])->saveQuietly();
                    }
                    $now = now();
                    $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => $now])->saveQuietly();
                    $patch = [...Arr::only($row['snapshot'], ['title', 'excerpt', 'content_md']), 'content_html' => null, 'published_revision_id' => $revision->id];
                    if ($article->working_revision_id === null || (int) $article->working_revision_id === (int) $article->getOriginal('published_revision_id')) {
                        $patch['working_revision_id'] = $revision->id;
                    }
                    if ($article->locale === 'en') {
                        $patch['translated_from_version_hash'] = $revision->translated_from_version_hash;
                    }
                    if ($row['operation'] === 'new_source_pair') {
                        $patch += ['status' => 'published', 'is_public' => true, 'published_at' => $now];
                        if ($article->locale === 'en') {
                            $patch['translation_status'] = Article::TRANSLATION_STATUS_PUBLISHED;
                        }
                    }
                    $article->forceFill($patch)->save();
                    $seo = $this->seo($article, true);
                    if (! $seo) {
                        // The public API already supports a native SEO fallback.
                        // Preserve its qualification while binding reviewed copy.
                        $seo = new ArticleSeoMeta([
                            'org_id' => $article->org_id, 'article_id' => $article->id,
                            'locale' => $article->locale, 'is_indexable' => $article->is_indexable,
                        ]);
                    }
                    $schema = $seo->schema_json ?? [];
                    $metadata = (array) ($schema['editorial_package_v1'] ?? []);
                    $metadata['answer_surface_policy'] = 'editor_supplied';
                    $metadata['answer_surface_visibility'] = 'visible';
                    Arr::set($metadata, 'answer_surface_v1.faq_items', $row['snapshot']['faq_items']);
                    $metadata['iq_article_exact_package_v1'] = $this->publicationBinding($row, $revision, $context);
                    $schema['editorial_package_v1'] = $metadata;
                    $seo->forceFill([
                        'seo_title' => $row['snapshot']['seo_title'], 'og_title' => $row['snapshot']['seo_title'],
                        'seo_description' => $row['snapshot']['seo_description'], 'og_description' => $row['snapshot']['seo_description'], 'schema_json' => $schema,
                    ])->saveQuietly();
                    $this->decisions->recordPublished($article, $revision, $now, 'publish', $reference);
                    $written++;
                }
                $this->assertPublished($rows, $context);
            }, 3);
        }
        if ($written === 0) {
            return $this->result($context, 0, 10, $reference, $this->state($rows, false));
        }
        try {
            $this->cache->flushArticleDiscoverabilityCaches(false);
        } catch (Throwable $error) {
            $this->rollback($context, (string) $reference);
            throw new DomainException('iq_article_cache_failed_rollback_succeeded', previous: $error);
        }

        return $this->result($context, $written, 10, $reference, $this->state($rows, false));
    }

    public function liveQa(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $this->assertPublished($rows, $context);
        foreach ($rows as $row) {
            $response = $this->publicApi->show(Request::create('/api/v0.5/articles/'.$row['identity']['slug'], 'GET', ['org_id' => 0, 'locale' => $row['identity']['locale']]), $row['identity']['slug']);
            $payload = $response->getData(true);
            if ($response->getStatusCode() !== 200 || ($payload['ok'] ?? null) !== true
                || data_get($payload, 'article.content_md') !== $row['snapshot']['content_md']
                || data_get($payload, 'article.locale') !== $row['identity']['locale']) {
                throw new DomainException('iq_article_public_readback_failed');
            }
            $faq = array_map(static fn (array $item): array => Arr::only($item, ['question', 'answer']), (array) data_get($payload, 'answer_surface_v1.faq_blocks', []));
            if ($faq !== $row['snapshot']['faq_items']) {
                throw new DomainException('iq_article_public_faq_readback_failed');
            }
            $seoResponse = $this->publicApi->seo(Request::create('/api/v0.5/articles/'.$row['identity']['slug'].'/seo', 'GET', ['org_id' => 0, 'locale' => $row['identity']['locale']]), $row['identity']['slug']);
            $meta = $seoResponse->getData(true)['meta'] ?? [];
            if ($seoResponse->getStatusCode() !== 200 || ($meta['title'] ?? null) !== $row['snapshot']['seo_title']
                || ($meta['description'] ?? null) !== $row['snapshot']['seo_description']
                || data_get($meta, 'og.title') !== $row['snapshot']['seo_title'] || data_get($meta, 'og.description') !== $row['snapshot']['seo_description']) {
                throw new DomainException('iq_article_public_seo_readback_failed');
            }
        }

        return $this->result($context, 0, 10, null, $this->state($rows, false));
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $rows = $this->rows($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($rows), self::PACK, 'before_publication', $rollbackReference);
        $this->assertExecution((array) $snapshot->meta_json, $context);
        $before = array_column((array) data_get($snapshot->meta_json, 'rows'), null, 'key');
        DB::transaction(function () use ($rows, $context, $before, $rollbackReference): void {
            foreach ($rows as $row) {
                $saved = $before[$this->key($row)] ?? null;
                $article = $this->article($row, true);
                $revision = $article ? $this->revision($article, $context) : null;
                if (! is_array($saved) || ! $article || ! $revision || (int) $article->id !== (int) ($saved['article']['id'] ?? 0)) {
                    throw new DomainException('iq_article_rollback_identity_invalid');
                }
                $this->assertPriorRevisionUnchanged($article, $saved);
                if ((int) $article->published_revision_id === (int) ($saved['article']['published_revision_id'] ?? 0)
                    && $revision->revision_status === ArticleTranslationRevision::STATUS_APPROVED) {
                    $this->assertRestoredCopy($article, $saved);

                    continue;
                }
                if ((int) $article->published_revision_id !== (int) $revision->id || $article->title !== $row['snapshot']['title']
                    || $article->excerpt !== $row['snapshot']['excerpt'] || $article->content_md !== $row['snapshot']['content_md'] || $article->content_html !== null
                    || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                    || $article->source_version_hash !== $article->computeSourceVersionHash()
                    || ($article->locale === 'en' && $article->translated_from_version_hash !== $revision->translated_from_version_hash)) {
                    throw new DomainException('iq_article_rollback_owned_copy_drift');
                }
                $seo = $this->seo($article, true);
                if (! $seo || $seo->seo_title !== $row['snapshot']['seo_title'] || $seo->og_title !== $row['snapshot']['seo_title']
                    || $seo->seo_description !== $row['snapshot']['seo_description'] || $seo->og_description !== $row['snapshot']['seo_description']) {
                    throw new DomainException('iq_article_rollback_owned_seo_drift');
                }
                $schema = $seo->schema_json ?? [];
                $metadata = (array) ($schema['editorial_package_v1'] ?? []);
                if (! $this->sameJson(Arr::get($metadata, 'answer_surface_v1.faq_items'), $row['snapshot']['faq_items'])
                    || Arr::get($metadata, 'iq_article_exact_package_v1.revision_id') !== $revision->id) {
                    throw new DomainException('iq_article_rollback_owned_faq_drift');
                }
                $binding = (array) ($metadata['iq_article_exact_package_v1'] ?? []);
                $this->assertExecution($binding, $context);
                if (($binding['source_commit'] ?? null) !== $context->sourceCommit) {
                    throw new DomainException('iq_article_rollback_publication_owner_mismatch');
                }
                if (($metadata['answer_surface_policy'] ?? null) !== 'editor_supplied'
                    || ($metadata['answer_surface_visibility'] ?? null) !== 'visible'
                    || PromotionContextFactory::canonicalJson($binding) !== PromotionContextFactory::canonicalJson($this->publicationBinding($row, $revision, $context))) {
                    throw new DomainException('iq_article_rollback_owned_faq_drift');
                }
                $originalMetadata = (array) data_get($saved, 'seo.schema_json.editorial_package_v1', []);
                foreach (self::FAQ_PATHS as $path) {
                    if (Arr::has($originalMetadata, $path)) {
                        Arr::set($metadata, $path, Arr::get($originalMetadata, $path));
                    } else {
                        Arr::forget($metadata, $path);
                    }
                }
                if (($metadata['answer_surface_v1'] ?? null) === []) {
                    unset($metadata['answer_surface_v1']);
                }
                if ($metadata === [] && ! array_key_exists('editorial_package_v1', (array) data_get($saved, 'seo.schema_json', []))) {
                    unset($schema['editorial_package_v1']);
                } else {
                    $schema['editorial_package_v1'] = $metadata;
                }
                if ($saved['seo'] === null) {
                    $expectedSchema = ['editorial_package_v1' => [
                        'answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible',
                        'answer_surface_v1' => ['faq_items' => $row['snapshot']['faq_items']],
                        'iq_article_exact_package_v1' => $this->publicationBinding($row, $revision, $context),
                    ]];
                    // Only this exact identity and untouched package-owned row
                    // can be removed; a later operator edit aborts the transaction.
                    if ($seo->org_id !== 0 || (int) $seo->article_id !== (int) $article->id || $seo->locale !== $article->locale
                        || $seo->is_indexable !== (bool) $saved['article']['is_indexable']
                        || $seo->canonical_url !== null || $seo->og_image_url !== null || $seo->robots !== null
                        || ! $this->sameJson($seo->schema_json, $expectedSchema)) {
                        throw new DomainException('iq_article_rollback_created_seo_drift');
                    }
                    $seo->delete();
                } else {
                    $seo->forceFill([...Arr::only($saved['seo'], self::SEO_COPY), 'schema_json' => $schema])->saveQuietly();
                }
                $patch = [...Arr::only($saved['article'], self::ARTICLE_COPY), 'published_revision_id' => $saved['article']['published_revision_id']];
                if ((int) $article->working_revision_id === (int) $revision->id) {
                    $patch['working_revision_id'] = $saved['article']['working_revision_id'];
                }
                if ($row['operation'] === 'new_source_pair') {
                    $patch['is_public'] = false;
                    $patch['published_at'] = $saved['article']['published_at'];
                    if ($article->status === 'published') {
                        $patch['status'] = $saved['article']['status'];
                    }
                    if ($article->locale === 'en') {
                        $patch['translation_status'] = $saved['article']['translation_status'];
                    }
                }
                $article->forceFill($patch);
                $article->forceFill(['source_version_hash' => $article->computeSourceVersionHash()])->saveQuietly();
                foreach ($saved['revisions'] as $old) {
                    if ((int) $old['id'] === (int) $revision->id) {
                        continue;
                    }
                    $prior = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->lockForUpdate()->find($old['id']);
                    if ($prior && (int) $prior->id === (int) ($saved['article']['published_revision_id'] ?? 0)) {
                        if (! in_array($prior->revision_status, [ArticleTranslationRevision::STATUS_STALE, $old['revision_status']], true)) {
                            throw new DomainException('iq_article_rollback_prior_revision_drift');
                        }
                        $prior->forceFill(['revision_status' => $old['revision_status']])->saveQuietly();
                    }
                }
                $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED, 'published_at' => null])->saveQuietly();
                if ($article->is_public && $article->status === 'published' && $article->publishedRevision) {
                    $this->decisions->recordPublished($article, $article->publishedRevision, now(), 'rollback', $rollbackReference);
                } elseif ($this->latestDecision((int) $article->id, $article->locale)?->publication_state !== 'unpublished') {
                    $this->decisions->recordUnpublished($article, now(), $rollbackReference);
                }
            }
        }, 3);
        $this->cache->flushArticleDiscoverabilityCaches(false);
    }

    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $snapshot = $this->snapshot($context);
        if (! $snapshot) {
            return false;
        }
        $this->rollback($context, 'content-release-snapshot:'.$snapshot->id);

        return true;
    }

    private function rows(PromotionContext $context): array
    {
        if (! $this->supports($context->lane, $context->subscope) || $context->expectedRowCount !== 10 || $context->workflowRunAttempt !== 1
            || realpath($context->packageDirectory) !== realpath(base_path(IqPublicArticlePackage::PACKAGE))) {
            throw new DomainException('iq_article_context_invalid');
        }

        return $this->package->read(base_path(), $context->packageSha256);
    }

    private function article(array $row, bool $lock): ?Article
    {
        $query = Article::query()->withoutGlobalScopes()->withTrashed()->where($row['identity']);
        $matches = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($matches->count() > 1 || $matches->first()?->trashed()) {
            throw new DomainException('iq_article_identity_collision');
        }

        return $matches->first();
    }

    private function seo(Article $article, bool $lock): ?ArticleSeoMeta
    {
        $query = ArticleSeoMeta::query()->withoutGlobalScopes()->where(['org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale]);
        $matches = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($matches->count() > 1) {
            throw new DomainException('iq_article_seo_collision');
        }

        return $matches->first();
    }

    private function revision(Article $article, PromotionContext $context): ?ArticleTranslationRevision
    {
        $matches = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)
            ->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $article->locale.':'.$article->slug)->get();
        if ($matches->count() > 1) {
            throw new DomainException('iq_article_exact_revision_collision');
        }

        return $matches->first();
    }

    private function state(array $rows, bool $lock): array
    {
        $state = [];
        foreach ($rows as $row) {
            $article = $this->article($row, $lock);
            $seo = $article ? $this->seo($article, $lock) : null;
            $revisions = $article ? ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)
                ->where(static function ($query) use ($article): void {
                    $query->whereIn('id', array_filter([$article->published_revision_id, $article->working_revision_id]))->orWhere('authority_package_sha256', IqPublicArticlePackage::SHA256);
                })->orderBy('id')->when($lock, static fn ($query) => $query->lockForUpdate())->get()->map->getAttributes()->all() : [];
            $seoAttributes = $seo?->getAttributes();
            if ($seoAttributes) {
                $seoAttributes['schema_json'] = $seo->schema_json;
            }
            $state[$this->key($row)] = ['key' => $this->key($row), 'article' => $article?->getAttributes(), 'seo' => $seoAttributes, 'revisions' => $revisions];
        }

        return $state;
    }

    private function assertPreflight(array $rows, array $state, PromotionContext $context): void
    {
        foreach ($rows as $row) {
            $saved = $state[$this->key($row)];
            $article = $saved['article'];
            if ($row['operation'] !== 'new_source_pair' && is_array($article)) {
                $published = array_values(array_filter($saved['revisions'], static fn (array $revision): bool => (int) $revision['id'] === (int) ($article['published_revision_id'] ?? 0)));
                // Material-decision rollback supports published revisions only.
                // Refuse any other baseline before creating a draft or snapshot.
                if (count($published) !== 1 || $published[0]['revision_status'] !== ArticleTranslationRevision::STATUS_PUBLISHED
                    || (int) $published[0]['org_id'] !== 0 || $published[0]['locale'] !== $row['identity']['locale']) {
                    throw new DomainException('iq_article_rollback_supported_baseline_required');
                }
            }
            if ($row['operation'] === 'new_source_pair') {
                if ($article !== null) {
                    $decision = $this->latestDecision((int) $article['id'], $row['identity']['locale']);
                    if ($decision?->publication_state === 'unpublished' && $decision->operation === 'unpublish') {
                        $rollback = preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', (string) $decision->evidence_ref, $match) === 1
                            ? ContentReleaseSnapshot::query()->find((int) $match[1]) : null;
                        if (! $rollback || $rollback->pack_id !== self::PACK || data_get($rollback->meta_json, 'package_sha256') !== $context->packageSha256
                            || ! collect(data_get($rollback->meta_json, 'rows', []))->contains(static fn (array $saved): bool => ($saved['article']['id'] ?? null) === $article['id'] && (bool) ($saved['article']['is_public'] ?? true) === false)) {
                            throw new DomainException('iq_article_operator_withdrawal_requires_editorial_action');
                        }
                    }
                    $owned = array_values(array_filter($saved['revisions'], static fn (array $revision): bool => ($revision['authority_package_sha256'] ?? null) === $context->packageSha256 && ($revision['authority_asset_key'] ?? null) === $row['identity']['locale'].':'.$row['identity']['slug']));
                    $metadata = count($owned) === 1 ? json_decode($owned[0]['authority_metadata_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR) : [];
                    if (count($owned) !== 1 || ($metadata['created_identity_by_package'] ?? null) !== true
                        || ! $this->sameJson($metadata['snapshot'] ?? null, $row['snapshot']) || $article['title'] !== $row['snapshot']['title'] || $article['content_md'] !== $row['snapshot']['content_md']
                        || $article['is_indexable'] || $article['sitemap_eligible'] || $article['llms_eligible']
                        || ! in_array($article['status'], ['draft', 'published'], true)
                        || ($article['working_revision_id'] !== null && (int) $article['working_revision_id'] !== (int) $owned[0]['id'])
                        || in_array($article['lifecycle_state'] ?? null, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)) {
                        throw new DomainException('iq_article_new_identity_collision');
                    }
                }
            } elseif (! is_array($article) || $article['status'] !== 'published' || ! $article['is_public'] || ! $article['published_revision_id']
                || in_array($article['lifecycle_state'] ?? null, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)) {
                throw new DomainException('iq_article_existing_public_authority_required');
            }
        }
    }

    private function assertPublished(array $rows, PromotionContext $context): void
    {
        $owner = null;
        foreach ($rows as $row) {
            $article = $this->article($row, false);
            $revision = $article ? $this->revision($article, $context) : null;
            $seo = $article ? $this->seo($article, false) : null;
            if ($article && $revision) {
                $this->assertRevision($row, $article, $revision, $context, $this->sourceRow($rows, $row)['snapshot']);
            }
            if (! $article || ! $revision || ! $seo || ! $article->is_public || $article->status !== 'published'
                || (int) $article->published_revision_id !== (int) $revision->id || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                || $article->title !== $row['snapshot']['title'] || $article->excerpt !== $row['snapshot']['excerpt'] || $article->content_md !== $row['snapshot']['content_md']
                || $article->content_html !== null || $article->source_version_hash !== $article->computeSourceVersionHash()
                || ($article->locale === 'en' && $article->translated_from_version_hash !== $revision->translated_from_version_hash)
                || $seo->seo_title !== $row['snapshot']['seo_title'] || $seo->og_title !== $row['snapshot']['seo_title']
                || $seo->seo_description !== $row['snapshot']['seo_description'] || $seo->og_description !== $row['snapshot']['seo_description']
                || data_get($seo->schema_json, 'editorial_package_v1.answer_surface_policy') !== 'editor_supplied'
                || data_get($seo->schema_json, 'editorial_package_v1.answer_surface_visibility') !== 'visible'
                || ! $this->sameJson(data_get($seo->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'), $row['snapshot']['faq_items'])) {
                throw new DomainException('iq_article_published_projection_invalid');
            }
            $binding = (array) data_get($seo->schema_json, 'editorial_package_v1.iq_article_exact_package_v1', []);
            if (count($binding) !== 7 || array_diff_key($binding, $this->publicationBinding($row, $revision, $context)) !== []
                || ($binding['package_sha256'] ?? null) !== $context->packageSha256 || ($binding['revision_id'] ?? null) !== $revision->id
                || ($binding['snapshot_sha256'] ?? null) !== $row['snapshot_sha256']
                || preg_match('/\A[a-f0-9]{40}\z/', (string) ($binding['source_commit'] ?? '')) !== 1
                || preg_match('/\A[1-9][0-9]{0,19}\z/', (string) ($binding['workflow_run_id'] ?? '')) !== 1
                || ($binding['workflow_run_attempt'] ?? null) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($binding['executor_release_sha256'] ?? '')) !== 1) {
                throw new DomainException('iq_article_published_binding_invalid');
            }
            $currentOwner = PromotionContextFactory::canonicalJson(Arr::only($binding, ['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256']));
            if ($owner !== null && $owner !== $currentOwner) {
                throw new DomainException('iq_article_published_binding_invalid');
            }
            $owner = $currentOwner;
        }
        $owners = ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->get()->filter(function (ContentReleaseSnapshot $snapshot) use ($owner, $rows, $context): bool {
            $meta = (array) $snapshot->meta_json;

            return ($meta['package_sha256'] ?? null) === $context->packageSha256
                && ($meta['lane'] ?? null) === $context->lane && ($meta['subscope'] ?? null) === $context->subscope
                && ($meta['phase'] ?? null) === 'before_publication'
                && ($meta['target_fingerprint'] ?? null) === $this->targets($rows)->fingerprint()
                && PromotionContextFactory::canonicalJson(Arr::only($meta, ['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256'])) === $owner;
        });
        if ($owners->count() !== 1) {
            throw new DomainException('iq_article_published_binding_invalid');
        }
        $reference = 'content-release-snapshot:'.$owners->first()->id;
        foreach ($rows as $row) {
            $article = $this->article($row, false);
            $decision = $this->latestDecision((int) $article->id, $article->locale);
            if (! $decision || $decision->publication_state !== 'published' || $decision->operation !== 'publish'
                || $decision->authority_revision !== (string) $article->published_revision_id || $decision->evidence_ref !== $reference) {
                throw new DomainException('iq_article_published_binding_invalid');
            }
        }
    }

    private function latestDecision(int $id, string $locale): ?ContentMaterialDecision
    {
        return ContentMaterialDecision::query()->where('org_id', 0)->where('family', ArticleMaterialDecisionService::FAMILY)
            ->where('locale', $locale)->where('authority_subject_key', 'article:'.$id)->latest('id')->lockForUpdate()->first();
    }

    private function sameJson(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right)
            ? PromotionContextFactory::canonicalJson($left) === PromotionContextFactory::canonicalJson($right)
            : $left === $right;
    }

    private function sourceRow(array $rows, array $row): array
    {
        foreach ($rows as $source) {
            if ($source['page_id'] === $row['page_id'] && $source['identity']['locale'] === 'zh-CN') {
                return $source;
            }
        }
        throw new DomainException('iq_article_source_pair_missing');
    }

    private function assertRevision(array $row, Article $article, ArticleTranslationRevision $revision, PromotionContext $context, array $sourceSnapshot): void
    {
        $source = $this->article(['identity' => [...$row['identity'], 'locale' => 'zh-CN']], false);
        if (! $source || (int) $revision->org_id !== 0 || $revision->locale !== $article->locale
            || (int) $revision->source_article_id !== (int) $source->id || $revision->translation_group_id !== $article->translation_group_id
            || $revision->authority_package_sha256 !== $context->packageSha256 || $revision->authority_asset_key !== $this->key($row)
            || $revision->authority_source_package !== IqPublicArticlePackage::PACKAGE || $revision->authority_source_hash !== $row['snapshot_sha256']
            || ! $this->sameJson(data_get($revision->authority_metadata_json, 'snapshot'), $row['snapshot'])
            || ($article->locale === 'en' && ((int) $article->source_article_id !== (int) $source->id || $article->translation_group_id !== $source->translation_group_id))) {
            throw new DomainException('iq_article_exact_draft_payload_drift');
        }
        $sourceHash = $this->projectedHash($source, $sourceSnapshot);
        if ($revision->source_version_hash !== $sourceHash || ($article->locale === 'en' && $revision->translated_from_version_hash !== $sourceHash)) {
            throw new DomainException('iq_article_exact_draft_payload_drift');
        }
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $field) {
            if ($row['snapshot'][$field] !== $revision->$field) {
                throw new DomainException('iq_article_exact_draft_payload_drift');
            }
        }
    }

    private function projectedHash(Article $source, array $snapshot): string
    {
        return Article::sourceVersionHashFromPayload([
            'locale' => $source->locale, 'title' => $snapshot['title'], 'excerpt' => $snapshot['excerpt'], 'content_md' => $snapshot['content_md'], 'content_html' => null,
            'cover_image_alt' => $source->cover_image_alt, 'related_test_slug' => $source->related_test_slug, 'voice' => $source->voice, 'voice_order' => $source->voice_order,
        ]);
    }

    private function snapshot(PromotionContext $context): ?ContentReleaseSnapshot
    {
        $matches = $this->sourceSnapshots($context)->filter(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'workflow_run_id') === $context->workflowRunId
            && data_get($snapshot->meta_json, 'workflow_run_attempt') === $context->workflowRunAttempt
            && data_get($snapshot->meta_json, 'executor_release_sha256') === $context->executorReleaseSha256);
        if ($matches->count() > 1) {
            throw new DomainException('iq_article_snapshot_collision');
        }
        $snapshot = $matches->first();
        if ($snapshot) {
            $this->assertExecution((array) $snapshot->meta_json, $context);
        }

        return $snapshot;
    }

    private function assertPublicationOwner(array $rows, array $owner): void
    {
        foreach ($rows as $row) {
            $article = $this->article($row, true);
            $seo = $article ? $this->seo($article, true) : null;
            $binding = (array) data_get($seo?->schema_json, 'editorial_package_v1.iq_article_exact_package_v1', []);
            foreach (['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256'] as $field) {
                if (($binding[$field] ?? null) !== ($owner[$field] ?? null)) {
                    throw new DomainException('iq_article_publication_owner_mismatch');
                }
            }
        }
    }

    private function publicationBinding(array $row, ArticleTranslationRevision $revision, PromotionContext $context): array
    {
        return ['package_sha256' => $context->packageSha256, 'revision_id' => $revision->id, 'snapshot_sha256' => $row['snapshot_sha256'], 'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt, 'executor_release_sha256' => $context->executorReleaseSha256];
    }

    private function sourceSnapshots(PromotionContext $context): \Illuminate\Support\Collection
    {
        // Sort only identifiers in SQL: MySQL otherwise includes the large JSON
        // snapshot in its sort buffer and can prevent publication recovery.
        $ids = ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->where('reason', 'content_promotion_before_publication')->orderBy('id')->pluck('id');

        return ContentReleaseSnapshot::query()->whereIn('id', $ids)->get()->sortBy('id')->values()
            ->filter(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'source_commit') === $context->sourceCommit && data_get($snapshot->meta_json, 'package_sha256') === $context->packageSha256);
    }

    private function assertPriorRevisionUnchanged(Article $article, array $saved): void
    {
        $id = (int) ($saved['article']['published_revision_id'] ?? 0);
        if ($id === 0) {
            return;
        }
        $matches = array_values(array_filter($saved['revisions'], static fn (array $revision): bool => (int) $revision['id'] === $id));
        $prior = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->lockForUpdate()->find($id);
        if (count($matches) !== 1 || ! $prior
            || ! in_array($prior->revision_status, [ArticleTranslationRevision::STATUS_STALE, $matches[0]['revision_status']], true)
            || PromotionContextFactory::canonicalJson(Arr::except($prior->getAttributes(), ['revision_status', 'updated_at']))
                !== PromotionContextFactory::canonicalJson(Arr::except($matches[0], ['revision_status', 'updated_at']))) {
            throw new DomainException('iq_article_rollback_prior_revision_drift');
        }
    }

    private function assertRestoredCopy(Article $article, array $saved): void
    {
        foreach (array_diff(self::ARTICLE_COPY, ['source_version_hash']) as $field) {
            if ($article->getAttributes()[$field] !== $saved['article'][$field]) {
                throw new DomainException('iq_article_rollback_restored_copy_drift');
            }
        }
        if ($article->source_version_hash !== $article->computeSourceVersionHash()) {
            throw new DomainException('iq_article_rollback_restored_copy_drift');
        }
        $seo = $this->seo($article, true);
        if ($saved['seo'] === null) {
            if ($seo !== null) {
                throw new DomainException('iq_article_rollback_restored_seo_drift');
            }

            return;
        }
        if (! $seo || PromotionContextFactory::canonicalJson(Arr::only($seo->getAttributes(), self::SEO_COPY)) !== PromotionContextFactory::canonicalJson(Arr::only($saved['seo'], self::SEO_COPY))) {
            throw new DomainException('iq_article_rollback_restored_seo_drift');
        }
        $current = (array) data_get($seo->schema_json, 'editorial_package_v1', []);
        $before = (array) data_get($saved, 'seo.schema_json.editorial_package_v1', []);
        foreach (self::FAQ_PATHS as $path) {
            if (Arr::has($current, $path) !== Arr::has($before, $path) || ! $this->sameJson(Arr::get($current, $path), Arr::get($before, $path))) {
                throw new DomainException('iq_article_rollback_restored_faq_drift');
            }
        }
    }

    private function previous(PromotionContext $context, string $kind): array
    {
        $previous = $this->receipts->readPrevious($kind, $context);
        $this->assertExecution($previous['receipt'], $context);

        return $previous['receipt'];
    }

    private function assertExecution(array $metadata, PromotionContext $context): void
    {
        if (($metadata['workflow_run_id'] ?? null) !== $context->workflowRunId || ($metadata['workflow_run_attempt'] ?? null) !== $context->workflowRunAttempt
            || ($metadata['executor_release_sha256'] ?? null) !== $context->executorReleaseSha256) {
            throw new DomainException('iq_article_execution_identity_mismatch');
        }
    }

    private function assertState(array $receipt, array $state): void
    {
        if (($receipt['target_state_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($state))) {
            throw new DomainException('iq_article_prestate_drift');
        }
    }

    private function targets(array $rows): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_column($rows, 'identity'));
    }

    private function key(array $row): string
    {
        return $row['identity']['locale'].':'.$row['identity']['slug'];
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, array $state, int $created = 0): array
    {
        return [...PromotionAdapterResultFactory::make($context, $written, 10, $published, $reference, array_fill_keys([
            'indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count', 'search_mutation_count', 'deploy_mutation_count',
        ], 0), ['created_count' => $created, 'updated_count' => $written - $created, 'unchanged_count' => 10 - $written]), 'target_state_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($state))];
    }
}
