<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ArticleSeoGateRollout
{
    private const EXECUTE_SAFETY_FLAGS = [
        'no_publish',
        'no_search',
        'no_sitemap_llms_change',
        'no_content_change',
        'no_revalidation',
    ];

    public function __construct(
        private readonly ArticleSeoService $seoService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options): array
    {
        $execute = (bool) ($options['execute'] ?? false);
        $dryRun = ! $execute;
        $errors = [];
        $warnings = [];
        $articleIds = $this->articleIds((string) ($options['article_ids'] ?? ''), $errors);
        $expectedSlugs = $this->expectedSlugs((string) ($options['expected_slugs'] ?? ''), $errors);
        $translationGroupId = trim((string) ($options['translation_group_id'] ?? ''));
        $setTranslationGroupId = trim((string) ($options['set_translation_group_id'] ?? ''));
        $schemaRequested = (bool) ($options['enable_article_schema'] ?? false)
            || (bool) ($options['enable_breadcrumb_schema'] ?? false)
            || (bool) ($options['enable_faq_schema'] ?? false)
            || (bool) ($options['hold_faq_schema'] ?? false);
        $hreflangRequested = (bool) ($options['enable_hreflang'] ?? false)
            || (bool) ($options['no_hreflang_policy'] ?? false);

        if ($translationGroupId === '') {
            $errors[] = $this->issue('translation_group_id', 'translation_group_id_required', 'Expected translation_group_id lock is required.');
        }

        if ((bool) ($options['dry_run'] ?? false) && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }

        if ($articleIds !== [] && $expectedSlugs !== [] && count($expectedSlugs) !== count($articleIds)) {
            $errors[] = $this->issue('expected_slugs', 'expected_slug_count_mismatch', 'expected-slugs must match article-ids count.');
        }

        if ($schemaRequested === false && $hreflangRequested === false && $setTranslationGroupId === '') {
            $errors[] = $this->issue('change_set', 'change_set_required', 'At least one schema, hreflang policy, or translation_group_id change is required.');
        }

        if ((bool) ($options['enable_hreflang'] ?? false) && (bool) ($options['no_hreflang_policy'] ?? false)) {
            $errors[] = $this->issue('hreflang', 'hreflang_policy_conflict', '--enable-hreflang cannot be combined with --no-hreflang-policy.');
        }

        if ($execute) {
            foreach (self::EXECUTE_SAFETY_FLAGS as $flag) {
                if ((bool) ($options[$flag] ?? false) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
        }

        $articles = $this->resolveArticles($articleIds);
        $this->validateLocks($articles, $articleIds, $expectedSlugs, $translationGroupId, $errors);
        $this->validateRequestedGates($articles, $options, $errors, $warnings);

        $before = array_map(fn (Article $article): array => $this->snapshot($article), $articles);
        $after = array_map(fn (Article $article): array => $this->plannedSnapshot($article, $options), $articles);

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleIds, $translationGroupId, $before, $after, $errors, $warnings);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_rollout_article_seo_gates', $articleIds, $translationGroupId, $before, $after, [], $warnings);
        }

        DB::transaction(function () use ($articleIds, $expectedSlugs, $translationGroupId, $options, &$after): void {
            $locked = $this->resolveArticles($articleIds, true);
            $errors = [];
            $warnings = [];
            $this->validateLocks($locked, $articleIds, $expectedSlugs, $translationGroupId, $errors);
            $this->validateRequestedGates($locked, $options, $errors, $warnings);

            if ($errors !== []) {
                throw new \RuntimeException('Article SEO gate rollout lock changed during execute.');
            }

            foreach ($locked as $article) {
                $this->apply($article, $options);
            }

            $fresh = $this->resolveArticles($articleIds);
            $after = array_map(fn (Article $article): array => $this->snapshot($article), $fresh);
            $this->writeAudit($fresh, $options, $translationGroupId);
        });

        return $this->summary(true, false, 'rolled_out_article_seo_gates', $articleIds, $translationGroupId, $before, $after, [], $warnings);
    }

    /**
     * @param  list<int>  $ids
     * @return list<Article>
     */
    private function resolveArticles(array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var list<Article> $articles */
        $articles = $query->get()
            ->sortBy(static fn (Article $article): int => array_search((int) $article->id, $ids, true))
            ->values()
            ->all();

        return $articles;
    }

    /**
     * @param  list<Article>  $articles
     * @param  list<int>  $articleIds
     * @param  list<string>  $expectedSlugs
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateLocks(array $articles, array $articleIds, array $expectedSlugs, string $translationGroupId, array &$errors): void
    {
        $foundIds = array_map(static fn (Article $article): int => (int) $article->id, $articles);
        foreach ($articleIds as $id) {
            if (! in_array($id, $foundIds, true)) {
                $errors[] = $this->issue('article_ids', 'article_not_found', 'Requested article id was not found.', ['article_id' => $id]);
            }
        }

        foreach ($articles as $index => $article) {
            if ((string) $article->translation_group_id !== $translationGroupId) {
                $errors[] = $this->issue('article.'.$article->id.'.translation_group_id', 'translation_group_id_mismatch', 'Article translation_group_id does not match expected lock.', [
                    'article_id' => (int) $article->id,
                    'actual' => (string) $article->translation_group_id,
                    'expected' => $translationGroupId,
                ]);
            }

            $expectedSlug = $expectedSlugs[$index] ?? null;
            if ($expectedSlug !== null && (string) $article->slug !== $expectedSlug) {
                $errors[] = $this->issue('article.'.$article->id.'.slug', 'expected_slug_mismatch', 'Article slug does not match expected identity lock.', [
                    'article_id' => (int) $article->id,
                    'actual' => (string) $article->slug,
                    'expected' => $expectedSlug,
                ]);
            }

            if ((string) $article->status !== 'published' || ! (bool) $article->is_public || (int) $article->published_revision_id <= 0) {
                $errors[] = $this->issue('article.'.$article->id.'.status', 'article_not_published_public', 'SEO gate rollout is limited to published public articles with a published revision.', [
                    'article_id' => (int) $article->id,
                ]);
            }

            if (! $article->seoMeta instanceof ArticleSeoMeta) {
                $errors[] = $this->issue('article.'.$article->id.'.seo_meta', 'article_seo_meta_missing', 'Article SEO meta row is required before SEO gate rollout.', [
                    'article_id' => (int) $article->id,
                ]);
            }
        }
    }

    /**
     * @param  list<Article>  $articles
     * @param  array<string,mixed>  $options
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     */
    private function validateRequestedGates(array $articles, array $options, array &$errors, array &$warnings): void
    {
        foreach ($articles as $article) {
            if (! $article->seoMeta instanceof ArticleSeoMeta) {
                continue;
            }

            $types = $this->jsonLdTypes($this->seoService->generateJsonLd($article, $article->publishedRevision));
            $articleSchemaRequested = (bool) ($options['enable_article_schema'] ?? false)
                || (bool) ($options['enable_breadcrumb_schema'] ?? false);

            if ($articleSchemaRequested && ! in_array('Article', $types, true)) {
                $errors[] = $this->issue('article.'.$article->id.'.jsonld', 'json_ld_article_missing', 'Generated JSON-LD must contain Article before enabling Article schema.', [
                    'article_id' => (int) $article->id,
                    'json_ld_types' => $types,
                ]);
            }

            if ($articleSchemaRequested && ! (bool) ($options['enable_faq_schema'] ?? false) && in_array('FAQPage', $types, true)) {
                $errors[] = $this->issue('article.'.$article->id.'.jsonld', 'json_ld_faq_gate_blocked', 'Generated JSON-LD contains FAQPage while FAQ schema gate is not explicitly enabled.', [
                    'article_id' => (int) $article->id,
                    'json_ld_types' => $types,
                ]);
            }

            if ((bool) ($options['enable_faq_schema'] ?? false) && ! in_array('FAQPage', $types, true)) {
                $warnings[] = $this->issue('article.'.$article->id.'.jsonld', 'faq_schema_enabled_without_faqpage', 'FAQ schema gate was requested but generated JSON-LD has no FAQPage.', [
                    'article_id' => (int) $article->id,
                    'json_ld_types' => $types,
                ]);
            }
        }

        if ((bool) ($options['enable_hreflang'] ?? false)) {
            $this->validateReciprocalHreflang($articles, $errors);
        }
    }

    /**
     * @param  list<Article>  $articles
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateReciprocalHreflang(array $articles, array &$errors): void
    {
        foreach ($articles as $article) {
            $payload = $this->seoService->buildSeoPayload($article, $article->publishedRevision);
            $alternates = is_array($payload['alternates'] ?? null) ? $payload['alternates'] : [];
            $canonical = (string) ($payload['canonical'] ?? '');

            if ($canonical === '' || ! isset($alternates['en'], $alternates['zh-CN'])) {
                $errors[] = $this->issue('article.'.$article->id.'.hreflang', 'hreflang_missing_counterpart', 'Hreflang enable requires both en and zh-CN public indexable alternates.', [
                    'article_id' => (int) $article->id,
                    'alternates' => $alternates,
                ]);

                continue;
            }

            foreach (['en', 'zh-CN'] as $locale) {
                $sibling = Article::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', (int) $article->org_id)
                    ->where('translation_group_id', (string) $article->translation_group_id)
                    ->where('locale', $locale)
                    ->publiclyIndexable()
                    ->first();

                if (! $sibling instanceof Article) {
                    $errors[] = $this->issue('article.'.$article->id.'.hreflang', 'hreflang_counterpart_not_found', 'Hreflang counterpart article was not found.', [
                        'article_id' => (int) $article->id,
                        'locale' => $locale,
                    ]);

                    continue;
                }

                $siblingPayload = $this->seoService->buildSeoPayload($sibling, $sibling->publishedRevision);
                $siblingAlternates = is_array($siblingPayload['alternates'] ?? null) ? $siblingPayload['alternates'] : [];
                if (($siblingAlternates[(string) $article->locale] ?? null) !== $canonical) {
                    $errors[] = $this->issue('article.'.$article->id.'.hreflang', 'hreflang_reciprocal_mismatch', 'Hreflang counterpart does not reciprocate this article canonical.', [
                        'article_id' => (int) $article->id,
                        'counterpart_article_id' => (int) $sibling->id,
                        'counterpart_locale' => $locale,
                        'expected' => $canonical,
                        'actual' => $siblingAlternates[(string) $article->locale] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function apply(Article $article, array $options): void
    {
        $setTranslationGroupId = trim((string) ($options['set_translation_group_id'] ?? ''));
        if ($setTranslationGroupId !== '') {
            $article->forceFill(['translation_group_id' => $setTranslationGroupId])->saveQuietly();
        }

        /** @var ArticleSeoMeta $seoMeta */
        $seoMeta = $article->seoMeta;
        $schema = is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
        $package = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];

        if ((bool) ($options['enable_article_schema'] ?? false)) {
            $package['article_schema_enabled'] = true;
        }
        if ((bool) ($options['enable_breadcrumb_schema'] ?? false)) {
            $package['breadcrumb_schema_enabled'] = true;
        }
        if ((bool) ($options['enable_faq_schema'] ?? false)) {
            $package['faq_schema_enabled'] = true;
        } elseif ((bool) ($options['hold_faq_schema'] ?? false)) {
            $package['faq_schema_enabled'] = false;
        }

        if ((bool) ($options['enable_hreflang'] ?? false)) {
            $package['hreflang_gate_v1'] = [
                'enabled' => true,
                'policy' => 'reciprocal_counterparts_verified',
                'verified_by' => 'articles:seo-gate-rollout',
                'verified_at' => now()->toIso8601String(),
            ];
        } elseif ((bool) ($options['no_hreflang_policy'] ?? false)) {
            $package['hreflang_gate_v1'] = [
                'enabled' => false,
                'policy' => 'no_hreflang',
                'reason' => trim((string) ($options['hreflang_policy_reason'] ?? 'no_verified_reciprocal_counterpart')),
                'recorded_by' => 'articles:seo-gate-rollout',
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        $schema['editorial_package_v1'] = $package;
        $seoMeta->forceFill(['schema_json' => $schema])->saveQuietly();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $article->loadMissing([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);

        $seoMeta = $article->seoMeta;
        $schema = $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json) ? $seoMeta->schema_json : null;
        $package = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => (int) $article->published_revision_id,
            'seo_meta_exists' => $seoMeta instanceof ArticleSeoMeta,
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) : null,
            'schema_gates' => [
                'article_schema_enabled' => $package['article_schema_enabled'] ?? null,
                'breadcrumb_schema_enabled' => $package['breadcrumb_schema_enabled'] ?? null,
                'faq_schema_enabled' => $package['faq_schema_enabled'] ?? null,
                'hreflang_gate_v1' => $package['hreflang_gate_v1'] ?? null,
            ],
            'json_ld_types' => $seoMeta instanceof ArticleSeoMeta
                ? $this->jsonLdTypes($this->seoService->generateJsonLd($article, $article->publishedRevision))
                : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function plannedSnapshot(Article $article, array $options): array
    {
        $snapshot = $this->snapshot($article);
        $setTranslationGroupId = trim((string) ($options['set_translation_group_id'] ?? ''));
        if ($setTranslationGroupId !== '') {
            $snapshot['translation_group_id'] = $setTranslationGroupId;
        }

        if ((bool) ($options['enable_article_schema'] ?? false)) {
            $snapshot['schema_gates']['article_schema_enabled'] = true;
        }
        if ((bool) ($options['enable_breadcrumb_schema'] ?? false)) {
            $snapshot['schema_gates']['breadcrumb_schema_enabled'] = true;
        }
        if ((bool) ($options['enable_faq_schema'] ?? false)) {
            $snapshot['schema_gates']['faq_schema_enabled'] = true;
        } elseif ((bool) ($options['hold_faq_schema'] ?? false)) {
            $snapshot['schema_gates']['faq_schema_enabled'] = false;
        }
        if ((bool) ($options['enable_hreflang'] ?? false)) {
            $snapshot['schema_gates']['hreflang_gate_v1'] = [
                'enabled' => true,
                'policy' => 'reciprocal_counterparts_verified',
            ];
        } elseif ((bool) ($options['no_hreflang_policy'] ?? false)) {
            $snapshot['schema_gates']['hreflang_gate_v1'] = [
                'enabled' => false,
                'policy' => 'no_hreflang',
                'reason' => trim((string) ($options['hreflang_policy_reason'] ?? 'no_verified_reciprocal_counterpart')),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  list<Article>  $articles
     * @param  array<string,mixed>  $options
     */
    private function writeAudit(array $articles, array $options, string $translationGroupId): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        foreach ($articles as $article) {
            AuditLog::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $article->org_id,
                'actor_admin_id' => null,
                'action' => 'articles_seo_gate_rollout',
                'target_type' => 'article',
                'target_id' => (string) $article->id,
                'meta_json' => [
                    'command' => 'articles:seo-gate-rollout',
                    'article_id' => (int) $article->id,
                    'slug' => (string) $article->slug,
                    'locale' => (string) $article->locale,
                    'translation_group_id_lock' => $translationGroupId,
                    'requested_options' => $this->auditOptions($options),
                    'no_publish' => true,
                    'no_search' => true,
                    'no_sitemap_llms_change' => true,
                    'no_content_change' => true,
                    'no_revalidation' => true,
                ],
                'ip' => null,
                'user_agent' => 'artisan',
                'request_id' => '',
                'reason' => 'controlled_article_seo_gate_rollout',
                'result' => 'success',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function auditOptions(array $options): array
    {
        return [
            'enable_article_schema' => (bool) ($options['enable_article_schema'] ?? false),
            'enable_breadcrumb_schema' => (bool) ($options['enable_breadcrumb_schema'] ?? false),
            'enable_faq_schema' => (bool) ($options['enable_faq_schema'] ?? false),
            'hold_faq_schema' => (bool) ($options['hold_faq_schema'] ?? false),
            'enable_hreflang' => (bool) ($options['enable_hreflang'] ?? false),
            'no_hreflang_policy' => (bool) ($options['no_hreflang_policy'] ?? false),
            'set_translation_group_id_present' => trim((string) ($options['set_translation_group_id'] ?? '')) !== '',
        ];
    }

    /**
     * @param  array<string,mixed>  $jsonLd
     * @return list<string>
     */
    private function jsonLdTypes(array $jsonLd): array
    {
        $types = [];
        $walk = function (mixed $value) use (&$walk, &$types): void {
            if (! is_array($value)) {
                return;
            }

            $type = $value['@type'] ?? null;
            if (is_string($type) && trim($type) !== '') {
                $types[] = trim($type);
            } elseif (is_array($type)) {
                foreach ($type as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $types[] = trim($item);
                    }
                }
            }

            foreach ($value as $nested) {
                $walk($nested);
            }
        };
        $walk($jsonLd);

        return array_values(array_unique($types));
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<int>
     */
    private function articleIds(string $raw, array &$errors): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (string $value): int => is_numeric(trim($value)) ? (int) trim($value) : 0,
            explode(',', $raw)
        ))));

        if ($ids === []) {
            $errors[] = $this->issue('article_ids', 'article_ids_required', 'At least one article id is required.');
        }

        return $ids;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<string>
     */
    private function expectedSlugs(string $raw, array &$errors): array
    {
        $slugs = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw)
        ), static fn (string $value): bool => $value !== ''));

        if ($slugs === []) {
            $errors[] = $this->issue('expected_slugs', 'expected_slugs_required', 'Expected slug identity lock is required.');
        }

        return $slugs;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return array_filter([
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @param  list<int>  $articleIds
     * @param  list<array<string,mixed>>  $before
     * @param  list<array<string,mixed>>  $after
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(
        bool $ok,
        bool $dryRun,
        string $action,
        array $articleIds,
        string $translationGroupId,
        array $before,
        array $after,
        array $errors,
        array $warnings
    ): array {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && $dryRun,
            'article_ids' => $articleIds,
            'translation_group_id_lock' => $translationGroupId,
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
