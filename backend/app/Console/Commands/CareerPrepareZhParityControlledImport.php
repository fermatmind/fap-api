<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerCliArtifactPathGuard;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class CareerPrepareZhParityControlledImport extends Command
{
    private const VALIDATOR_VERSION = 'career_zh_parity_controlled_import_readiness_v0.1';

    private const SOURCE_MANIFEST_SCHEMA = 'career_zh_parity_controlled_import_manifest.v0.1';

    protected $signature = 'career:prepare-zh-parity-controlled-import
        {--source-report=backend/docs/career/generated/career-zh-parity-live-01-production-report.json : CAREER-ZH-PARITY-LIVE-01 production report JSON}
        {--chunk-size=50 : Positive candidate chunk size for dry-run/import/cache command planning}
        {--output= : Optional JSON output path}
        {--json : Emit machine-readable report}';

    protected $description = 'Prepare a read-only controlled import/cache readiness plan from the zh career parity live report.';

    public function handle(): int
    {
        try {
            $sourceReportPath = $this->sourceReportPath();
            $chunkSize = $this->chunkSize();
            $sourceReport = $this->readJsonFile($sourceReportPath);
            $sourceManifest = $this->sourceManifest($sourceReport);
            $candidates = $this->candidateSlugs($sourceManifest);
            $errors = $this->validateSourceReport($sourceReport, $sourceManifest, $candidates);

            if ($errors !== []) {
                return $this->finish([
                    'validator_version' => self::VALIDATOR_VERSION,
                    'decision' => 'fail',
                    'read_only' => true,
                    'writes_database' => false,
                    'cache_mutation' => false,
                    'content_mutation' => false,
                    'source_report' => $this->sourceReportSummary($sourceReportPath, $sourceReport),
                    'errors' => array_values(array_unique($errors)),
                ], false);
            }

            $chunks = $this->chunks($candidates, $chunkSize);
            $report = [
                'validator_version' => self::VALIDATOR_VERSION,
                'decision' => 'pass',
                'read_only' => true,
                'writes_database' => false,
                'cache_mutation' => false,
                'content_mutation' => false,
                'publish_mutation' => false,
                'deploy_mutation' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'index_strategy_changed' => false,
                'source_report' => $this->sourceReportSummary($sourceReportPath, $sourceReport),
                'readiness_decision' => 'ready_for_reviewed_workbook_dry_run',
                'blocked_until_explicit_approval' => [
                    'force_import',
                    'production_cache_forget_warm',
                    'production_controlled_execution',
                ],
                'controlled_import_input_manifest' => [
                    'schema_version' => (string) $sourceManifest['schema_version'],
                    'source_scope' => (string) $sourceManifest['source_scope'],
                    'target_command' => (string) $sourceManifest['target_command'],
                    'target_locale' => (string) $sourceManifest['target_locale'],
                    'candidate_count' => count($candidates),
                    'candidate_slugs_sha256' => $this->slugListSha256($candidates),
                    'candidate_slugs' => $candidates,
                ],
                'source_live_counts' => [
                    'total_slugs' => (int) data_get($sourceReport, 'summary.total_slugs', 0),
                    'runtime_shell_count' => (int) data_get($sourceReport, 'production_live_assessment.runtime_shell_count', 0),
                    'missing_modules_by_slug_count' => (int) data_get($sourceReport, 'production_live_assessment.missing_modules_by_slug_count', 0),
                    'root_cause_counts' => (array) data_get($sourceReport, 'production_live_assessment.root_cause_counts', []),
                    'cache_stale_counts' => (array) data_get($sourceReport, 'production_live_assessment.cache_stale_counts', []),
                    'cms_asset_exists_counts' => (array) data_get($sourceReport, 'production_live_assessment.cms_asset_exists_counts', []),
                ],
                'execution_plan' => [
                    'chunk_size' => $chunkSize,
                    'chunk_count' => count($chunks),
                    'requires_reviewed_workbook' => true,
                    'requires_validated_full_upload_manifest' => true,
                    'dry_run_first' => true,
                    'force_import_requires_explicit_approval' => true,
                    'cache_refresh_requires_explicit_post_import_approval' => true,
                    'must_not_change_sitemap_llms_or_index_strategy' => true,
                    'placeholder_inputs' => [
                        'reviewed_workbook' => '<reviewed_workbook.xlsx>',
                        'validated_full_upload_plan_manifest' => '<validated_full_upload_plan.json>',
                    ],
                    'chunks' => $this->executionChunks($chunks, $chunkSize),
                ],
                'acceptance_commands' => [
                    'validate_readiness_report' => 'python3 -m json.tool backend/docs/career/generated/career-zh-parity-controlled-import-readiness-01.json >/dev/null',
                    'readiness_command' => 'php backend/artisan career:prepare-zh-parity-controlled-import --source-report=backend/docs/career/generated/career-zh-parity-live-01-production-report.json --output=backend/docs/career/generated/career-zh-parity-controlled-import-readiness-01.json --json',
                    'dry_run_template' => 'php backend/artisan career:import-selected-display-assets --file=<reviewed_workbook.xlsx> --manifest=<validated_full_upload_plan.json> --manifest-chunk-size='.$chunkSize.' --manifest-chunk-index=1 --dry-run --json',
                    'cache_refresh_template_after_import_approval' => 'php backend/artisan career:warm-public-authority-cache --job-detail-slugs=<chunk_slugs_csv> --job-detail-locales=zh-CN --forget-job-detail --job-detail-only --json',
                ],
            ];

            return $this->finish($report, true);
        } catch (Throwable $throwable) {
            return $this->finish([
                'validator_version' => self::VALIDATOR_VERSION,
                'decision' => 'fail',
                'read_only' => true,
                'writes_database' => false,
                'cache_mutation' => false,
                'content_mutation' => false,
                'errors' => [$throwable->getMessage()],
            ], false);
        }
    }

    private function sourceReportPath(): string
    {
        $path = trim((string) $this->option('source-report'));
        if ($path === '') {
            throw new RuntimeException('--source-report is required.');
        }
        if (! is_file($path)) {
            throw new RuntimeException('--source-report does not exist: '.$path);
        }

        return $path;
    }

    private function chunkSize(): int
    {
        $raw = trim((string) $this->option('chunk-size'));
        if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            throw new RuntimeException('--chunk-size must be a positive integer.');
        }

        return (int) $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Source report must be valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $sourceReport
     * @return array<string, mixed>
     */
    private function sourceManifest(array $sourceReport): array
    {
        $manifest = $sourceReport['controlled_import_manifest'] ?? null;
        if (! is_array($manifest)) {
            throw new RuntimeException('Source report must include controlled_import_manifest.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function candidateSlugs(array $manifest): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            (array) ($manifest['candidate_slugs'] ?? []),
        ), static fn (string $slug): bool => $slug !== '')));
    }

    /**
     * @param  array<string, mixed>  $sourceReport
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $candidateSlugs
     * @return list<string>
     */
    private function validateSourceReport(array $sourceReport, array $manifest, array $candidateSlugs): array
    {
        $errors = [];
        $rows = (array) ($manifest['rows'] ?? []);
        $rowSlugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $row): string => is_array($row) ? strtolower(trim((string) ($row['slug'] ?? ''))) : '',
            $rows,
        ), static fn (string $slug): bool => $slug !== '')));

        if (($manifest['schema_version'] ?? null) !== self::SOURCE_MANIFEST_SCHEMA) {
            $errors[] = 'controlled_import_manifest.schema_version must be '.self::SOURCE_MANIFEST_SCHEMA.'.';
        }
        if (($manifest['target_command'] ?? null) !== 'career:import-selected-display-assets') {
            $errors[] = 'controlled_import_manifest.target_command must be career:import-selected-display-assets.';
        }
        if (($manifest['target_locale'] ?? null) !== 'zh-CN') {
            $errors[] = 'controlled_import_manifest.target_locale must be zh-CN.';
        }
        if ((bool) ($manifest['requires_reviewed_workbook'] ?? false) !== true) {
            $errors[] = 'controlled_import_manifest.requires_reviewed_workbook must be true.';
        }
        if ((bool) ($manifest['requires_explicit_production_write_approval'] ?? false) !== true) {
            $errors[] = 'controlled_import_manifest.requires_explicit_production_write_approval must be true.';
        }
        if ((bool) ($manifest['requires_cache_forget_warm_after_import'] ?? false) !== true) {
            $errors[] = 'controlled_import_manifest.requires_cache_forget_warm_after_import must be true.';
        }
        if ((bool) ($manifest['must_not_change_sitemap_llms_or_index_strategy'] ?? false) !== true) {
            $errors[] = 'controlled_import_manifest.must_not_change_sitemap_llms_or_index_strategy must be true.';
        }
        if ((int) ($manifest['candidate_count'] ?? -1) !== count($candidateSlugs)) {
            $errors[] = 'controlled_import_manifest.candidate_count must match candidate_slugs.';
        }
        if (count($rows) !== count($candidateSlugs)) {
            $errors[] = 'controlled_import_manifest.rows must match candidate_slugs.';
        }
        if ($rowSlugs !== $candidateSlugs) {
            $errors[] = 'controlled_import_manifest.rows slug order must match candidate_slugs.';
        }
        if ($candidateSlugs === []) {
            $errors[] = 'controlled_import_manifest must include at least one candidate slug.';
        }
        foreach ($candidateSlugs as $slug) {
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
                $errors[] = 'Invalid candidate slug: '.$slug.'.';
            }
        }
        if ((bool) ($sourceReport['read_only'] ?? false) !== true) {
            $errors[] = 'Source report must be read_only.';
        }
        if ((bool) ($sourceReport['writes_database'] ?? true) !== false) {
            $errors[] = 'Source report must not write database.';
        }
        if ((bool) ($sourceReport['sitemap_changed'] ?? true) !== false) {
            $errors[] = 'Source report must not change sitemap.';
        }
        if ((bool) ($sourceReport['llms_changed'] ?? true) !== false) {
            $errors[] = 'Source report must not change llms.';
        }
        if ((bool) ($sourceReport['index_strategy_changed'] ?? true) !== false) {
            $errors[] = 'Source report must not change index strategy.';
        }
        if ((int) data_get($sourceReport, 'production_live_assessment.runtime_shell_count', 0) < 1) {
            $errors[] = 'Source report must include runtime_shell_count evidence.';
        }

        return $errors;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<list<string>>
     */
    private function chunks(array $slugs, int $chunkSize): array
    {
        return array_values(array_chunk($slugs, $chunkSize));
    }

    /**
     * @param  list<list<string>>  $chunks
     * @return list<array<string, mixed>>
     */
    private function executionChunks(array $chunks, int $chunkSize): array
    {
        return array_map(function (array $chunk, int $index) use ($chunkSize): array {
            $chunkIndex = $index + 1;
            $chunkSlugs = implode(',', $chunk);

            return [
                'chunk_index' => $chunkIndex,
                'candidate_count' => count($chunk),
                'candidate_slugs_sha256' => $this->slugListSha256($chunk),
                'candidate_slugs' => $chunk,
                'dry_run_command' => 'php backend/artisan career:import-selected-display-assets --file=<reviewed_workbook.xlsx> --manifest=<validated_full_upload_plan.json> --manifest-chunk-size='.$chunkSize.' --manifest-chunk-index='.$chunkIndex.' --dry-run --json',
                'force_command_requires_explicit_approval' => 'php backend/artisan career:import-selected-display-assets --file=<reviewed_workbook.xlsx> --manifest=<validated_full_upload_plan.json> --manifest-chunk-size='.$chunkSize.' --manifest-chunk-index='.$chunkIndex.' --force --json',
                'post_import_cache_refresh_command_requires_explicit_approval' => 'php backend/artisan career:warm-public-authority-cache --job-detail-slugs='.$chunkSlugs.' --job-detail-locales=zh-CN --forget-job-detail --job-detail-only --json',
            ];
        }, $chunks, array_keys($chunks));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function slugListSha256(array $slugs): string
    {
        return hash('sha256', json_encode(array_values($slugs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  array<string, mixed>  $sourceReport
     * @return array<string, mixed>
     */
    private function sourceReportSummary(string $sourceReportPath, array $sourceReport): array
    {
        return [
            'path' => $sourceReportPath,
            'sha256' => hash_file('sha256', $sourceReportPath) ?: null,
            'validator_version' => (string) ($sourceReport['validator_version'] ?? ''),
            'decision' => (string) ($sourceReport['decision'] ?? ''),
            'live_gate_decision' => (string) data_get($sourceReport, 'live_gate.decision', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        CareerCliArtifactPathGuard::writeJsonOutput($this->option('output'), $report);

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line(is_string($json) ? $json : '{}');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
