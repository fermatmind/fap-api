<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageDraftImporter;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class SeoAgentArticleReleaseCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-release-gate-report.v1';

    private const STAGES = [
        'package-qa',
        'media-readiness',
        'cms-draft-dry-run',
        'preview-qa',
        'publish-rehearsal',
        'closeout-readback',
    ];

    protected $signature = 'seo-agent:article-release
        {--package= : Path to a Mode C article content package directory}
        {--translation-group-id= : Expected translation_group_id}
        {--locales=zh-CN,en : Comma-separated locale list}
        {--stage=package-qa : Release stage to report}
        {--dry-run : Generate a no-write gate report}
        {--json : Emit a JSON gate report}
        {--expected-zh-slug= : Expected zh-CN article slug}
        {--expected-en-slug= : Expected en article slug}';

    protected $description = 'No-write staged SEO article release gate reporter for Mode C packages.';

    public function handle(SeoContentPackageDraftImporter $importer): int
    {
        $stage = trim((string) $this->option('stage'));
        if (! in_array($stage, self::STAGES, true)) {
            return $this->finish($this->failureReport($stage, 'invalid_stage', 'Unsupported stage.'));
        }

        $packageRoot = $this->packageRoot();
        if ($packageRoot === null) {
            return $this->finish($this->failureReport($stage, 'package_unreadable', 'Package directory is required and must be readable.'));
        }

        $locales = $this->locales();
        $translationGroupId = trim((string) $this->option('translation-group-id'));
        $base = $this->baseReport($stage, $packageRoot, $translationGroupId, $locales);

        try {
            $report = match ($stage) {
                'package-qa' => $this->packageQaReport($base, $importer, $packageRoot, $translationGroupId, $locales),
                'media-readiness' => $this->mediaReadinessReport($base, $packageRoot, $locales),
                'cms-draft-dry-run' => $this->cmsDraftDryRunReport($base, $importer, $packageRoot, $translationGroupId, $locales),
                'preview-qa' => $this->previewQaReport($base, $importer, $packageRoot, $translationGroupId, $locales),
                'publish-rehearsal' => $this->publishRehearsalReport($base, $importer, $packageRoot, $translationGroupId, $locales),
                'closeout-readback' => $this->closeoutReadbackReport($base, $packageRoot),
            };
        } catch (Throwable $exception) {
            $report = $this->failureReport($stage, 'runtime_error', $exception->getMessage(), $base);
        }

        return $this->finish($report);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function packageQaReport(array $base, SeoContentPackageDraftImporter $importer, string $packageRoot, string $translationGroupId, array $locales): array
    {
        $requiredDirectories = $this->requiredDirectoryEvidence($packageRoot);
        $plan = $importer->planFromDirectory($this->importerOptions($packageRoot, $translationGroupId, $locales));
        $passed = $this->allRequiredDirectoriesPresent($requiredDirectories) && (bool) ($plan['ok'] ?? false);

        return array_replace($base, [
            'ok' => $passed,
            'status' => $passed ? 'passed' : 'blocked',
            'stage_report' => [
                'required_directories' => $requiredDirectories,
                'importer_plan' => $this->sanitizedImporterPlan($plan),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function mediaReadinessReport(array $base, string $packageRoot, array $locales): array
    {
        $items = $this->cmsImportDraftItems($packageRoot, $locales);
        $missing = [];
        foreach ($items as $item) {
            foreach (['cover_media_asset_key', 'body_visual_asset_key', 'body_visual_image_url'] as $field) {
                if (trim((string) ($item[$field] ?? '')) === '') {
                    $missing[] = [
                        'locale' => (string) ($item['locale'] ?? ''),
                        'slug' => (string) ($item['slug'] ?? ''),
                        'field' => $field,
                    ];
                }
            }
        }

        $passed = $items !== [] && $missing === [];

        return array_replace($base, [
            'ok' => $passed,
            'status' => $passed ? 'passed' : 'blocked',
            'stage_report' => [
                'cms_import_draft_count' => count($items),
                'media_items' => array_map(
                    static fn (array $item): array => [
                        'locale' => (string) ($item['locale'] ?? ''),
                        'slug' => (string) ($item['slug'] ?? ''),
                        'cover_media_asset_key' => (string) ($item['cover_media_asset_key'] ?? ''),
                        'body_visual_asset_key' => (string) ($item['body_visual_asset_key'] ?? ''),
                        'body_visual_image_url' => (string) ($item['body_visual_image_url'] ?? ''),
                        'body_visual_fallback_authorized' => (bool) ($item['body_visual_fallback_authorized'] ?? false),
                    ],
                    $items
                ),
                'missing_media_fields' => $missing,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function cmsDraftDryRunReport(array $base, SeoContentPackageDraftImporter $importer, string $packageRoot, string $translationGroupId, array $locales): array
    {
        $plan = $importer->planFromDirectory($this->importerOptions($packageRoot, $translationGroupId, $locales));
        $passed = (bool) ($plan['ok'] ?? false);

        return array_replace($base, [
            'ok' => $passed,
            'status' => $passed ? 'passed' : 'blocked',
            'stage_report' => [
                'importer_plan' => $this->sanitizedImporterPlan($plan),
                'body_visual_parity' => array_values(array_map(
                    static fn (array $article): array => [
                        'locale' => (string) ($article['locale'] ?? ''),
                        'slug' => (string) ($article['slug'] ?? ''),
                        'media_metadata_parity' => $article['media_metadata_parity'] ?? null,
                    ],
                    array_filter((array) ($plan['articles'] ?? []), 'is_array')
                )),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function previewQaReport(array $base, SeoContentPackageDraftImporter $importer, string $packageRoot, string $translationGroupId, array $locales): array
    {
        $plan = $importer->planFromDirectory($this->importerOptions($packageRoot, $translationGroupId, $locales));
        $articles = array_values(array_filter((array) ($plan['articles'] ?? []), 'is_array'));
        $previewCandidates = array_values(array_filter(array_map(
            static fn (array $article): array => [
                'locale' => (string) ($article['locale'] ?? ''),
                'slug' => (string) ($article['slug'] ?? ''),
                'preview_url_candidate' => (string) ($article['preview_url_candidate'] ?? ''),
                'media_metadata_parity' => $article['media_metadata_parity'] ?? null,
            ],
            $articles
        ), static fn (array $candidate): bool => $candidate['preview_url_candidate'] !== ''));
        $passed = (bool) ($plan['ok'] ?? false) && $articles !== [] && count($previewCandidates) === count($articles);

        return array_replace($base, [
            'ok' => $passed,
            'status' => $passed ? 'passed' : 'blocked_external_draft_required',
            'external_evidence_required' => [
                'ops_article_preview_html',
                'operator_editorial_preview_approval',
            ],
            'stage_report' => [
                'importer_plan' => $this->sanitizedImporterPlan($plan),
                'preview_candidates' => $previewCandidates,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function publishRehearsalReport(array $base, SeoContentPackageDraftImporter $importer, string $packageRoot, string $translationGroupId, array $locales): array
    {
        $plan = $importer->planFromDirectory($this->importerOptions($packageRoot, $translationGroupId, $locales));
        $passed = (bool) ($plan['ok'] ?? false);

        return array_replace($base, [
            'ok' => $passed,
            'status' => $passed ? 'passed' : 'blocked',
            'external_exact_authorization_required_for_write' => true,
            'stage_report' => [
                'importer_plan' => $this->sanitizedImporterPlan($plan),
                'blocked_write_actions' => [
                    'cms_publish',
                    'make_indexable',
                    'sitemap_or_llms_enablement',
                    'schema_or_hreflang_enablement',
                    'search_submission',
                    'deploy',
                ],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function closeoutReadbackReport(array $base, string $packageRoot): array
    {
        $items = $this->cmsImportDraftItems($packageRoot, $this->locales());

        return array_replace($base, [
            'ok' => true,
            'status' => 'passed',
            'stage_report' => [
                'readback_targets' => array_map(
                    static fn (array $item): array => [
                        'locale' => (string) ($item['locale'] ?? ''),
                        'slug' => (string) ($item['slug'] ?? ''),
                        'canonical_url' => (string) ($item['canonical_url'] ?? ''),
                    ],
                    $items
                ),
                'external_evidence_required' => [
                    'public_smoke',
                    'discoverability_parity',
                    'url_truth_readback',
                    'search_channel_queue_readback',
                ],
            ],
        ]);
    }

    private function packageRoot(): ?string
    {
        $path = trim((string) $this->option('package'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_dir($path) && is_readable($path) ? rtrim($path, '/') : null;
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) $this->option('locales'))
        ), static fn (string $locale): bool => $locale !== ''));
    }

    /**
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function importerOptions(string $packageRoot, string $translationGroupId, array $locales): array
    {
        return [
            'package' => $packageRoot,
            'translation_group_id' => $translationGroupId,
            'locales' => $locales,
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
                'zh-CN' => (string) $this->option('expected-zh-slug'),
                'en' => (string) $this->option('expected-en-slug'),
            ],
        ];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function baseReport(string $stage, string $packageRoot, string $translationGroupId, array $locales): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'stage' => $stage,
            'dry_run' => (bool) $this->option('dry-run'),
            'write_allowed' => false,
            'writes_attempted' => false,
            'package' => [
                'path' => $packageRoot,
                'sha256' => $this->packageSha256($packageRoot),
            ],
            'translation_group_id' => $translationGroupId,
            'locales' => $locales,
            'supported_stages' => self::STAGES,
            'negative_guarantees' => [
                'no_cms_draft_creation',
                'no_cms_publish',
                'no_indexability_change',
                'no_sitemap_or_llms_change',
                'no_search_submission',
                'no_schema_or_hreflang_enablement',
                'no_deploy',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failureReport(string $stage, string $code, string $message, ?array $base = null): array
    {
        return array_replace($base ?? [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => $stage,
            'dry_run' => (bool) $this->option('dry-run'),
            'write_allowed' => false,
            'writes_attempted' => false,
        ], [
            'ok' => false,
            'status' => 'blocked',
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
        ]);
    }

    /**
     * @return array<string,bool>
     */
    private function requiredDirectoryEvidence(string $packageRoot): array
    {
        $directories = ['brief', 'pages', 'cms', 'contracts', 'review', 'codex', 'media', 'observation'];
        $evidence = [];
        foreach ($directories as $directory) {
            $evidence[$directory] = is_dir($packageRoot.'/'.$directory);
        }

        $evidence['manifest.json'] = is_file($packageRoot.'/manifest.json');

        return $evidence;
    }

    /**
     * @param  array<string,bool>  $evidence
     */
    private function allRequiredDirectoriesPresent(array $evidence): bool
    {
        foreach ($evidence as $present) {
            if (! $present) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $locales
     * @return list<array<string,mixed>>
     */
    private function cmsImportDraftItems(string $packageRoot, array $locales): array
    {
        $items = [];
        foreach (glob($packageRoot.'/cms/CMS_IMPORT_DRAFT_*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($locales !== [] && ! in_array((string) ($decoded['locale'] ?? ''), $locales, true)) {
                continue;
            }
            $items[] = $decoded;
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function sanitizedImporterPlan(array $plan): array
    {
        return [
            'ok' => (bool) ($plan['ok'] ?? false),
            'dry_run' => (bool) ($plan['dry_run'] ?? false),
            'action' => (string) ($plan['action'] ?? ''),
            'would_write' => (bool) ($plan['would_write'] ?? false),
            'translation_group_id' => (string) ($plan['translation_group_id'] ?? ''),
            'article_count' => count((array) ($plan['articles'] ?? [])),
            'articles' => array_values(array_map(
                static fn (array $article): array => [
                    'locale' => (string) ($article['locale'] ?? ''),
                    'slug' => (string) ($article['slug'] ?? ''),
                    'action' => (string) ($article['action'] ?? ''),
                    'preview_url_candidate' => (string) ($article['preview_url_candidate'] ?? ''),
                    'media_metadata_parity' => $article['media_metadata_parity'] ?? null,
                ],
                array_filter((array) ($plan['articles'] ?? []), 'is_array')
            )),
            'errors' => array_values(array_filter((array) ($plan['errors'] ?? []), 'is_array')),
            'warnings' => array_values(array_filter((array) ($plan['warnings'] ?? []), 'is_array')),
            'active_surface_guard_scan' => $plan['active_surface_guard_scan'] ?? null,
            'contract_integrity_scan' => $plan['contract_integrity_scan'] ?? null,
        ];
    }

    private function packageSha256(string $packageRoot): string
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relativePath = ltrim(str_replace($packageRoot, '', $file->getPathname()), '/');
            $hashes[] = $relativePath.':'.(hash_file('sha256', $file->getPathname()) ?: '');
        }
        sort($hashes);

        return hash('sha256', implode("\n", $hashes));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function finish(array $report): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif ((bool) ($report['ok'] ?? false)) {
            $this->info((string) ($report['status'] ?? 'passed'));
        } else {
            $this->error((string) ($report['status'] ?? 'blocked'));
        }

        return (bool) ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
