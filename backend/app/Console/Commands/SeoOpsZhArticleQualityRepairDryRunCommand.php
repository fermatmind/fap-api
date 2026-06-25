<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoOpsZhArticleQualityRepairDryRunCommand extends Command
{
    private const INPUT_SCHEMA = 'fermatmind-zh-article-quality-cms-repair-package.v1';

    private const OUTPUT_SCHEMA = 'seo-ops-zh-article-quality-repair-dry-run.v1';

    private const EXPECTED_OPERATION_COUNT = 9;

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

    protected $signature = 'seo-ops:zh-article-quality-repair-dry-run
        {--package= : Path to fermatmind-zh-article-quality-cms-repair-package.v1 JSON package}
        {--confirm-package-sha256= : Expected SHA-256 of the source package}
        {--artifact-dir= : Directory for read-only evidence output}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only backend authority dry-run for the zh-CN article quality repair package; writes evidence only.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $loaded = $this->loadPackage();
        if (($loaded['ok'] ?? false) !== true) {
            $summary = $this->failureSummary((array) ($loaded['issues'] ?? ['package_unreadable']), [
                'source_package' => $loaded['source_package'] ?? null,
            ]);
            $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

            return $this->finish($summary);
        }

        $package = (array) $loaded['package'];
        $operations = array_values((array) ($package['operations'] ?? []));
        $issues = $this->validatePackageShape($package, $operations);
        $plans = array_map(fn (array $operation): array => $this->operationPlan($operation), $operations);

        foreach ($plans as $plan) {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        $issues = array_values(array_unique($issues));
        $resolvedCount = count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['resolved'] ?? false)));
        $headingReplacementCount = array_sum(array_map(
            static fn (array $plan): int => (int) data_get($plan, 'planned_repair_counts.heading_replacements', 0),
            $plans
        ));
        $linkReplacementCount = array_sum(array_map(
            static fn (array $plan): int => (int) data_get($plan, 'planned_repair_counts.link_replacements', 0),
            $plans
        ));

        $summary = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:zh-article-quality-repair-dry-run',
            'ok' => $issues === [],
            'status' => $issues === [] ? 'planned' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'source_package' => [
                'path' => (string) $loaded['source_package_path'],
                'sha256' => (string) $loaded['source_package_sha256'],
                'schema' => (string) ($package['schema'] ?? ''),
                'source_scan' => (string) ($package['source_scan'] ?? ''),
                'source_scan_sha256' => (string) ($package['source_scan_sha256'] ?? ''),
            ],
            'authority_sources' => [
                'article_quality_repair' => 'backend.articles + article_seo_meta + article_translation_revisions',
            ],
            'candidate_counts' => [
                'package_operations' => count($operations),
                'resolved_article_operations' => $resolvedCount,
                'unresolved_article_operations' => count($operations) - $resolvedCount,
            ],
            'planned_ops_count' => [
                'article_quality_repair_operations' => $resolvedCount,
                'heading_replacements' => $headingReplacementCount,
                'link_replacements' => $linkReplacementCount,
                'total_replacements' => $headingReplacementCount + $linkReplacementCount,
            ],
            'protected_diff_summary' => [
                'slug' => 'no_change',
                'canonical' => 'no_change',
                'locale' => 'no_change',
                'article_id' => $resolvedCount === count($operations) ? 'resolved_no_change' : 'unresolved',
                'publication' => 'no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
                'search_submission' => 'hold_no_change',
            ],
            'article_operation_plans' => $plans,
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPackage(): array
    {
        $path = $this->readablePath((string) $this->option('package'));
        if ($path === null) {
            return ['ok' => false, 'issues' => ['package_unreadable']];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return [
                'ok' => false,
                'source_package' => ['path' => $path],
                'issues' => ['forbidden_input_field_present'],
                'forbidden_matches' => $forbidden,
            ];
        }

        $actualSha = hash('sha256', $raw);
        $expectedSha = trim((string) $this->option('confirm-package-sha256'));
        if ($expectedSha === '' || ! hash_equals($expectedSha, $actualSha)) {
            return [
                'ok' => false,
                'source_package' => [
                    'path' => $path,
                    'sha256' => $actualSha,
                    'expected_sha256' => $expectedSha,
                ],
                'issues' => ['package_sha_mismatch'],
            ];
        }

        try {
            $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'source_package' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['package_json_invalid'],
            ];
        }

        if (! is_array($package)) {
            return [
                'ok' => false,
                'source_package' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['package_json_not_object'],
            ];
        }

        if (($package['schema'] ?? null) !== self::INPUT_SCHEMA) {
            return [
                'ok' => false,
                'source_package' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['package_schema_invalid'],
            ];
        }

        return [
            'ok' => true,
            'package' => $package,
            'source_package_path' => $path,
            'source_package_sha256' => $actualSha,
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<int, mixed>  $operations
     * @return array<int, string>
     */
    private function validatePackageShape(array $package, array $operations): array
    {
        $issues = [];
        if (($package['schema'] ?? null) !== self::INPUT_SCHEMA) {
            $issues[] = 'package_schema_invalid';
        }
        if ((int) ($package['operation_count'] ?? -1) !== self::EXPECTED_OPERATION_COUNT) {
            $issues[] = 'package_operation_count_field_unexpected';
        }
        if (count($operations) !== self::EXPECTED_OPERATION_COUNT) {
            $issues[] = 'package_operation_count_unexpected';
        }
        if (($package['negative_guarantees'] ?? null) !== null) {
            foreach ((array) $package['negative_guarantees'] as $key => $value) {
                if ($value !== false) {
                    $issues[] = 'negative_guarantee_not_false:'.$key;
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operationPlan(array $operation): array
    {
        $path = (string) ($operation['path'] ?? '');
        $slug = $this->slugFromArticlePath($path);
        $locale = 'zh-CN';
        $issues = [];

        if ($slug === null) {
            $issues[] = 'article_path_invalid:'.$path;
        }

        $article = $slug !== null
            ? Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->where('slug', $slug)
                ->where('locale', $locale)
                ->first()
            : null;

        if (! $article instanceof Article && $slug !== null) {
            $issues[] = 'article_not_found:'.$slug.':'.$locale;
        }

        if ((bool) ($operation['cms_write_allowed_now'] ?? true) !== false) {
            $issues[] = 'operation_cms_write_allowed_now_not_false:'.$path;
        }

        $seoMeta = $article?->seoMeta;
        $currentCanonical = $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null;
        $currentCanonicalPath = $this->canonicalPath($currentCanonical) ?: $path;
        $headingReplacements = array_values((array) ($operation['heading_replacements'] ?? []));
        $linkReplacements = array_values((array) ($operation['link_replacements'] ?? []));

        return [
            'target' => $article instanceof Article ? 'article:'.(int) $article->id.':'.$locale : 'article:unresolved:'.$locale,
            'path' => $path,
            'slug' => $slug,
            'locale' => $locale,
            'resolved' => $article instanceof Article,
            'authority_source' => 'backend.articles + article_seo_meta + article_translation_revisions',
            'source_snapshot' => [
                'path' => (string) ($operation['snapshot_path'] ?? ''),
                'sha256' => (string) ($operation['snapshot_sha256'] ?? ''),
            ],
            'issue_codes' => array_values(array_filter(explode(';', (string) ($operation['issue_codes'] ?? '')))),
            'current' => $article instanceof Article ? [
                'article_id' => (int) $article->id,
                'translation_group_id' => (string) $article->translation_group_id,
                'title' => (string) $article->title,
                'excerpt' => (string) $article->excerpt,
                'seo_title' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->seo_title : null,
                'seo_description' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->seo_description : null,
                'canonical_url' => $currentCanonical,
                'canonical_path' => $currentCanonicalPath,
                'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
                'jsonld_total' => $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json) ? count($seoMeta->schema_json) : 0,
                'status' => (string) $article->status,
                'lifecycle_state' => $article->lifecycle_state,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'working_revision_id' => $article->working_revision_id,
                'published_revision_id' => $article->published_revision_id,
                'published_revision_status' => $article->publishedRevision?->revision_status,
                'published_at' => optional($article->published_at)->toIso8601String(),
            ] : null,
            'planned_repairs' => [
                'heading_replacements' => $this->sanitizeReplacements($headingReplacements),
                'link_replacements' => $this->sanitizeReplacements($linkReplacements),
            ],
            'planned_repair_counts' => [
                'heading_replacements' => count($headingReplacements),
                'link_replacements' => count($linkReplacements),
            ],
            'protected_fields_from_package' => (array) ($operation['protected_fields'] ?? []),
            'protected_diff' => [
                'slug' => $this->diffItem($article instanceof Article ? (string) $article->slug : $slug, $slug, 'no_change'),
                'canonical' => $this->diffItem($currentCanonicalPath, $currentCanonicalPath, 'no_change'),
                'locale' => $this->diffItem($article instanceof Article ? (string) $article->locale : $locale, $locale, 'no_change'),
                'article_id' => $article instanceof Article
                    ? $this->diffItem((int) $article->id, (int) $article->id, 'resolved_no_change')
                    : $this->diffItem(null, 'unresolved', 'unresolved'),
                'translation_group_id' => $article instanceof Article
                    ? $this->diffItem((string) $article->translation_group_id, (string) $article->translation_group_id, 'no_change')
                    : $this->diffItem(null, 'unresolved', 'unresolved'),
                'publication' => $this->diffItem($article instanceof Article ? (string) $article->status : null, 'unchanged', 'no_change'),
                'schema' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'hreflang' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'sitemap' => $this->diffItem($article instanceof Article ? (bool) $article->sitemap_eligible : null, 'unchanged', 'no_change'),
                'llms' => $this->diffItem($article instanceof Article ? (bool) $article->llms_eligible : null, 'unchanged', 'no_change'),
                'search_submission' => $this->diffItem('not_attempted', 'hold', 'hold_no_change'),
            ],
            'operator_review_required' => (bool) ($operation['operator_review_required'] ?? false),
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<int, mixed>  $replacements
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeReplacements(array $replacements): array
    {
        return array_map(static fn ($replacement): array => [
            'find' => (string) data_get($replacement, 'find', ''),
            'replace_with' => (string) data_get($replacement, 'replace_with', ''),
            'scope' => (string) data_get($replacement, 'scope', ''),
        ], $replacements);
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
            'command' => 'php artisan seo-ops:zh-article-quality-repair-dry-run',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeEvidence(string $artifactDir, array $summary): ?array
    {
        File::ensureDirectoryExists($artifactDir);
        $path = rtrim($artifactDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'seo-ops-zh-article-quality-repair-dry-run-'.now()->utc()->format('Ymd\THis\Z').'.json';

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
            $this->info('ZH article quality repair dry-run planned: '.json_encode($summary['planned_ops_count'] ?? []));
        } else {
            $this->error('ZH article quality repair dry-run blocked: '.implode(', ', (array) ($summary['issues'] ?? [])));
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

    private function slugFromArticlePath(string $path): ?string
    {
        $prefix = '/zh/articles/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $slug = trim(substr($path, strlen($prefix)), '/');

        return $slug !== '' && ! str_contains($slug, '/') ? $slug : null;
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
     * @param  mixed  $current
     * @param  mixed  $proposed
     * @return array<string, mixed>
     */
    private function diffItem($current, $proposed, string $change): array
    {
        return [
            'current' => $current,
            'proposed' => $proposed,
            'change' => $change,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_draft_create' => false,
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
        ];
    }
}
