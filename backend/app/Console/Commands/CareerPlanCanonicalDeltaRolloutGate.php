<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerDeltaRolloutGatePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalDeltaRolloutGate extends Command
{
    protected $signature = 'career:plan-canonical-delta-rollout-gate
        {--manifest= : Required Career 51-delta rollout manifest JSON artifact}
        {--target= : Optional rollout target key; supports detail_ready_1048}
        {--target-public-total= : Target public total after delta rollout}
        {--expect-delta-count= : Expected delta promotion slug count}
        {--json : Emit JSON output}
        {--output= : Optional output path for delta rollout gate JSON}';

    protected $description = 'Plan read-only Career 51-delta rollout gate semantics without executing rollout.';

    public function handle(): int
    {
        try {
            $manifestPath = $this->requiredOption('manifest');
            $manifest = $this->readJson($manifestPath);
            $target = $this->target($manifest);
            $payload = app(CareerDeltaRolloutGatePlanner::class)->plan(
                manifest: $manifest,
                targetPublicTotal: $this->positiveIntOption('target-public-total', $this->defaultTargetPublicTotal($target)),
                expectedDeltaCount: $this->positiveIntOption('expect-delta-count', $this->defaultExpectedDeltaCount($target)),
                manifestPath: $manifestPath,
                target: $target,
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerDeltaRolloutGatePlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'target' => 'career_80_delta',
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_apply_allowed' => false,
                'rollout_dry_run_executed' => false,
                'rollout_apply_executed' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
                'next_required_action' => 'FIX_DELTA_ROLLOUT_GATE_BLOCKERS',
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
     * @param  array<string, mixed>  $manifest
     */
    private function target(array $manifest): ?string
    {
        $target = trim((string) ($this->option('target') ?? ''));
        if ($target !== '') {
            return $target;
        }

        $candidate = $manifest['target_key'] ?? $manifest['target'] ?? null;

        return is_string($candidate) && trim($candidate) !== '' ? $candidate : null;
    }

    private function defaultTargetPublicTotal(?string $target): int
    {
        return $target === 'detail_ready_1048' ? 1048 : 80;
    }

    private function defaultExpectedDeltaCount(?string $target): int
    {
        return $target === 'detail_ready_1048' ? 1018 : 51;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('delta_rollout_manifest_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('delta_rollout_manifest_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('delta_rollout_manifest_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('delta_rollout_manifest_artifact_shape_invalid');
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
            $this->line('delta_slug_count='.(string) ($payload['delta_slug_count'] ?? 0));
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'delta_rollout_gate_error';
        $key = trim($key, '_');

        return $key === '' ? 'delta_rollout_gate_error' : $key;
    }
}
