<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Review\CareerSearchEntryQualityBatchPlanner;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class CareerBuildSearchEntryQualityBatchCommand extends Command
{
    protected $signature = 'career:build-search-entry-quality-batch
        {--output= : Optional private JSON output path}
        {--expected-package= : Optional exact prior package to verify without writing}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Build or verify the exact bounded bilingual Career search-entry quality package without writes.';

    public function handle(CareerSearchEntryQualityBatchPlanner $planner): int
    {
        try {
            $expectedPath = trim((string) $this->option('expected-package'));
            $package = $expectedPath === ''
                ? $planner->build()
                : $planner->verify($this->readExpectedPackage($expectedPath));
            $output = trim((string) $this->option('output'));
            if ($output !== '') {
                $this->writePrivatePackage($output, $package);
            }

            return $this->finish([
                'status' => 'PASS_CAREER_SEARCH_ENTRY_QUALITY_BATCH',
                ...$package,
                'output_path' => $output !== '' ? $output : null,
                'expected_package_verified' => $expectedPath !== '',
            ]);
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'HOLD_CAREER_SEARCH_ENTRY_QUALITY_BATCH',
                'error' => (bool) $this->option('json')
                    ? 'Career search-entry quality batch failed closed.'
                    : $throwable->getMessage(),
                'negative_guarantees' => $this->negativeGuarantees(),
            ], self::FAILURE);
        }
    }

    /** @return array<string,mixed> */
    private function readExpectedPackage(string $path): array
    {
        if (str_contains($path, "\0") || ! is_file($path)) {
            throw new \RuntimeException('Expected Career quality package path is invalid.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Expected Career quality package is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $package */
    private function writePrivatePackage(string $path, array $package): void
    {
        if (str_contains($path, "\0") || file_exists($path)) {
            throw new \RuntimeException('Career quality package output path is invalid.');
        }
        try {
            $encoded = json_encode($package, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException) {
            throw new \RuntimeException('Career quality package could not be encoded.');
        }
        $previousUmask = umask(0077);
        try {
            if (file_put_contents($path, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException('Career quality package output could not be written.');
            }
        } finally {
            umask($previousUmask);
        }
        @chmod($path, 0600);
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ([
                'status',
                'error',
                'candidate_count',
                'bilingual_url_count',
                'target_set_sha256',
                'package_sha256',
                'quality_package_sha256',
                'output_path',
            ] as $key) {
                if (array_key_exists($key, $payload)) {
                    $this->line($key.'='.(string) ($payload[$key] ?? ''));
                }
            }
        }

        return $exitCode;
    }

    /** @return array<string,int> */
    private function negativeGuarantees(): array
    {
        return [
            'database_writes' => 0,
            'cms_writes' => 0,
            'cache_writes' => 0,
            'queue_dispatches' => 0,
            'publication_writes' => 0,
            'indexability_writes' => 0,
            'sitemap_writes' => 0,
            'llms_writes' => 0,
            'search_channel_actions' => 0,
            'url_submissions' => 0,
            'deploys' => 0,
            'held_slug_releases' => 0,
        ];
    }
}
