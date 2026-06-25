<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class SeoOpsP0CtrArticleCmsUpdateWriterCommand extends Command
{
    private const INPUT_SCHEMA = 'seo-ops-p0-ctr-repair-dry-run.v1';

    private const OUTPUT_SCHEMA = 'seo-ops-p0-ctr-article-cms-update-writer.v1';

    private const EXPECTED_ARTICLE_COUNT = 3;

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'Cookie:',
        'Set-Cookie:',
        'content_html',
        'content_md',
        'cms_draft_body',
    ];

    protected $signature = 'seo-ops:p0-ctr-article-cms-update-writer
        {--dry-run-evidence= : Path to seo-ops-p0-ctr-repair-dry-run.v1 evidence JSON}
        {--confirm-dry-run-evidence-sha256= : Expected SHA-256 of the dry-run evidence}
        {--artifact-dir= : Directory for writer evidence output}
        {--execute : Apply the bounded article CMS update}
        {--confirm-write= : Exact Gate B write confirmation phrase}
        {--json : Emit JSON summary}';

    protected $description = 'Controlled Gate B writer for P0 CTR article CMS updates from verified dry-run evidence; defaults to dry-run.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $loaded = $this->loadDryRunEvidence();
        if (($loaded['ok'] ?? false) !== true) {
            $summary = $this->failureSummary((array) ($loaded['issues'] ?? ['dry_run_evidence_unreadable']), [
                'dry_run_evidence' => $loaded['dry_run_evidence'] ?? null,
            ]);
            $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

            return $this->finish($summary);
        }

        $dryRunEvidence = (array) $loaded['dry_run_evidence_payload'];
        $dryRunSha = (string) $loaded['dry_run_evidence_sha256'];
        $articlePlans = array_values((array) data_get($dryRunEvidence, 'article_plans', []));
        $issues = $this->validateDryRunEvidence($dryRunEvidence, $articlePlans);
        $plans = array_map(fn (array $plan): array => $this->buildArticleWritePlan($plan), $articlePlans);

        foreach ($plans as $plan) {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        $issues = array_values(array_unique($issues));
        $requiredPhrase = $this->requiredConfirmationPhrase($dryRunSha, $plans);
        $execute = (bool) $this->option('execute');

        if ($execute && ! hash_equals($requiredPhrase, trim((string) $this->option('confirm-write')))) {
            $issues[] = 'confirm_write_phrase_mismatch';
        }

        $ok = $issues === [];
        $writeResults = [];
        if ($ok && $execute) {
            $writeResults = $this->executePlans($plans, $dryRunSha);
        }

        $status = $ok ? ($execute ? 'success' : 'planned') : 'blocked';
        $summary = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:p0-ctr-article-cms-update-writer',
            'ok' => $ok,
            'status' => $status,
            'dry_run' => ! $execute,
            'execute' => $execute,
            'generated_at' => now()->utc()->toIso8601String(),
            'dry_run_evidence' => [
                'path' => (string) $loaded['dry_run_evidence_path'],
                'sha256' => $dryRunSha,
                'schema_version' => (string) ($dryRunEvidence['schema_version'] ?? ''),
            ],
            'planned_write_count' => [
                'article_cms_updates' => count(array_filter($plans, static fn (array $plan): bool => ($plan['ready'] ?? false) === true)),
                'landing_surfaces' => 0,
                'total' => count(array_filter($plans, static fn (array $plan): bool => ($plan['ready'] ?? false) === true)),
            ],
            'target_lock' => [
                'targets' => array_values(array_map(static fn (array $plan): string => (string) ($plan['target'] ?? ''), $plans)),
                'expected_count' => self::EXPECTED_ARTICLE_COUNT,
            ],
            'required_confirmation_phrase' => $requiredPhrase,
            'article_update_plans' => $plans,
            'write_results' => $writeResults,
            'protected_diff_summary' => [
                'slug' => 'no_change',
                'canonical' => 'no_change',
                'locale' => 'no_change',
                'article_id' => 'resolved_no_change',
                'publication' => 'no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
            ],
            'side_effects' => [
                'database_write' => $execute && $ok,
                'cms_article_update' => $execute && $ok,
                'landing_surface_write' => false,
                'cms_publish' => false,
                'url_truth_write' => false,
                'schema_enable' => false,
                'hreflang_enable' => false,
                'sitemap_write' => false,
                'llms_write' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
                'baidu_submit' => false,
                'gsc_request_indexing' => false,
                'scheduler_start' => false,
                'queue_worker_start' => false,
                'external_api_call' => false,
                'revalidation' => false,
                'deploy' => false,
            ],
            'issues' => $issues,
        ];
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDryRunEvidence(): array
    {
        $path = $this->readablePath((string) $this->option('dry-run-evidence'));
        if ($path === null) {
            return ['ok' => false, 'issues' => ['dry_run_evidence_unreadable']];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return [
                'ok' => false,
                'dry_run_evidence' => ['path' => $path],
                'issues' => ['forbidden_input_field_present'],
                'forbidden_matches' => $forbidden,
            ];
        }

        $actualSha = hash('sha256', $raw);
        $expectedSha = trim((string) $this->option('confirm-dry-run-evidence-sha256'));
        if ($expectedSha === '' || ! hash_equals($expectedSha, $actualSha)) {
            return [
                'ok' => false,
                'dry_run_evidence' => [
                    'path' => $path,
                    'sha256' => $actualSha,
                    'expected_sha256' => $expectedSha,
                ],
                'issues' => ['dry_run_evidence_sha_mismatch'],
            ];
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'dry_run_evidence' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['dry_run_evidence_json_invalid'],
            ];
        }

        if (! is_array($payload)) {
            return [
                'ok' => false,
                'dry_run_evidence' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['dry_run_evidence_json_not_object'],
            ];
        }

        return [
            'ok' => true,
            'dry_run_evidence_payload' => $payload,
            'dry_run_evidence_path' => $path,
            'dry_run_evidence_sha256' => $actualSha,
        ];
    }

    /**
     * @param  array<string, mixed>  $dryRunEvidence
     * @param  array<int, mixed>  $articlePlans
     * @return array<int, string>
     */
    private function validateDryRunEvidence(array $dryRunEvidence, array $articlePlans): array
    {
        $issues = [];
        if (($dryRunEvidence['schema_version'] ?? null) !== self::INPUT_SCHEMA) {
            $issues[] = 'dry_run_evidence_schema_invalid';
        }
        if (($dryRunEvidence['ok'] ?? null) !== true || ($dryRunEvidence['status'] ?? null) !== 'planned') {
            $issues[] = 'dry_run_evidence_not_planned';
        }
        if ((bool) ($dryRunEvidence['dry_run'] ?? false) !== true || (bool) ($dryRunEvidence['execute'] ?? true) !== false) {
            $issues[] = 'dry_run_evidence_mode_invalid';
        }
        if (count($articlePlans) !== self::EXPECTED_ARTICLE_COUNT) {
            $issues[] = 'article_plan_count_unexpected';
        }
        if ((int) data_get($dryRunEvidence, 'planned_write_count.article_cms_updates') !== self::EXPECTED_ARTICLE_COUNT) {
            $issues[] = 'planned_article_write_count_unexpected';
        }
        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'url_truth_write',
            'schema_enable',
            'hreflang_enable',
            'sitemap_write',
            'llms_write',
            'search_channel_enqueue',
            'indexnow_submit',
            'baidu_submit',
            'gsc_request_indexing',
            'revalidation',
            'deploy',
        ] as $field) {
            if ((bool) data_get($dryRunEvidence, 'negative_guarantees.'.$field) !== false) {
                $issues[] = 'dry_run_negative_guarantee_invalid:'.$field;
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $inputPlan
     * @return array<string, mixed>
     */
    private function buildArticleWritePlan(array $inputPlan): array
    {
        $target = trim((string) ($inputPlan['target'] ?? ''));
        $slug = trim((string) ($inputPlan['slug'] ?? ''));
        $locale = trim((string) ($inputPlan['resolved_locale'] ?? $inputPlan['input_locale'] ?? ''));
        $safePath = trim((string) ($inputPlan['safe_path'] ?? ''));
        $fields = (array) ($inputPlan['planned_fields'] ?? []);
        $issues = [];

        if (($inputPlan['resolved'] ?? null) !== true) {
            $issues[] = 'article_plan_unresolved:'.$target;
        }
        if ((array) ($inputPlan['issues'] ?? []) !== []) {
            $issues[] = 'article_plan_has_existing_issues:'.$target;
        }

        $articleId = $this->articleIdFromTarget($target);
        $article = $articleId !== null
            ? Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find($articleId)
            : null;

        if (! $article instanceof Article) {
            $issues[] = 'article_not_found:'.$target;
        }

        $seoTitle = $this->boundedString($fields['seo_title'] ?? null, 60);
        $seoDescription = $this->boundedString($fields['seo_description'] ?? null, 160);
        $primaryCtaLabel = $this->boundedString($fields['primary_cta_label'] ?? null, 96);
        $primaryCtaPath = $this->safePublicTestPath($fields['primary_cta_path'] ?? null);
        $internalLinkTargets = $this->safeInternalPaths($fields['internal_link_targets'] ?? []);

        if ($seoTitle === null) {
            $issues[] = 'seo_title_missing_or_too_long:'.$target;
        }
        if ($seoDescription === null) {
            $issues[] = 'seo_description_missing_or_too_long:'.$target;
        }
        if ($primaryCtaLabel === null) {
            $issues[] = 'primary_cta_label_missing_or_too_long:'.$target;
        }
        if ($primaryCtaPath === null) {
            $issues[] = 'primary_cta_path_invalid:'.$target;
        }
        if (($fields['internal_link_targets'] ?? []) !== [] && $internalLinkTargets === []) {
            $issues[] = 'internal_link_targets_invalid:'.$target;
        }

        $protected = $article instanceof Article ? $this->currentProtectedSnapshot($article) : null;
        if ($article instanceof Article) {
            $this->validateCurrentArticle($article, $slug, $locale, $safePath, $issues);
        }

        return [
            'target' => $target,
            'article_id' => $article instanceof Article ? (int) $article->id : $articleId,
            'slug' => $slug,
            'locale' => $locale,
            'safe_path' => $safePath,
            'ready' => $issues === [],
            'current_protected' => $protected,
            'planned_fields' => [
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
                'first_screen_summary_direction' => $this->boundedString($fields['first_screen_summary_direction'] ?? null, 240),
                'primary_cta_label' => $primaryCtaLabel,
                'primary_cta_path' => $primaryCtaPath,
                'internal_link_targets' => $internalLinkTargets,
                'claim_boundary_note' => $this->boundedString($fields['claim_boundary_note'] ?? null, 240),
            ],
            'protected_diff' => [
                'slug' => 'no_change',
                'canonical' => 'no_change',
                'locale' => 'no_change',
                'article_id' => $article instanceof Article ? 'resolved_no_change' : 'unresolved',
                'publication' => 'no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
            ],
            'runtime_projection_plan' => [
                'published_revision_seo_title' => true,
                'published_revision_seo_description' => true,
                'article_seo_meta_title_description' => true,
                'landing_surface_cta_bundle_from_editorial_metadata' => true,
                'answer_surface_next_steps_from_editorial_metadata' => true,
                'body_internal_link_rewrite' => false,
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    private function executePlans(array $plans, string $dryRunSha): array
    {
        return DB::transaction(function () use ($plans, $dryRunSha): array {
            $results = [];
            foreach ($plans as $plan) {
                /** @var Article $article */
                $article = Article::query()
                    ->withoutGlobalScopes()
                    ->with([
                        'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                        'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    ])
                    ->whereKey((int) $plan['article_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $before = $this->currentProtectedSnapshot($article);
                $this->assertProtectedMatchesPlan($article, $plan);

                /** @var ArticleTranslationRevision $revision */
                $revision = $article->publishedRevision;
                $fields = (array) $plan['planned_fields'];
                $revision->forceFill([
                    'seo_title' => (string) $fields['seo_title'],
                    'seo_description' => (string) $fields['seo_description'],
                ])->save();

                /** @var ArticleSeoMeta $seoMeta */
                $seoMeta = $article->seoMeta instanceof ArticleSeoMeta
                    ? $article->seoMeta
                    : ArticleSeoMeta::query()->withoutGlobalScopes()->firstOrNew([
                        'org_id' => (int) $article->org_id,
                        'article_id' => (int) $article->id,
                        'locale' => (string) $article->locale,
                    ]);

                $schema = is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
                $editorial = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];
                $editorial['source'] = 'seo_ops_p0_ctr_article_cms_update_writer';
                $editorial['dry_run_evidence_sha256'] = $dryRunSha;
                $editorial['first_screen_summary_direction'] = $fields['first_screen_summary_direction'];
                $editorial['claim_boundary_note'] = $fields['claim_boundary_note'];
                $editorial['internal_link_targets'] = $fields['internal_link_targets'];
                $editorial['cta_slots'] = [[
                    'key' => 'p0_ctr_primary_test_cta',
                    'label' => (string) $fields['primary_cta_label'],
                    'href' => (string) $fields['primary_cta_path'],
                    'kind' => 'start_test',
                ]];
                $editorial['schema_hold'] = true;
                $editorial['hreflang_hold'] = true;
                $editorial['search_submission_allowed'] = false;
                $editorial['sitemap_change_allowed'] = false;
                $editorial['llms_change_allowed'] = false;
                $schema['editorial_package_v1'] = $editorial;

                $seoMeta->forceFill([
                    'org_id' => (int) $article->org_id,
                    'article_id' => (int) $article->id,
                    'locale' => (string) $article->locale,
                    'seo_title' => (string) $fields['seo_title'],
                    'seo_description' => (string) $fields['seo_description'],
                    'og_title' => (string) $fields['seo_title'],
                    'og_description' => (string) $fields['seo_description'],
                    'robots' => (bool) $article->is_indexable ? 'index,follow' : 'noindex,nofollow',
                    'is_indexable' => (bool) $article->is_indexable,
                    'schema_json' => $schema,
                ])->save();

                $article->refresh();
                $article->load([
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                ]);
                $after = $this->currentProtectedSnapshot($article);

                if ($before !== $after) {
                    throw new \RuntimeException('protected fields changed for '.$plan['target']);
                }

                $results[] = [
                    'target' => (string) $plan['target'],
                    'article_id' => (int) $article->id,
                    'published_revision_id' => (int) $article->published_revision_id,
                    'seo_title_sha256' => hash('sha256', (string) $fields['seo_title']),
                    'seo_description_sha256' => hash('sha256', (string) $fields['seo_description']),
                    'cta_slot_count' => 1,
                    'protected_fields_preserved' => true,
                ];
            }

            return $results;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function requiredConfirmationPhrase(string $dryRunSha, array $plans): string
    {
        $targets = implode(', ', array_values(array_map(static fn (array $plan): string => (string) ($plan['target'] ?? ''), $plans)));

        return 'I explicitly approve Gate B P0 CTR repair article CMS update for 3 articles using dry-run evidence sha256 '
            .$dryRunSha.'; targets '.$targets.'; no landing surface write, no publish, no Search Channel, no IndexNow/Baidu/GSC, no schema/hreflang, no sitemap/llms, no URL Truth, no deploy/revalidation.';
    }

    private function validateCurrentArticle(Article $article, string $slug, string $locale, string $safePath, array &$issues): void
    {
        if ((string) $article->slug !== $slug) {
            $issues[] = 'slug_lock_mismatch:article:'.(int) $article->id;
        }
        if ((string) $article->locale !== $locale) {
            $issues[] = 'locale_lock_mismatch:article:'.(int) $article->id;
        }
        if ((string) $article->status !== 'published' || ! $article->publishedRevision instanceof ArticleTranslationRevision) {
            $issues[] = 'published_revision_missing:article:'.(int) $article->id;
        }
        if (! (bool) $article->is_public || ! (bool) $article->is_indexable) {
            $issues[] = 'article_not_public_indexable:article:'.(int) $article->id;
        }
        $canonicalPath = $this->canonicalPath($article->seoMeta instanceof ArticleSeoMeta ? (string) $article->seoMeta->canonical_url : null);
        if ($canonicalPath !== $safePath) {
            $issues[] = 'canonical_lock_mismatch:article:'.(int) $article->id;
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function assertProtectedMatchesPlan(Article $article, array $plan): void
    {
        $issues = [];
        $this->validateCurrentArticle(
            $article,
            (string) $plan['slug'],
            (string) $plan['locale'],
            (string) $plan['safe_path'],
            $issues
        );

        if ($issues !== []) {
            throw new \RuntimeException('protected lock mismatch: '.implode(', ', $issues));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function currentProtectedSnapshot(Article $article): array
    {
        return [
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) $article->translation_group_id,
            'canonical_path' => $this->canonicalPath($article->seoMeta instanceof ArticleSeoMeta ? (string) $article->seoMeta->canonical_url : null),
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => $article->working_revision_id !== null ? (int) $article->working_revision_id : null,
            'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
            'published_at' => optional($article->published_at)->toIso8601String(),
        ];
    }

    private function articleIdFromTarget(string $target): ?int
    {
        if (preg_match('/^article:(\d+):[A-Za-z-]+$/', $target, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '' || mb_strlen($string) > $max) {
            return null;
        }

        return $string;
    }

    private function safePublicTestPath(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $path = trim((string) $value);
        if (preg_match('#^/(en|zh)/tests/[a-z0-9][a-z0-9-]*$#i', $path) !== 1) {
            return null;
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function safeInternalPaths(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $path = trim((string) $item);
            if (preg_match('#^/(en|zh)/(?:articles|tests|topics)/[a-z0-9][a-z0-9-]*$#i', $path) === 1) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function canonicalPath(?string $canonical): ?string
    {
        if ($canonical === null || trim($canonical) === '') {
            return null;
        }
        $path = parse_url($canonical, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return $path;
        }

        return str_starts_with($canonical, '/') ? $canonical : null;
    }

    /**
     * @param  array<int, string>  $issues
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(array $issues, array $extra = []): array
    {
        return array_replace([
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:p0-ctr-article-cms-update-writer',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => array_values(array_unique($issues)),
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeEvidence(string $artifactDir, array $summary): ?array
    {
        File::ensureDirectoryExists($artifactDir);
        $path = rtrim($artifactDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'seo-ops-p0-ctr-article-cms-update-writer-'.now()->utc()->format('Ymd\THis\Z').'.json';

        $artifact = $summary;
        unset($artifact['evidence']);
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path) ?: 0,
        ];
    }

    private function finish(array $summary): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($summary['ok'] ?? false) === true) {
            $this->info('P0 CTR article CMS update writer '.($summary['status'] ?? 'planned').': '.json_encode($summary['planned_write_count'] ?? []));
        } else {
            $this->error('P0 CTR article CMS update writer blocked: '.implode(', ', (array) ($summary['issues'] ?? [])));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            return null;
        }
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /**
     * @return array<int, string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }
}
