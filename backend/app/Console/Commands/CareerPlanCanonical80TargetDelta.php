<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\Career80TargetDeltaPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonical80TargetDelta extends Command
{
    protected $signature = 'career:plan-canonical-80-target-delta
        {--readiness= : Required Career 80 readiness JSON artifact}
        {--delta-slugs= : Required reviewed 51-slug delta artifact JSON path}
        {--runtime-pool= : Optional runtime candidate pool JSON artifact for already-published evidence}
        {--target=80 : Target public total}
        {--json : Emit JSON output}
        {--output= : Optional output path for target delta plan JSON}';

    protected $description = 'Plan the read-only Career 80 target decomposition into published baseline and delta promotion slugs.';

    public function handle(): int
    {
        try {
            $readinessPath = $this->requiredOption('readiness');
            $deltaPath = $this->requiredOption('delta-slugs');
            $runtimePoolPath = $this->pathOption('runtime-pool');
            $target = $this->positiveIntOption('target', 80);

            $result = app(Career80TargetDeltaPlanner::class)->plan(
                readiness: $this->readJson($readinessPath, 'readiness'),
                deltaArtifact: $this->readJson($deltaPath, 'delta_slug'),
                runtimePool: $runtimePoolPath === null ? null : $this->readJson($runtimePoolPath, 'runtime_pool'),
                target: $target,
            )->toArray();

            return $this->finish($result, ($result['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => Career80TargetDeltaPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
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
        $output = $this->option('output');
        if (is_string($output) && trim($output) !== '') {
            File::put(trim($output), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->line('status='.($payload['status'] ?? 'unknown'));
            $this->line('published_baseline_count='.(string) ($payload['published_baseline_count'] ?? 0));
            $this->line('delta_promotion_count='.(string) ($payload['delta_promotion_count'] ?? 0));
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'target_delta_error';
        $key = trim($key, '_');

        return $key === '' ? 'target_delta_error' : $key;
    }
}
