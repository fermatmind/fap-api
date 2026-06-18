<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\SalaryAssets\CareerSalaryAssetImportService;
use Illuminate\Console\Command;
use Throwable;

final class CareerImportSalaryAssetsPreview extends Command
{
    protected $signature = 'career:salary-assets-import-preview
        {--file= : Absolute path to PASS career_job_salary_assets_1046_v3_6 JSONL}
        {--expected-sha256= : Optional expected SHA-256 for the input JSONL artifact}
        {--slugs= : Optional comma-separated preview slug subset}
        {--all-slugs-from-file : Dry-run every slug found in the source JSONL instead of the configured preview allowlist}
        {--confirm-full-staging-preview : Explicitly confirm that --force may write every slug found in the source JSONL to staging_preview}
        {--approve-staging-preview : Validate or transition existing staging_preview salary asset rows to approved using an approval manifest}
        {--approval-manifest= : Absolute path to the editorial approval manifest JSON}
        {--expected-approval-manifest-sha256= : Optional expected SHA-256 for the approval manifest artifact}
        {--confirm-approval-transition : Explicitly confirm that --approve-staging-preview may update rows to approved}
        {--production-import : Validate or transition approved salary asset rows to production_imported using the approval manifest and exact operator approval}
        {--operator-approval= : Exact operator approval text required for --production-import}
        {--confirm-production-import : Explicitly confirm that --production-import may update approved rows to production_imported}
        {--dry-run : Validate only; this is the default when --force is not supplied}
        {--force : Write staging_preview rows for allowlisted preview slugs only}
        {--json : Emit machine-readable JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Dry-run or staging-preview import of PASS v3.6 career salary asset rows for the configured staging preview allowlist.';

    public function __construct(
        private readonly CareerSalaryAssetImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ((bool) $this->option('production-import')) {
                if ((bool) $this->option('force') || (bool) $this->option('approve-staging-preview')) {
                    return $this->finish([
                        'decision' => 'fail',
                        'errors' => ['--production-import cannot be combined with --force or --approve-staging-preview.'],
                    ], false);
                }

                $manifest = trim((string) $this->option('approval-manifest'));
                if ($manifest === '') {
                    return $this->finish([
                        'decision' => 'fail',
                        'errors' => ['--approval-manifest is required with --production-import.'],
                    ], false);
                }

                $report = $this->importService->productionImportApproved(
                    $manifest,
                    trim((string) $this->option('expected-approval-manifest-sha256')) ?: null,
                    trim((string) $this->option('operator-approval')),
                    (bool) $this->option('confirm-production-import'),
                );

                return $this->finish($report, ($report['decision'] ?? null) === 'pass');
            }

            if ((bool) $this->option('approve-staging-preview')) {
                if ((bool) $this->option('force')) {
                    return $this->finish([
                        'decision' => 'fail',
                        'errors' => ['--approve-staging-preview and --force cannot be used together.'],
                    ], false);
                }

                $manifest = trim((string) $this->option('approval-manifest'));
                if ($manifest === '') {
                    return $this->finish([
                        'decision' => 'fail',
                        'errors' => ['--approval-manifest is required with --approve-staging-preview.'],
                    ], false);
                }

                $report = $this->importService->approveStagingPreview(
                    $manifest,
                    trim((string) $this->option('expected-approval-manifest-sha256')) ?: null,
                    (bool) $this->option('confirm-approval-transition'),
                );

                return $this->finish($report, ($report['decision'] ?? null) === 'pass');
            }

            $file = trim((string) $this->option('file'));
            if ($file === '') {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--file is required.'],
                ], false);
            }

            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');
            if ($force && $dryRun) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--dry-run and --force cannot be used together.'],
                ], false);
            }
            $allSlugsFromFile = (bool) $this->option('all-slugs-from-file');
            $confirmFullStagingPreview = (bool) $this->option('confirm-full-staging-preview');
            if ($force && $allSlugsFromFile && ! $confirmFullStagingPreview) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--force --all-slugs-from-file requires --confirm-full-staging-preview.'],
                ], false);
            }

            $slugs = $this->requestedSlugs();
            $expectedSha256 = trim((string) $this->option('expected-sha256')) ?: null;
            $report = $force
                ? $this->importService->importStagingPreview($file, $slugs, $expectedSha256, $allSlugsFromFile)
                : $this->importService->validateFile($file, $slugs, $expectedSha256, $allSlugsFromFile);

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
            $this->info('career salary asset preview import validation passed.');
        } else {
            $this->error('career salary asset preview import validation failed.');
            foreach ((array) ($report['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
