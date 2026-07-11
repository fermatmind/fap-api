<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageDraftImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class SeoAgentArticleReleaseCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-release-gate-report.v1';

    private const STAGES = [
        'platform-readiness',
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
        {--expected-en-slug= : Expected en article slug}
        {--checkpoint= : Audited checkpoint JSON artifact path}
        {--resume : Resume from an existing audited checkpoint}
        {--actor=seo-agent : Actor recorded in checkpoint history}
        {--article-ids= : Optional comma-separated article ids for checkpoint identity}
        {--revision-ids= : Optional comma-separated revision ids for checkpoint identity}
        {--evidence= : Optional comma-separated evidence paths whose hashes must remain stable}';

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

        $checkpointPath = $this->checkpointPath();
        $checkpoint = null;
        if ((bool) $this->option('resume')) {
            if ($checkpointPath === null || ! is_file($checkpointPath)) {
                return $this->finish($this->failureReport($stage, 'checkpoint_unreadable', 'Resume requires a readable checkpoint JSON artifact.', $base));
            }
            $checkpoint = json_decode((string) file_get_contents($checkpointPath), true);
            $checkpointError = $this->checkpointError(is_array($checkpoint) ? $checkpoint : [], $base);
            if ($checkpointError !== null) {
                return $this->finish($this->failureReport($stage, $checkpointError, 'Checkpoint identity or evidence no longer matches the current release inputs.', $base));
            }
            if (in_array($stage, (array) ($checkpoint['completed_read_only_stages'] ?? []), true)) {
                $base['ok'] = true;
                $base['status'] = 'already_completed';
                $base['checkpoint'] = $this->checkpointSummary($checkpointPath, $checkpoint);

                return $this->finish($base);
            }
        }

        $base['platform_readiness'] = $this->platformReadiness();

        try {
            $report = match ($stage) {
                'platform-readiness' => array_replace($base, [
                    'ok' => (bool) ($base['platform_readiness']['ok'] ?? false),
                    'status' => (bool) ($base['platform_readiness']['ok'] ?? false) ? 'passed' : 'blocked_missing_capability',
                    'stage_report' => ['platform_readiness' => $base['platform_readiness']],
                ]),
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

        if ($checkpointPath !== null) {
            $checkpoint = $this->advanceCheckpoint($checkpoint ?? [], $base, $report);
            $this->writeCheckpoint($checkpointPath, $checkpoint);
            $report['checkpoint'] = $this->checkpointSummary($checkpointPath, $checkpoint);
        }

        return $this->finish($report);
    }

    private function checkpointPath(): ?string
    {
        $path = trim((string) $this->option('checkpoint'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @param array<string,mixed> $checkpoint @param array<string,mixed> $base */
    private function checkpointError(array $checkpoint, array $base): ?string
    {
        if (($checkpoint['schema_version'] ?? null) !== 'seo-agent-article-release-checkpoint.v1') {
            return 'checkpoint_schema_invalid';
        }
        if (($checkpoint['package']['sha256'] ?? null) !== ($base['package']['sha256'] ?? null)
            || ($checkpoint['translation_group_id'] ?? null) !== ($base['translation_group_id'] ?? null)
            || ($checkpoint['locales'] ?? null) !== ($base['locales'] ?? null)) {
            return 'checkpoint_package_identity_mismatch';
        }
        foreach ((array) ($checkpoint['evidence'] ?? []) as $evidence) {
            if (! is_array($evidence) || ! is_file((string) ($evidence['path'] ?? ''))
                || hash_file('sha256', (string) $evidence['path']) !== ($evidence['sha256'] ?? null)) {
                return 'checkpoint_evidence_hash_mismatch';
            }
        }

        return null;
    }

    /** @param array<string,mixed> $checkpoint @param array<string,mixed> $base @param array<string,mixed> $report @return array<string,mixed> */
    private function advanceCheckpoint(array $checkpoint, array $base, array $report): array
    {
        $now = now()->toIso8601String();
        $completed = array_values(array_unique(array_filter((array) ($checkpoint['completed_read_only_stages'] ?? []), 'is_string')));
        if (($report['ok'] ?? false) === true) {
            $completed[] = (string) $base['stage'];
            $completed = array_values(array_unique($completed));
        }
        $evidence = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('evidence')))) as $path) {
            $resolved = str_starts_with($path, '/') ? $path : base_path($path);
            if (is_file($resolved)) {
                $evidence[] = ['path' => $resolved, 'sha256' => hash_file('sha256', $resolved) ?: ''];
            }
        }
        $history = array_values(array_filter((array) ($checkpoint['history'] ?? []), 'is_array'));
        $history[] = ['stage' => $base['stage'], 'status' => $report['status'] ?? 'blocked', 'actor' => (string) $this->option('actor'), 'at' => $now];

        return [
            'schema_version' => 'seo-agent-article-release-checkpoint.v1',
            'package' => $base['package'],
            'translation_group_id' => $base['translation_group_id'],
            'locales' => $base['locales'],
            'slugs' => ['zh-CN' => (string) $this->option('expected-zh-slug'), 'en' => (string) $this->option('expected-en-slug')],
            'article_ids' => $this->integerList('article-ids'),
            'revision_ids' => $this->integerList('revision-ids'),
            'canonicals' => array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['canonical_url'] ?? ''), $this->cmsImportDraftItems((string) $base['package']['path'], (array) $base['locales'])))),
            'completed_read_only_stages' => $completed,
            'release_states' => array_replace([
                'package_compiled' => in_array('package-qa', $completed, true), 'media_ready' => in_array('media-readiness', $completed, true),
                'cms_draft_imported' => false, 'preview_passed' => false, 'published' => false, 'discoverability_complete' => false,
                'body_visual_parity' => in_array('cms-draft-dry-run', $completed, true), 'seo_enhancement_complete' => false,
                'url_truth_complete' => false, 'indexnow' => 'pending', 'baidu' => 'pending', 'gsc' => 'pending', 'closeout' => 'pending',
            ], (array) ($checkpoint['release_states'] ?? [])),
            'platform_readiness' => $base['platform_readiness'] ?? $this->platformReadiness(),
            'evidence' => $evidence !== [] ? $evidence : (array) ($checkpoint['evidence'] ?? []),
            'last_successful_stage' => ($report['ok'] ?? false) ? $base['stage'] : ($checkpoint['last_successful_stage'] ?? null),
            'next_stage' => $this->nextStage($completed),
            'created_at' => $checkpoint['created_at'] ?? $now, 'updated_at' => $now, 'actor' => (string) $this->option('actor'), 'history' => $history,
        ];
    }

    /** @return list<int> */
    private function integerList(string $option): array
    {
        return array_values(array_filter(array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->option($option))))), static fn (int $id): bool => $id > 0));
    }

    /** @param list<string> $completed */
    private function nextStage(array $completed): ?string
    {
        foreach (self::STAGES as $stage) {
            if (! in_array($stage, $completed, true)) {
                return $stage;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $checkpoint */
    private function writeCheckpoint(string $path, array $checkpoint): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($temporary, $path);
    }

    /** @param array<string,mixed> $checkpoint @return array<string,mixed> */
    private function checkpointSummary(string $path, array $checkpoint): array
    {
        return ['path' => $path, 'sha256' => hash_file('sha256', $path) ?: '', 'last_successful_stage' => $checkpoint['last_successful_stage'] ?? null, 'next_stage' => $checkpoint['next_stage'] ?? null];
    }

    /** @return array<string,mixed> */
    private function platformReadiness(): array
    {
        $required = ['seo-agent:compile-mode-c-package', 'media-assets:seo-release-preflight', 'articles:release-closeout', 'articles:seo-gate-rollout', 'content-release:revalidate', 'seo-intel:search-channel-provider-preflight'];
        $available = array_keys(Artisan::all());
        $commands = array_combine($required, array_map(static fn (string $command): bool => in_array($command, $available, true), $required));
        $missing = array_keys(array_filter($commands ?: [], static fn (bool $present): bool => ! $present));
        $revisionPath = base_path('../REVISION');

        return [
            'ok' => $missing === [], 'backend_revision' => is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : 'unavailable',
            'frontend_revision' => (string) config('app.frontend_revision', 'unavailable'), 'required_commands' => $commands,
            'missing_capabilities' => $missing, 'compiler_version' => 'seo-agent-mode-c-package-compiler.v1',
            'importer' => ['class' => SeoContentPackageDraftImporter::class, 'available' => class_exists(SeoContentPackageDraftImporter::class)],
            'public_baselines' => ['sitemap' => 'external_readback_required', 'llms' => 'external_readback_required', 'llms_full' => 'external_readback_required'],
            'schema_hreflang_gate_available' => in_array('articles:seo-gate-rollout', $available, true),
            'provider_capability_preflight_available' => in_array('seo-intel:search-channel-provider-preflight', $available, true),
            'runtime_lock_state' => 'external_readback_required', 'writes_authorized' => false,
        ];
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
