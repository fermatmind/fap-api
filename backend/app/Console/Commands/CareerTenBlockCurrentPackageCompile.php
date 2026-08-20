<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCurrentPackageCompiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerTenBlockCurrentPackageCompile extends Command
{
    protected $signature = 'career:ten-block-current-package-compile
        {--source-root= : Read-only canonical ten-block root}
        {--lookup= : Read-only canonical lookup JSON}
        {--evidence-root= : Read-only evidence authority root}
        {--output-root= : Required existing task temp directory}
        {--write-current : Atomically install the validated candidate into repository Current authority}';

    protected $description = 'Deterministically compile and validate the 1046-row Career Current candidate package';

    public function handle(
        CareerTenBlockCurrentPackageCompiler $compiler,
        CareerCurrentAuthorityPackage $package,
    ): int {
        ini_set('memory_limit', '1024M');
        $scratch = null;
        try {
            $sourceRoot = trim((string) $this->option('source-root'));
            $lookup = trim((string) $this->option('lookup'));
            $evidenceRoot = trim((string) $this->option('evidence-root'));
            $outputRoot = $this->outputRoot((string) $this->option('output-root'));
            if ($sourceRoot === '' || $lookup === '' || $evidenceRoot === '') {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_COMMAND_INPUT_INVALID');
            }
            $result = $compiler->compile($sourceRoot, $lookup, $evidenceRoot, base_path());
            $this->write($outputRoot.'/assets.jsonl', $result['assets_bytes']);
            $scratch = $outputRoot.'/.package-backend-'.bin2hex(random_bytes(8));
            $packageRoot = $scratch.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
            if (! mkdir($packageRoot, 0700, true)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_WRITE_FAILED');
            }
            $this->write($packageRoot.'/assets.jsonl', $result['assets_bytes']);
            $this->write(
                $packageRoot.'/manifest.json',
                CareerCurrentAuthorityPackage::encodePrettyCanonical($result['manifest_template']),
            );
            $expected = $package->expectedManifest($scratch);
            $manifestBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($expected['manifest']);
            $this->write($packageRoot.'/manifest.json', $manifestBytes);
            $validated = $package->load($scratch);
            $this->write($outputRoot.'/manifest.json', $manifestBytes);
            $result['receipt']['assets_sha256'] = $validated['summary']['assets_sha256'];
            $result['receipt']['manifest_sha256'] = $validated['summary']['manifest_sha256'];
            $result['receipt']['full_asset_set_sha256'] = $validated['summary']['full_asset_set_sha256'];
            $result['receipt']['slug_set_sha256'] = $validated['summary']['slug_set_sha256'];
            $this->writeJson($outputRoot.'/full-compile-receipt.json', $result['receipt']);
            $this->writeJson($outputRoot.'/field-coverage-report.json', $result['field_coverage']);
            $this->writeJson($outputRoot.'/package-diff-report.json', $result['package_diff']);
            $written = false;
            if ((bool) $this->option('write-current')) {
                $this->assertCurrentWriteAllowed();
                $this->installCurrent($result['assets_bytes'], $manifestBytes);
                $package->load(base_path());
                $written = true;
            }
            $this->line((string) json_encode([
                'status' => 'PASS_TEN_BLOCK_CURRENT_PACKAGE_COMPILE',
                'receipt' => $result['receipt'],
                'package_written' => $written,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_TEN_BLOCK_CURRENT_PACKAGE_COMPILE',
                'safe_error_code' => $throwable instanceof CareerTenBlockCompileFailure
                    ? $throwable->safeCode
                    : ($throwable instanceof CareerCurrentAuthorityPackageFailure
                        ? $throwable->safeCode : 'TEN_BLOCK_UNEXPECTED_FAILURE'),
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } finally {
            if (is_string($scratch)) {
                $this->removeScratch($scratch);
            }
        }
    }

    private function outputRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        $temp = realpath(sys_get_temp_dir());
        $sharedTemp = realpath('/tmp');
        if ($resolved === false || ! is_dir($resolved) || $temp === false
            || (! str_starts_with($resolved.'/', rtrim($temp, '/').'/')
                && ($sharedTemp === false || ! str_starts_with($resolved.'/', rtrim($sharedTemp, '/').'/')))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }

    private function assertCurrentWriteAllowed(): void
    {
        $repositoryRoot = dirname(base_path());
        if (! app()->environment(['local', 'testing'])
            || (! is_file($repositoryRoot.'/.git') && ! is_dir($repositoryRoot.'/.git'))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_CURRENT_WRITE_FORBIDDEN');
        }
    }

    private function installCurrent(string $assetsBytes, string $manifestBytes): void
    {
        $root = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH);
        $this->replaceAtomically($root.'/assets.jsonl', $assetsBytes);
        $this->replaceAtomically($root.'/manifest.json', $manifestBytes);
    }

    private function replaceAtomically(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.ten-block-current-');
        if (! is_string($temporary)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_CURRENT_WRITE_FAILED');
        }
        try {
            $mode = fileperms($path);
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== false && ! chmod($temporary, $mode & 0777))
                || ! rename($temporary, $path)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_CURRENT_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function writeJson(string $path, array $value): void
    {
        $this->write($path, CareerCurrentAuthorityPackage::encodePrettyCanonical($value));
    }

    private function write(string $path, string $bytes): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_OUTPUT_WRITE_FAILED');
        }
    }

    private function removeScratch(string $root): void
    {
        if (! is_dir($root) || ! str_contains(basename($root), '.package-backend-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
