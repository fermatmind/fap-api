<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerTenBlockBatchNormalizer;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Illuminate\Console\Command;
use Throwable;

final class CareerTenBlockNormalizeBatch extends Command
{
    protected $signature = 'career:ten-block-normalize-batch
        {--source-root= : Read-only canonical ten-block root}
        {--lookup= : Read-only canonical lookup JSON}
        {--output-root= : Optional existing task temp directory for manifest and receipt}';

    protected $description = 'Read-only full-coverage Career ten-block profile normalization and link canonicalization';

    public function handle(CareerTenBlockBatchNormalizer $normalizer): int
    {
        try {
            $sourceRoot = trim((string) $this->option('source-root'));
            $lookup = trim((string) $this->option('lookup'));
            if ($sourceRoot === '' || $lookup === '') {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_COMMAND_INPUT_INVALID');
            }
            $result = $normalizer->normalize($sourceRoot, $lookup);
            $outputRoot = trim((string) $this->option('output-root'));
            if ($outputRoot !== '') {
                $this->writeOutput($outputRoot, $result);
            }
            $this->line((string) json_encode([
                'status' => 'PASS_TEN_BLOCK_BATCH_NORMALIZE',
                'receipt' => $result['receipt'],
                'output_written' => $outputRoot !== '',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_TEN_BLOCK_BATCH_NORMALIZE',
                'safe_error_code' => $throwable instanceof CareerTenBlockCompileFailure
                    ? $throwable->safeCode : 'TEN_BLOCK_UNEXPECTED_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    /** @param array{manifest:array<string,mixed>,receipt:array<string,mixed>} $result */
    private function writeOutput(string $root, array $result): void
    {
        $resolved = is_link($root) ? false : realpath($root);
        $temp = realpath(sys_get_temp_dir());
        $sharedTemp = realpath('/tmp');
        if ($resolved === false || ! is_dir($resolved) || $temp === false
            || (! str_starts_with($resolved.'/', rtrim($temp, '/').'/')
                && ($sharedTemp === false || ! str_starts_with($resolved.'/', rtrim($sharedTemp, '/').'/')))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_ROOT_FORBIDDEN');
        }
        foreach ([
            'schema-profile-manifest.json' => $result['manifest'],
            'batch-normalize-receipt.json' => $result['receipt'],
        ] as $file => $value) {
            $payload = CareerCurrentAuthorityPackage::encodePrettyCanonical($value)."\n";
            $temporary = $resolved.'/.'.$file.'.tmp';
            if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! rename($temporary, $resolved.'/'.$file)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_WRITE_FAILED');
            }
        }
    }
}
