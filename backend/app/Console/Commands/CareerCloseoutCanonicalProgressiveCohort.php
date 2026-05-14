<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerProgressiveCohortCloseoutPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerCloseoutCanonicalProgressiveCohort extends Command
{
    protected $signature = 'career:closeout-canonical-progressive-cohort
        {--live-acceptance= : Required progressive live acceptance JSON artifact}
        {--target-public-total= : Target public total; defaults to live acceptance target_public_total}
        {--baseline-slugs= : Optional current/baseline slug artifact path to record}
        {--delta-slugs= : Optional delta slug artifact path to record}
        {--total-slugs= : Required total accepted slug artifact path to record}
        {--json : Emit JSON output}
        {--output= : Optional output path for closeout JSON}';

    protected $description = 'Create a read-only closeout artifact for accepted Career progressive cohorts.';

    public function handle(): int
    {
        try {
            $liveAcceptancePath = $this->requiredOption('live-acceptance');
            $payload = app(CareerProgressiveCohortCloseoutPlanner::class)->closeout(
                liveAcceptance: $this->readJson($liveAcceptancePath, 'live_acceptance'),
                targetPublicTotal: $this->targetPublicTotal(),
                liveAcceptancePath: $liveAcceptancePath,
                baselineSlugsPath: $this->pathOption('baseline-slugs'),
                deltaSlugsPath: $this->pathOption('delta-slugs'),
                totalSlugsPath: $this->pathOption('total-slugs'),
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'complete' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerProgressiveCohortCloseoutPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'accepted' => false,
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_allowed' => false,
                'live_crawl_executed' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
                'sidecars' => [],
                'next_required_action' => 'FIX_PROGRESSIVE_COHORT_CLOSEOUT_INPUTS',
            ], self::FAILURE);
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $value;
    }

    private function pathOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }

    private function targetPublicTotal(): ?int
    {
        $value = trim((string) ($this->option('target-public-total') ?? ''));
        if ($value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($int) || $int < 1) {
            throw new RuntimeException('target_public_total_invalid');
        }

        return $int;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $kind): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($kind.'_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException($kind.'_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($kind.'_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($kind.'_artifact_shape_invalid');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed_to_encode_json_payload');

            return self::FAILURE;
        }

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            File::put($outputPath, $encoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
            $this->line('accepted='.(($payload['accepted'] ?? false) ? 'true' : 'false'));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'career_progressive_cohort_closeout_error';
        $key = trim($key, '_');

        return $key === '' ? 'career_progressive_cohort_closeout_error' : $key;
    }
}
