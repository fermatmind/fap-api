<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCompiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Illuminate\Console\Command;
use Throwable;

final class CareerCurrentCandidateCompile extends Command
{
    protected $signature = 'career:current-candidate-compile
        {slug : Exact canonical slug}
        {--source-root= : Read-only ten-block root}
        {--lookup= : Read-only career lookup JSON}
        {--evidence= : Optional read-only evidence fixture/registry}
        {--baseline-assets= : Current assets.jsonl used only as the compatibility shell}
        {--output-root= : Explicit existing task temp directory for canonical IR/row/receipt}';

    protected $description = 'Strict deterministic dry compile of one ten-block Career candidate without runtime writes';

    public function handle(CareerTenBlockCompiler $compiler): int
    {
        try {
            $slug = trim((string) $this->argument('slug'));
            $sourceRoot = trim((string) $this->option('source-root'));
            $lookup = trim((string) $this->option('lookup'));
            $evidence = trim((string) $this->option('evidence'));
            $baseline = trim((string) $this->option('baseline-assets'));
            if ($sourceRoot === '' || $lookup === '' || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_COMMAND_INPUT_INVALID');
            }
            if ($baseline === '') {
                $baseline = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/assets.jsonl');
            }
            $result = $compiler->compile(
                $sourceRoot,
                $slug,
                $lookup,
                $baseline,
                $evidence === '' ? null : $evidence,
            );
            $outputRoot = trim((string) $this->option('output-root'));
            if ($outputRoot !== '') {
                $this->writeOutput($outputRoot, $result);
            }
            $this->line((string) json_encode([
                'status' => $result['receipt']['publication_eligible'] ? 'PASS_TEN_BLOCK_DRY_COMPILE' : 'BLOCKED_TEN_BLOCK_DRY_COMPILE',
                'receipt' => $result['receipt'],
                'output_written' => $outputRoot !== '',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_TEN_BLOCK_DRY_COMPILE',
                'safe_error_code' => $throwable instanceof CareerTenBlockCompileFailure
                    ? $throwable->safeCode
                    : 'TEN_BLOCK_UNEXPECTED_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    /** @param array{ir:array<string,mixed>,row:?array<string,mixed>,receipt:array<string,mixed>} $result */
    private function writeOutput(string $root, array $result): void
    {
        if (is_link($root)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_ROOT_FORBIDDEN');
        }
        $resolved = realpath($root);
        $tempRoot = realpath(sys_get_temp_dir());
        $sharedTempRoot = realpath('/tmp');
        $currentRoot = realpath(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH));
        if ($resolved === false || ! is_dir($resolved) || $tempRoot === false
            || (! str_starts_with($resolved.'/', rtrim($tempRoot, '/').'/')
                && ($sharedTempRoot === false || ! str_starts_with($resolved.'/', rtrim($sharedTempRoot, '/').'/')))
            || ($currentRoot !== false && str_starts_with($resolved.'/', rtrim($currentRoot, '/').'/'))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_ROOT_FORBIDDEN');
        }
        $payloads = [
            'canonical-ir.json' => CareerCurrentAuthorityPackage::encodePrettyCanonical($result['ir'])."\n",
            'compile-receipt.json' => CareerCurrentAuthorityPackage::encodePrettyCanonical($result['receipt'])."\n",
        ];
        if ($result['row'] !== null) {
            $payloads['candidate-row.json'] = CareerCurrentAuthorityPackage::encodeCanonical($result['row'])."\n";
        }
        foreach ($payloads as $file => $payload) {
            $temporary = $resolved.'/.'.$file.'.tmp';
            if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! rename($temporary, $resolved.'/'.$file)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_WRITE_FAILED');
            }
        }
    }
}
