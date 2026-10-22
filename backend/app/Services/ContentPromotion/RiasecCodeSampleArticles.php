<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\Cms\ArticleSeoGateRollout;
use DomainException;
use Illuminate\Support\Facades\DB;

/** The six reviewed Article identities in the existing transaction. @review-surface article */
final class RiasecCodeSampleArticles
{
    public const SLUGS = ['ric-holland-code-career-exploration', 'iar-holland-code-career-exploration', 'sae-holland-code-career-exploration'];

    public static function package(string $bytes, string $review, string $sha): array
    {
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($review, true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== 'fermatmind.riasec_code_samples.v1' || count($rows) !== 6
            || ($proof['schema'] ?? null) !== 'fermatmind.riasec_code_samples.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== 6) {
            throw new DomainException('public_content_53_riasec_code_samples_review_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $identity = $row['identity'] ?? [];
            $code = strtoupper(substr($identity['slug'] ?? '', 0, 3));
            $locale = $identity['locale'] ?? '';
            $id = 'RIASEC-'.$code.'-'.$locale;
            $fields = array_keys($row);
            sort($fields);
            $copy = $row['snapshot'] ?? [];
            $copyFields = array_keys($copy);
            sort($copyFields);
            $seo = $copy['seo_text'] ?? [];
            $seoFields = array_keys($seo);
            sort($seoFields);
            if ($fields !== ['before', 'identity', 'kind', 'new_article', 'page_id', 'snapshot', 'source_identity', 'staging_before']
                || ($row['kind'] ?? null) !== 'article' || ($row['new_article'] ?? null) !== true
                || array_keys($identity) !== ['org_id', 'slug', 'locale'] || $identity['org_id'] !== 0
                || ! in_array($identity['slug'], self::SLUGS, true) || ! in_array($locale, ['zh-CN', 'en'], true)
                || ($row['page_id'] ?? null) !== $id || isset($seen[$id]) || ($proof['pages'][$id] ?? null) !== 'PASS'
                || $row['before'] !== [] || $row['staging_before'] !== []
                || ($row['source_identity'] ?? null) !== self::sourceIdentity($identity['slug'])
                || $copyFields !== ['content_md', 'excerpt', 'seo_text', 'title']
                || $seoFields !== ['og_description', 'og_title', 'seo_description', 'seo_title']
                || array_filter(array_merge(array_diff_key($copy, ['seo_text' => true]), $seo), static fn ($value): bool => ! is_string($value) || trim($value) === '')
                || preg_match('/^# /m', $copy['content_md']) === 1) {
                throw new DomainException('public_content_53_riasec_code_samples_identity_or_fields_invalid');
            }
            $seen[$id] = true;
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    public static function matchesContext(PromotionContext $context): bool
    {
        if ($context->lane !== 'W3' || $context->subscope !== 'PUBLIC-CONTENT-53' || $context->expectedRowCount !== 6) {
            return false;
        }
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];

        return count($rows) === 6 && count(array_filter($rows, static fn ($row): bool => ($row['new_article'] ?? false) === true)) === 6;
    }

    public static function sourceIdentity(string $slug): array
    {
        // Stable bilingual identity, scoped only to these three new Articles.
        $groups = ['ric' => '9fb10886-03c0-42a1-8699-c1a34029fd01', 'iar' => '9fb10886-03c0-42a1-8699-c1a34029fd02', 'sae' => '9fb10886-03c0-42a1-8699-c1a34029fd03'];

        return ['translation_group_id' => $groups[substr($slug, 0, 3)], 'source_locale' => 'zh-CN'];
    }

    public function create(array $row, PromotionContext $context): bool
    {
        $existing = Article::query()->withoutGlobalScopes()->where($row['identity'])->lockForUpdate()->first();
        if (DB::transactionLevel() < 1 || ($existing && ! self::isRestored($existing->getRawOriginal(), $context->packageSha256))) {
            throw new DomainException('public_content_53_riasec_code_sample_identity_collision');
        }
        $copy = $row['snapshot'];
        $seo = $copy['seo_text'];
        $english = $row['identity']['locale'] === 'en';
        $source = $english ? Article::query()->withoutGlobalScopes()->where(array_replace($row['identity'], ['locale' => 'zh-CN']))->lockForUpdate()->firstOrFail() : null;
        $article = $existing ?? new Article;
        $article->forceFill($row['identity'] + $row['source_identity'] + [
            'title' => $copy['title'], 'excerpt' => $copy['excerpt'], 'content_md' => $copy['content_md'],
            'author_name' => 'FermatMind', 'related_test_slug' => 'holland-career-interest-test-riasec', 'status' => 'published', 'is_public' => true,
            'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true,
            'published_at' => now(), 'lifecycle_state' => 'active',
            'translation_status' => $english ? Article::TRANSLATION_STATUS_PUBLISHED : Article::TRANSLATION_STATUS_SOURCE,
            'source_article_id' => $source?->id, 'translated_from_article_id' => $source?->id,
        ])->save();
        $sourceHash = $source?->source_version_hash ?? $article->computeSourceVersionHash();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source?->id ?? $article->id,
            'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale, 'source_locale' => 'zh-CN',
            'revision_number' => (int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number') + 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $sourceHash, 'translated_from_version_hash' => $english ? $sourceHash : null,
            'title' => $copy['title'], 'excerpt' => $copy['excerpt'], 'content_md' => $copy['content_md'],
            'seo_title' => $seo['seo_title'], 'seo_description' => $seo['seo_description'],
            'authority_asset_key' => $row['page_id'].':'.$context->sourceCommit,
            'authority_source_package' => PublicContent53Package::PACKAGE,
            'authority_source_hash' => app(PublicContent53State::class)->hash($copy), 'authority_package_sha256' => $context->packageSha256,
            'authority_metadata_json' => ['source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'approval_kind' => 'verified_automated_exact_package'],
            'approved_at' => now(), 'published_at' => now(),
        ]);
        $article->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $revision->id,
            'translated_from_version_hash' => $english ? $sourceHash : null])->saveQuietly();
        ArticleSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(['article_id' => $article->id], ['org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale,
            'canonical_url' => '/'.($english ? 'en' : 'zh').'/articles/'.$article->slug,
            'robots' => 'index,follow', 'is_indexable' => true, ...$seo]);
        $decision = app(ArticleMaterialDecisionService::class)->recordPublished($article, $revision, now());
        event(new \App\Events\PublicAuthorityChanged('article', (string) $article->id, $article->locale, $decision->material_fingerprint, 'publish'));

        return $existing === null;
    }

    public function enableGates(array $rows, array $written): void
    {
        $groups = [];
        foreach ($rows as $row) {
            if (($row['new_article'] ?? false) && isset($written[$row['page_id']])) {
                $article = Article::query()->withoutGlobalScopes()->where($row['identity'])->firstOrFail();
                self::assertNoHold($article->seoMeta);
                $groups[$row['identity']['slug']][] = $article;
            }
        }
        foreach ($groups as $slug => $articles) {
            $result = app(ArticleSeoGateRollout::class)->run([
                'article_ids' => implode(',', array_map(static fn ($a) => $a->id, $articles)), 'expected_slugs' => implode(',', array_fill(0, count($articles), $slug)),
                'translation_group_id' => self::sourceIdentity($slug)['translation_group_id'],
                'enable_hreflang' => true, 'enable_article_schema' => true, 'enable_breadcrumb_schema' => true,
                'execute' => true, 'no_publish' => true, 'no_search' => true, 'no_sitemap_llms_change' => true,
                'no_content_change' => true, 'no_revalidation' => true,
            ], false);
            if (($result['ok'] ?? false) !== true) {
                throw new DomainException('public_content_53_riasec_code_sample_seo_gate_invalid');
            }
        }
    }

    /** Keep authored rows and immutable revisions private on rollback; delete no content. */
    public function unpublish(array $saved, PromotionContext $context): void
    {
        $article = Article::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
        $revision = $article->publishedRevision;
        if (! $revision || $revision->authority_package_sha256 !== $context->packageSha256) {
            throw new DomainException('public_content_53_riasec_code_sample_rollback_identity');
        }
        $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED])->saveQuietly();
        $article->forceFill(['status' => 'draft', 'is_public' => false, 'is_indexable' => false, 'sitemap_eligible' => false,
            'llms_eligible' => false, 'published_revision_id' => null])->saveQuietly();
        $article->seoMeta->forceFill(['is_indexable' => false, 'robots' => 'noindex,follow'])->saveQuietly();
        $decision = app(ArticleMaterialDecisionService::class)->recordUnpublished($article, now());
        event(new \App\Events\PublicAuthorityChanged('article', (string) $article->id, $article->locale, $decision->material_fingerprint, 'unpublish'));
        if (! self::isRestored($article->getRawOriginal(), $context->packageSha256)) {
            throw new DomainException('public_content_53_riasec_code_sample_rollback_readback');
        }
    }

    public static function assertNoHold(?ArticleSeoMeta $seo): void
    {
        $schema = $seo?->schema_json ?? [];
        if (! $seo || data_get($schema, 'editorial_package_v1.schema_hold') || data_get($schema, 'editorial_package_v1.hreflang_hold')) {
            throw new DomainException('public_content_53_riasec_code_sample_operator_hold');
        }
    }

    public static function isRestored(array $values, string $sha): bool
    {
        if (($values['author_name'] ?? null) !== 'FermatMind' || ($values['related_test_slug'] ?? null) !== 'holland-career-interest-test-riasec'
            || ! in_array($values['content_html'] ?? null, [null, ''], true)
            || ($values['translation_status'] ?? null) !== (($values['locale'] ?? '') === 'en' ? Article::TRANSLATION_STATUS_PUBLISHED : Article::TRANSLATION_STATUS_SOURCE)
            || ($values['deleted_at'] ?? null) !== null || ($values['scheduled_at'] ?? null) !== null
            || ! in_array($values['lifecycle_state'] ?? null, [null, 'active'], true)
            || ($values['status'] ?? '') !== 'draft' || ($values['published_revision_id'] ?? null) !== null
            || ($values['is_public'] ?? true) || ($values['is_indexable'] ?? true)
            || ($values['sitemap_eligible'] ?? true) || ($values['llms_eligible'] ?? true)) {
            return false;
        }
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->find($values['working_revision_id'] ?? 0);
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $values['id'])->first();
        $schema = $seo?->schema_json ?? [];
        $package = $schema['editorial_package_v1'] ?? [];
        $gate = $package['hreflang_gate_v1'] ?? [];
        $allowedSchema = ['article_schema_enabled' => true, 'breadcrumb_schema_enabled' => true,
            'hreflang_gate_v1' => ['enabled' => true, 'policy' => 'reciprocal_counterparts_verified',
                'verified_by' => 'articles:seo-gate-rollout', 'verified_at' => $gate['verified_at'] ?? null]];
        if (! $seo || $seo->org_id !== 0 || $seo->locale !== $values['locale'] || $seo->og_image_url !== null
            || $seo->is_indexable || $seo->robots !== 'noindex,follow'
            || $seo->canonical_url !== '/'.($values['locale'] === 'en' ? 'en' : 'zh').'/articles/'.$values['slug']
            || ! is_string($gate['verified_at'] ?? null)
            || app(PublicContent53State::class)->hash($schema) !== app(PublicContent53State::class)->hash(['editorial_package_v1' => $allowedSchema])) {
            return false;
        }
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        $row = collect($rows)->first(static fn ($row): bool => $row['identity']['slug'] === $values['slug'] && $row['identity']['locale'] === $values['locale']);
        if (! $row || app(PublicContent53State::class)->hash(array_intersect_key($seo->getAttributes(), $row['snapshot']['seo_text']))
            !== app(PublicContent53State::class)->hash($row['snapshot']['seo_text'])) {
            return false;
        }

        return $revision && in_array($values['slug'] ?? '', self::SLUGS, true)
            && ($values['translation_group_id'] ?? '') === self::sourceIdentity($values['slug'])['translation_group_id']
            && ($values['source_locale'] ?? '') === 'zh-CN'
            && $revision->title === $values['title'] && $revision->excerpt === $values['excerpt'] && $revision->content_md === $values['content_md']
            && $revision->article_id === $values['id'] && $revision->revision_status === ArticleTranslationRevision::STATUS_APPROVED
            && $revision->authority_package_sha256 === $sha && $revision->authority_source_package === PublicContent53Package::PACKAGE
            && str_starts_with((string) $revision->authority_asset_key, 'RIASEC-');
    }
}
