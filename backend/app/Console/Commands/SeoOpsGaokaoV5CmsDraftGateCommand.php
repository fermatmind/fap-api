<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageDraftImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoOpsGaokaoV5CmsDraftGateCommand extends Command
{
    private const OUTPUT_SCHEMA = 'seo-ops-gaokao-v5-cms-draft-gate.v1';

    private const EXPECTED_LOCALE = 'zh-CN';

    protected $signature = 'seo-ops:gaokao-v5-cms-draft-gate
        {--package= : Path to the repaired Gaokao v5 SEO content package directory}
        {--confirm-package-sha256= : Expected SHA-256 of the package directory}
        {--translation-group-id= : Expected package translation_group_id}
        {--expected-zh-slug= : Expected zh-CN article slug}
        {--artifact-dir= : Directory for read-only evidence output}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only gate evidence for Gaokao v5 CMS draft package readiness; writes evidence only.';

    public function handle(SeoContentPackageDraftImporter $importer): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $packageRoot = $this->packageRoot();
        $packageSha = $packageRoot !== null ? $this->packageSha256($packageRoot) : '';
        $expectedSha = trim((string) $this->option('confirm-package-sha256'));
        $issues = [];

        if ($packageRoot === null) {
            $issues[] = 'package_unreadable';
        } elseif ($expectedSha === '' || ! hash_equals($expectedSha, $packageSha)) {
            $issues[] = 'package_sha_mismatch';
        }

        $translationGroupId = trim((string) $this->option('translation-group-id'));
        $expectedSlug = trim((string) $this->option('expected-zh-slug'));
        if ($translationGroupId === '') {
            $issues[] = 'translation_group_id_required';
        }
        if ($expectedSlug === '') {
            $issues[] = 'expected_zh_slug_required';
        }

        $plan = [];
        if ($issues === [] && $packageRoot !== null) {
            $plan = $importer->planFromDirectory([
                'package' => $packageRoot,
                'translation_group_id' => $translationGroupId,
                'locales' => [self::EXPECTED_LOCALE],
                'dry_run' => true,
                'json' => true,
                'draft_only' => true,
                'no_publish' => true,
                'no_index' => true,
                'no_sitemap' => true,
                'no_llms' => true,
                'schema_hold' => true,
                'hreflang_hold' => true,
                'expected_slugs' => [
                    self::EXPECTED_LOCALE => $expectedSlug,
                    'en' => '',
                ],
            ]);
            if (($plan['ok'] ?? false) !== true) {
                foreach ((array) ($plan['errors'] ?? []) as $error) {
                    $issues[] = is_array($error)
                        ? (string) (($error['code'] ?? 'importer_plan_error'))
                        : 'importer_plan_error';
                }
            }
        }

        $articles = array_values(array_filter(
            (array) ($plan['articles'] ?? []),
            static fn (mixed $article): bool => is_array($article)
        ));
        if ($issues === [] && count($articles) !== 1) {
            $issues[] = 'planned_article_count_not_one';
        }
        foreach ($articles as $article) {
            if (($article['article_id'] ?? null) !== null) {
                $issues[] = 'existing_article_authority_found_new_article_gate_blocked';
            }
            if ((string) ($article['action'] ?? '') !== 'would_create_draft') {
                $issues[] = 'unexpected_draft_action';
            }
        }

        $summary = $this->summary($packageRoot, $packageSha, $translationGroupId, $expectedSlug, $plan, $articles, $issues);
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $articles
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function summary(?string $packageRoot, string $packageSha, string $translationGroupId, string $expectedSlug, array $plan, array $articles, array $issues): array
    {
        $ok = $issues === [];
        $article = $articles[0] ?? [];

        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:gaokao-v5-cms-draft-gate',
            'ok' => $ok,
            'status' => $ok ? 'planned' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'source_package' => [
                'path' => $packageRoot,
                'sha256' => $packageSha,
                'translation_group_id' => $translationGroupId,
                'expected_zh_slug' => $expectedSlug,
            ],
            'authority_sources' => [
                'cms_draft_gate' => 'backend SeoContentPackageDraftImporter dry-run',
                'article_authority' => 'backend.articles + article_translation_revisions + article_seo_meta',
                'package_authority' => 'repaired generated Gaokao v5 SEO content package',
            ],
            'candidate_counts' => [
                'package_articles' => count($articles),
                'planned_draft_articles' => $ok ? count($articles) : 0,
                'blocked_articles' => $ok ? 0 : count($articles),
            ],
            'planned_write_count' => [
                'article_drafts' => $ok ? count($articles) : 0,
                'article_revisions' => $ok ? count($articles) : 0,
                'article_seo_meta_rows' => $ok ? count($articles) : 0,
                'article_editorial_package_import_rows' => $ok ? count($articles) : 0,
            ],
            'protected_diff_summary' => [
                'operation_type' => 'new_article_draft_only',
                'slug' => (string) ($article['slug'] ?? '') === $expectedSlug ? 'no_change' : 'mismatch',
                'canonical' => 'no_change',
                'locale' => (string) ($article['locale'] ?? '') === self::EXPECTED_LOCALE ? 'no_change' : 'mismatch',
                'article_id' => ($article['article_id'] ?? null) === null ? 'new_article_no_existing_authority' : 'resolved_existing',
                'publication' => 'hold_no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
                'search_submission' => 'hold_no_change',
            ],
            'article_plans' => array_map(fn (array $planned): array => $this->articlePlan($planned, $expectedSlug), $articles),
            'delegate_dry_run_command' => $this->delegateCommand($packageRoot, $translationGroupId, $expectedSlug, true, $packageSha),
            'delegate_write_command_after_separate_approval' => $this->delegateCommand($packageRoot, $translationGroupId, $expectedSlug, false, $packageSha),
            'required_confirmation_phrase' => $this->confirmationPhrase($packageSha, $translationGroupId, $expectedSlug),
            'importer_plan' => [
                'ok' => (bool) ($plan['ok'] ?? false),
                'action' => (string) ($plan['action'] ?? ''),
                'active_surface_guard_scan' => $plan['active_surface_guard_scan'] ?? null,
                'contract_integrity_scan' => $plan['contract_integrity_scan'] ?? null,
                'safety_flags' => $plan['safety_flags'] ?? null,
                'errors' => $plan['errors'] ?? [],
                'warnings' => $plan['warnings'] ?? [],
            ],
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private function articlePlan(array $article, string $expectedSlug): array
    {
        $slug = (string) ($article['slug'] ?? '');
        $locale = (string) ($article['locale'] ?? '');

        return [
            'target' => 'article:new:'.$locale.':'.$slug,
            'locale' => $locale,
            'slug' => $slug,
            'expected_slug' => $expectedSlug,
            'action' => (string) ($article['action'] ?? ''),
            'article_id' => $article['article_id'] ?? null,
            'working_revision_status' => (string) ($article['working_revision_status'] ?? ''),
            'status' => (string) ($article['status'] ?? ''),
            'is_public' => (bool) ($article['is_public'] ?? true),
            'is_indexable' => (bool) ($article['is_indexable'] ?? true),
            'sitemap_eligible' => (bool) ($article['sitemap_eligible'] ?? true),
            'llms_eligible' => (bool) ($article['llms_eligible'] ?? true),
            'protected_diff' => [
                'slug' => $slug === $expectedSlug ? 'no_change' : 'mismatch',
                'locale' => $locale === self::EXPECTED_LOCALE ? 'no_change' : 'mismatch',
                'publication' => 'hold_no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
                'search_submission' => 'hold_no_change',
            ],
        ];
    }

    private function packageRoot(): ?string
    {
        $path = trim((string) $this->option('package'));
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $real = realpath($path);

        return is_string($real) ? $real : null;
    }

    private function packageSha256(string $root): string
    {
        $files = collect(File::allFiles($root))
            ->filter(static fn (\SplFileInfo $file): bool => $file->isFile())
            ->map(static fn (\SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values();

        $hash = hash_init('sha256');
        foreach ($files as $path) {
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            hash_update($hash, $relative."\0".hash_file('sha256', $path)."\n");
        }

        return hash_final($hash);
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '') {
            return null;
        }

        File::ensureDirectoryExists($dir);
        $real = realpath($dir);

        return is_string($real) && is_dir($real) ? $real : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function writeEvidence(string $artifactDir, array $summary): array
    {
        $path = $artifactDir.'/seo-ops-gaokao-v5-cms-draft-gate-'.now()->utc()->format('Ymd\THis\Z').'.json';
        File::put($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
        ];
    }

    /**
     * @return list<string>
     */
    private function delegateCommand(?string $packageRoot, string $translationGroupId, string $expectedSlug, bool $dryRun, string $packageSha): array
    {
        $parts = [
            'php artisan articles:import-seo-content-package-draft',
            '--package='.escapeshellarg((string) $packageRoot),
            '--translation-group-id='.escapeshellarg($translationGroupId),
            '--locales=zh-CN',
            '--draft-only',
            '--no-publish',
            '--no-index',
            '--no-sitemap',
            '--no-llms',
            '--schema-hold',
            '--hreflang-hold',
            '--expected-zh-slug='.escapeshellarg($expectedSlug),
            '--expected-en-slug='."''",
            '--json',
        ];
        if ($dryRun) {
            $parts[] = '--dry-run';
        } else {
            $parts[] = '# execute only after exact approval: '.$this->confirmationPhrase($packageSha, $translationGroupId, $expectedSlug);
        }

        return $parts;
    }

    private function confirmationPhrase(string $packageSha, string $translationGroupId, string $expectedSlug): string
    {
        return 'I explicitly approve articles:import-seo-content-package-draft draft-only write for Gaokao v5 package sha256 '.$packageSha.' translation_group_id '.$translationGroupId.' slug '.$expectedSlug.'; no publish, no URL Truth, no sitemap/llms, no schema/hreflang, no Search Channel, no deploy/revalidation.';
    }

    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(array $issues, array $extra = []): array
    {
        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:gaokao-v5-cms-draft-gate',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
            ...$extra,
        ];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'url_truth_write' => false,
            'schema_hreflang_write' => false,
            'sitemap_llms_mutation' => false,
            'search_channel_enqueue' => false,
            'indexnow_submit' => false,
            'baidu_submit' => false,
            'gsc_request_indexing' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'deploy' => false,
            'revalidation' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
            $this->line('status='.(string) ($summary['status'] ?? ''));
            $this->line('planned_article_drafts='.(string) data_get($summary, 'planned_write_count.article_drafts', 0));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
