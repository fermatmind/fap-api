<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerCurrentZhBatchMaterializer;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerMaterializeCurrentZhBatch extends Command
{
    protected $signature = 'career:materialize-current-zh-batch
        {--source= : Read-only canonical ten-block root}
        {--plan= : Deterministic C3.1 plan root}
        {--batch-id= : Exact batch-NNN identity}
        {--expected-base-assets-sha256= : Exact current assets.jsonl SHA-256}
        {--output= : Required empty task temp directory in dry-run mode}
        {--dry-run : Compile and validate a candidate without changing Current}
        {--write : Atomically update the unique repository Current package}';

    protected $description = 'Materialize one ordered zh-CN Career Current authority batch without runtime writes';

    public function handle(CareerCurrentZhBatchMaterializer $materializer): int
    {
        ini_set('memory_limit', '1024M');
        try {
            $dryRun = (bool) $this->option('dry-run');
            $write = (bool) $this->option('write');
            if ($dryRun === $write) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_MATERIALIZE_MODE_INVALID');
            }
            $result = $materializer->materialize(
                trim((string) $this->option('source')),
                trim((string) $this->option('plan')),
                trim((string) $this->option('batch-id')),
                trim((string) $this->option('expected-base-assets-sha256')),
                base_path(),
                $dryRun ? trim((string) $this->option('output')) : null,
                $write,
                app()->environment(),
            );
            $this->line((string) json_encode($result['report'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CURRENT_ZH_BATCH_MATERIALIZE',
                'safe_error_code' => $throwable instanceof CareerTenBlockCompileFailure
                    ? $throwable->safeCode : 'CURRENT_ZH_MATERIALIZE_UNEXPECTED_FAILURE',
                'current_package_writes' => 0,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'pointer_writes' => 0,
                'sitemap_writes' => 0,
                'discoverability_writes' => 0,
                'llms_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
