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
        {--slugs= : Optional comma-separated preview slug subset}
        {--dry-run : Validate only; this is the default when --force is not supplied}
        {--force : Write staging_preview rows for allowlisted preview slugs only}
        {--json : Emit machine-readable JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Dry-run or staging-preview import of PASS v3.6 career salary asset rows for the 10-slug allowlist.';

    public function __construct(
        private readonly CareerSalaryAssetImportService $importService,
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
            if ($force && $dryRun) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--dry-run and --force cannot be used together.'],
                ], false);
            }

            $slugs = $this->requestedSlugs();
            $report = $force
                ? $this->importService->importStagingPreview($file, $slugs)
                : $this->importService->validateFile($file, $slugs);

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
