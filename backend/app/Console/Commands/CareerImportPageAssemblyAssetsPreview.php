<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJobPageAssemblyAsset;
use App\Services\Career\PageAssemblyAssets\CareerPageAssemblyImportService;
use Illuminate\Console\Command;
use Throwable;

final class CareerImportPageAssemblyAssetsPreview extends Command
{
    protected $signature = 'career:page-assembly-assets-import-preview
        {--file= : Absolute path to PASS career_page_assembly_v1 JSONL}
        {--expected-sha256= : Optional expected SHA-256 for the input JSONL artifact}
        {--slugs= : Optional comma-separated preview slug subset}
        {--all-slugs-from-file : Dry-run every slug found in the source JSONL instead of the configured preview allowlist}
        {--confirm-full-staging-preview : Explicitly confirm that --force may write every slug found in the source JSONL to staging_preview}
        {--confirm-approved-transition : Explicitly confirm that --force may mark validated rows as approved}
        {--confirm-production-import : Explicitly confirm that production import preflight or --force may import validated, approved page assembly rows into production_imported}
        {--approval-manifest= : Approval manifest JSON produced by the editorial review package}
        {--approval-manifest-sha256= : Optional expected SHA-256 for the approval manifest}
        {--editorial-review-report= : Editorial review JSON report that authorizes the approved transition}
        {--editorial-review-sha256= : Optional expected SHA-256 for the editorial review report}
        {--dry-run : Validate only; do not write staging or production rows}
        {--force : Write rows only when --status=staging_preview, perform explicit approved transition, or perform explicit production import}
        {--status=staging_preview : Target import status; staging_preview, approved, and production_imported are supported through their explicit gates}
        {--json : Emit machine-readable JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Validate or write PASS v1 career page assembly asset rows through staging, approval, or explicit production gates.';

    public function __construct(
        private readonly CareerPageAssemblyImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $file = trim((string) $this->option('file'));
            if ($file === '') {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--file is required.'],
                ], false);
            }

            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');
            $status = trim((string) $this->option('status')) ?: CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW;

            if ($force && $dryRun) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--force cannot be combined with --dry-run.'],
                ], false);
            }

            if (! in_array($status, [
                CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
                CareerJobPageAssemblyAsset::STATUS_APPROVED,
                CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            ], true)) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['Only --status=staging_preview, --status=approved, or --status=production_imported is supported.'],
                ], false);
            }

            if (
                $force
                && $status === CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW
                && (bool) $this->option('all-slugs-from-file')
                && ! (bool) $this->option('confirm-full-staging-preview')
            ) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--force --all-slugs-from-file requires --confirm-full-staging-preview.'],
                ], false);
            }

            if ($force && $status === CareerJobPageAssemblyAsset::STATUS_APPROVED) {
                $report = $this->importService->approveReviewedAssets(
                    $file,
                    $this->requestedSlugs(),
                    trim((string) $this->option('expected-sha256')) ?: null,
                    (bool) $this->option('all-slugs-from-file'),
                    trim((string) $this->option('approval-manifest')),
                    trim((string) $this->option('approval-manifest-sha256')) ?: null,
                    trim((string) $this->option('editorial-review-report')),
                    trim((string) $this->option('editorial-review-sha256')) ?: null,
                    (bool) $this->option('confirm-approved-transition'),
                );
            } elseif ($status === CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED && ($force || $dryRun)) {
                $report = $this->importService->importApprovedAssetsToProduction(
                    $file,
                    $this->requestedSlugs(),
                    trim((string) $this->option('expected-sha256')) ?: null,
                    (bool) $this->option('all-slugs-from-file'),
                    trim((string) $this->option('approval-manifest')),
                    trim((string) $this->option('approval-manifest-sha256')) ?: null,
                    trim((string) $this->option('editorial-review-report')),
                    trim((string) $this->option('editorial-review-sha256')) ?: null,
                    (bool) $this->option('confirm-production-import'),
                    $force,
                );
            } else {
                $report = $force
                    ? $this->importService->importStagingPreview(
                        $file,
                        $this->requestedSlugs(),
                        trim((string) $this->option('expected-sha256')) ?: null,
                        (bool) $this->option('all-slugs-from-file'),
                        $status,
                    )
                    : $this->importService->validateFile(
                        $file,
                        $this->requestedSlugs(),
                        trim((string) $this->option('expected-sha256')) ?: null,
                        (bool) $this->option('all-slugs-from-file'),
                    );
            }

            return $this->finish($report, ($report['decision'] ?? null) === 'pass');
        } catch (Throwable $throwable) {
            return $this->finish([
                'decision' => 'fail',
                'errors' => [$throwable->getMessage()],
            ], false);
        }
    }

    /**
     * @return list<string>|null
     */
    private function requestedSlugs(): ?array
    {
        $raw = trim((string) $this->option('slugs'));
        if ($raw === '') {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (string $slug): string => strtolower(trim($slug)), explode(',', $raw)),
            static fn (string $slug): bool => $slug !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        $outputPath = trim((string) $this->option('output'));
        if ($outputPath !== '') {
            $directory = dirname($outputPath);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($success) {
            $this->info('career page assembly asset preview import validation passed.');
        } else {
            $this->error('career page assembly asset preview import validation failed.');
            foreach ((array) ($report['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
