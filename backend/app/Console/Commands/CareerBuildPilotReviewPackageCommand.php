<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class CareerBuildPilotReviewPackageCommand extends Command
{
    protected $signature = 'career:build-pilot-review-package
        {--slugs= : Comma-separated exact career slugs}
        {--output= : Optional private JSON output path}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Build deterministic bilingual career review targets without binding evidence or changing public state.';

    public function handle(CareerPilotReviewEvidenceBridge $bridge): int
    {
        try {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('slugs')))));
            $package = $bridge->buildPackage($slugs);
            $output = trim((string) $this->option('output'));
            if ($output !== '') {
                $this->writePrivatePackage($output, $package);
            }

            $summary = [
                'status' => 'PASS_CAREER_PILOT_REVIEW_PACKAGE',
                ...$package,
                'output_path' => $output !== '' ? $output : null,
                'database_writes' => 0,
                'review_evidence_bound' => false,
                'publishes' => false,
                'changes_indexability' => false,
                'submits_search_urls' => false,
            ];

            return $this->finish($summary);
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'HOLD_CAREER_PILOT_REVIEW_PACKAGE',
                'error' => (bool) $this->option('json')
                    ? 'Career pilot review package generation failed.'
                    : $throwable->getMessage(),
                'database_writes' => 0,
                'review_evidence_bound' => false,
                'publishes' => false,
                'changes_indexability' => false,
                'submits_search_urls' => false,
            ], self::FAILURE);
        }
    }

    /** @param array<string,mixed> $package */
    private function writePrivatePackage(string $path, array $package): void
    {
        if (str_contains($path, "\0") || file_exists($path)) {
            throw new \RuntimeException('Career pilot review package output path is invalid.');
        }

        try {
            $encoded = json_encode($package, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException) {
            throw new \RuntimeException('Career pilot review package could not be encoded.');
        }

        $previousUmask = umask(0077);
        try {
            if (file_put_contents($path, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException('Career pilot review package output could not be written.');
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
            foreach (['status', 'scope_identity', 'target_count', 'target_set_sha256', 'package_sha256', 'output_path'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $this->line($key.'='.(string) ($payload[$key] ?? ''));
                }
            }
        }

        return $exitCode;
    }
}
