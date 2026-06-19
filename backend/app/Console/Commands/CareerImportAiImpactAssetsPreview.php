<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\AiImpactAssets\CareerAiImpactAssetImportService;
use Illuminate\Console\Command;
use Throwable;

final class CareerImportAiImpactAssetsPreview extends Command
{
    protected $signature = 'career:ai-impact-assets-import-preview
        {--file= : Absolute path to PASS career_risk_future_ai_impact v5 assets JSONL}
        {--expected-sha256= : Optional expected SHA-256 for the input JSONL artifact}
        {--slugs= : Optional comma-separated preview slug subset}
        {--all-slugs-from-file : Dry-run every slug found in the source JSONL instead of the configured preview allowlist}
        {--dry-run : Validate only; do not write staging or production rows}
        {--force : Write rows only when --status=staging_preview and validation passes}
        {--status=staging_preview : Target import status; only staging_preview is supported by this command}
        {--json : Emit machine-readable JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Validate or write PASS v5 career AI impact asset rows for the configured staging preview contract.';

    public function __construct(
        private readonly CareerAiImpactAssetImportService $importService,
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
            $status = trim((string) $this->option('status')) ?: 'staging_preview';

            if ($force && $dryRun) {
                return $this->finish([
                    'decision' => 'fail',
                    'errors' => ['--force cannot be combined with --dry-run.'],
                ], false);
            }

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
            $this->info('career AI impact asset preview import validation passed.');
        } else {
            $this->error('career AI impact asset preview import validation failed.');
            foreach ((array) ($report['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
