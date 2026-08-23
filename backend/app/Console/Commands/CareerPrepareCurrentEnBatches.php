<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerCurrentEnBatchPreparer;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerPrepareCurrentEnBatches extends Command
{
    protected $signature = 'career:prepare-current-en-batches
        {--source= : Read-only canonical ten-block root}
        {--output= : Required empty task temp directory}
        {--batch-size=50 : Deterministic target batch size}
        {--base-sha= : Exact 40-character origin/main SHA}
        {--dry-run : Required; this command has no runtime write mode}';

    protected $description = 'Prepare deterministic read-only English Career Current candidate projections and batches';

    public function handle(CareerCurrentEnBatchPreparer $preparer): int
    {
        ini_set('memory_limit', '2048M');
        try {
            if (! (bool) $this->option('dry-run')) {
                throw new CareerTenBlockCompileFailure('CURRENT_EN_DRY_RUN_REQUIRED');
            }
            $result = $preparer->prepare(
                trim((string) $this->option('source')),
                trim((string) $this->option('output')),
                (int) $this->option('batch-size'),
                trim((string) $this->option('base-sha')),
                base_path(),
            );
            $this->line((string) json_encode([
                'status' => 'PASS_CURRENT_EN_BATCH_PREPARE',
                'manifest' => $result['manifest'],
                'report' => $result['report'],
                'current_package_writes' => 0,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'pointer_writes' => 0,
                'sitemap_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CURRENT_EN_BATCH_PREPARE',
                'safe_error_code' => $throwable instanceof CareerTenBlockCompileFailure
                    ? $throwable->safeCode : 'CURRENT_EN_UNEXPECTED_FAILURE',
                'current_package_writes' => 0,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'pointer_writes' => 0,
                'sitemap_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
