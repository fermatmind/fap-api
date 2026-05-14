<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\Career80TotalLiveAcceptancePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerValidateCanonical80TotalLiveAcceptance extends Command
{
    protected $signature = 'career:validate-canonical-80-total-live-acceptance
        {--target-delta= : Required Career 80 target delta plan JSON artifact}
        {--delta-manifest= : Optional Career 51-delta rollout manifest JSON artifact}
        {--live-acceptance= : Optional 80-total live acceptance JSON artifact}
        {--target-public-total=80 : Target public total after delta rollout}
        {--locales=en,zh : Comma-separated target locales}
        {--json : Emit JSON output}
        {--output= : Optional output path for 80-total live acceptance report JSON}';

    protected $description = 'Plan read-only Career 80 total live acceptance accounting without executing live crawl or rollout.';

    public function handle(): int
    {
        try {
            $targetDeltaPath = $this->requiredOption('target-delta');
            $deltaManifestPath = $this->pathOption('delta-manifest');
            $liveAcceptancePath = $this->pathOption('live-acceptance');

            $payload = app(Career80TotalLiveAcceptancePlanner::class)->plan(
                targetDelta: $this->readJson($targetDeltaPath, 'target_delta'),
                deltaManifest: $deltaManifestPath === null ? null : $this->readJson($deltaManifestPath, 'delta_manifest'),
                liveAcceptance: $liveAcceptancePath === null ? null : $this->readJson($liveAcceptancePath, 'live_acceptance'),
                targetPublicTotal: $this->positiveIntOption('target-public-total', 80),
                locales: $this->csvOption('locales', 'en,zh'),
                targetDeltaPath: $targetDeltaPath,
                deltaManifestPath: $deltaManifestPath,
                liveAcceptancePath: $liveAcceptancePath,
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => Career80TotalLiveAcceptancePlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'target' => 'career_80_total',
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
                'next_required_action' => 'FIX_80_TOTAL_LIVE_ACCEPTANCE_INPUTS',
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

    private function positiveIntOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name, string $default): array
    {
        $raw = trim((string) ($this->option($name) ?? $default));
        $values = $raw === '' ? [] : array_map(static fn (string $value): string => trim($value), explode(',', $raw));
        $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        if ($values === []) {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $values;
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
            $this->line('accepted='.(($payload['accepted'] ?? false) ? 'true' : 'false'));
            $this->line('expected_locale_rows='.(string) ($payload['expected_locale_rows'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'career_80_total_live_acceptance_error';
        $key = trim($key, '_');

        return $key === '' ? 'career_80_total_live_acceptance_error' : $key;
    }
}
