<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerDeltaRolloutManifestPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerGenerateCanonicalDeltaRolloutManifest extends Command
{
    protected $signature = 'career:generate-canonical-delta-rollout-manifest
        {--target-delta= : Required Career 80 target delta JSON artifact}
        {--candidate-prep-plan= : Optional runtime candidate prep plan JSON artifact}
        {--target= : Optional rollout target key; supports detail_ready_1048}
        {--target-public-total= : Target public total after delta rollout}
        {--expect-delta-count= : Expected delta promotion slug count}
        {--batch-id= : Optional batch id; defaults to career_80_delta_canonical_001}
        {--locales=en,zh : Comma-separated locale list}
        {--json : Emit JSON output}
        {--output= : Optional output path for delta rollout manifest JSON}';

    protected $description = 'Generate a read-only Career 51-delta canonical rollout manifest with 80-total accounting.';

    public function handle(): int
    {
        try {
            $targetDeltaPath = $this->requiredOption('target-delta');
            $candidatePrepPlanPath = $this->pathOption('candidate-prep-plan');
            $targetDelta = $this->readJson($targetDeltaPath, 'target_delta');
            $target = $this->target($targetDelta);
            $targetPublicTotal = $this->positiveIntOption('target-public-total', $this->defaultTargetPublicTotal($target));
            $expectedDeltaCount = $this->positiveIntOption('expect-delta-count', $this->defaultExpectedDeltaCount($target));
            $payload = app(CareerDeltaRolloutManifestPlanner::class)->plan(
                targetDeltaPlan: $targetDelta,
                candidatePrepPlan: $candidatePrepPlanPath === null ? null : $this->readJson($candidatePrepPlanPath, 'candidate_prep_plan'),
                targetPublicTotal: $targetPublicTotal,
                expectedDeltaCount: $expectedDeltaCount,
                locales: $this->locales(),
                batchId: $this->batchId($targetPublicTotal),
                targetDeltaPath: $targetDeltaPath,
                candidatePrepPlanPath: $candidatePrepPlanPath,
                target: $target,
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerDeltaRolloutManifestPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'target' => 'career_80_delta',
                'read_only' => true,
                'writes_database' => false,
                'rollout_allowed' => false,
                'dry_run_allowed' => false,
                'apply_allowed' => false,
                'rollout_dry_run_executed' => false,
                'rollout_apply_executed' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
                'next_required_action' => 'FIX_DELTA_ROLLOUT_MANIFEST_BLOCKERS',
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
    private function locales(): array
    {
        $raw = trim((string) ($this->option('locales') ?? 'en,zh'));
        $locales = [];
        foreach (explode(',', $raw) as $locale) {
            $normalized = strtolower(trim($locale));
            if ($normalized !== '' && ! in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        sort($locales);
        if ($locales === []) {
            throw new RuntimeException('locales_missing');
        }

        return $locales;
    }

    private function batchId(int $targetPublicTotal): string
    {
        $batchId = trim((string) ($this->option('batch-id') ?? ''));
        if ($batchId !== '') {
            return $batchId;
        }

        return $targetPublicTotal === 80 ? 'career_80_delta_canonical_001' : 'career_'.$targetPublicTotal.'_delta_canonical_001';
    }

    /**
     * @param  array<string, mixed>  $targetDelta
     */
    private function target(array $targetDelta): ?string
    {
        $target = trim((string) ($this->option('target') ?? ''));
        if ($target !== '') {
            return $target;
        }

        $candidate = $targetDelta['target_key'] ?? $targetDelta['target'] ?? null;

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
            $this->line('delta_slug_count='.(string) ($payload['delta_slug_count'] ?? 0));
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'delta_rollout_manifest_error';
        $key = trim($key, '_');

        return $key === '' ? 'delta_rollout_manifest_error' : $key;
    }
}
