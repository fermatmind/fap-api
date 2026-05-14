<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerDeltaRolloutManifestPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerGenerateCanonicalProgressiveRolloutManifest extends Command
{
    protected $signature = 'career:generate-canonical-progressive-rollout-manifest
        {--target-delta= : Required progressive cohort target-delta JSON artifact}
        {--candidate-prep-plan= : Optional runtime candidate prep plan JSON artifact}
        {--current-public-total= : Current accepted public total before rollout}
        {--target-public-total= : Target public total after rollout; defaults to target-delta target_public_total}
        {--expect-delta-count= : Expected delta promotion slug count; defaults to target-delta delta slug count}
        {--target= : Optional target key; defaults to career_<current>_to_<target>_delta}
        {--batch-id= : Optional batch id; defaults to career_<current>_to_<target>_canonical_001}
        {--locales=en,zh : Comma-separated locale list}
        {--json : Emit JSON output}
        {--output= : Optional output path for progressive rollout manifest JSON}';

    protected $description = 'Generate a read-only Career progressive canonical rollout manifest for 300, 800, or 2786 cohorts.';

    public function handle(): int
    {
        try {
            $targetDeltaPath = $this->requiredOption('target-delta');
            $targetDelta = $this->readJson($targetDeltaPath, 'target_delta');
            $candidatePrepPlanPath = $this->pathOption('candidate-prep-plan');
            $currentPublicTotal = $this->currentPublicTotal($targetDelta);
            $targetPublicTotal = $this->targetPublicTotal($targetDelta);
            $expectedDeltaCount = $this->expectedDeltaCount($targetDelta);
            $target = $this->target($currentPublicTotal, $targetPublicTotal);

            $payload = app(CareerDeltaRolloutManifestPlanner::class)->plan(
                targetDeltaPlan: $targetDelta,
                candidatePrepPlan: $candidatePrepPlanPath === null ? null : $this->readJson($candidatePrepPlanPath, 'candidate_prep_plan'),
                targetPublicTotal: $targetPublicTotal,
                expectedDeltaCount: $expectedDeltaCount,
                locales: $this->locales(),
                batchId: $this->batchId($currentPublicTotal, $targetPublicTotal),
                targetDeltaPath: $targetDeltaPath,
                candidatePrepPlanPath: $candidatePrepPlanPath,
                target: $target,
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerDeltaRolloutManifestPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'target' => 'career_progressive_delta',
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

    /**
     * @param  array<string, mixed>  $targetDelta
     */
    private function currentPublicTotal(array $targetDelta): int
    {
        return $this->positiveIntOption('current-public-total')
            ?? $this->positiveIntValue($targetDelta['current_public_total'] ?? null, 'current_public_total');
    }

    /**
     * @param  array<string, mixed>  $targetDelta
     */
    private function targetPublicTotal(array $targetDelta): int
    {
        return $this->positiveIntOption('target-public-total')
            ?? $this->positiveIntValue($targetDelta['target_public_total'] ?? null, 'target_public_total');
    }

    /**
     * @param  array<string, mixed>  $targetDelta
     */
    private function expectedDeltaCount(array $targetDelta): int
    {
        return $this->positiveIntOption('expect-delta-count')
            ?? $this->positiveIntValue($targetDelta['delta_slug_count'] ?? $targetDelta['delta_promotion_count'] ?? null, 'delta_slug_count');
    }

    private function positiveIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->positiveIntValue($raw, str_replace('-', '_', $name));
    }

    private function positiveIntValue(mixed $raw, string $name): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException($name.'_invalid');
        }

        return $value;
    }

    private function target(int $currentPublicTotal, int $targetPublicTotal): string
    {
        $target = trim((string) ($this->option('target') ?? ''));
        if ($target === '') {
            $target = 'career_'.$currentPublicTotal.'_to_'.$targetPublicTotal.'_delta';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower($target)) ?? $target;

        return trim($normalized, '_') ?: 'career_'.$targetPublicTotal.'_delta';
    }

    private function batchId(int $currentPublicTotal, int $targetPublicTotal): string
    {
        $batchId = trim((string) ($this->option('batch-id') ?? ''));
        if ($batchId !== '') {
            return $batchId;
        }

        return 'career_'.$currentPublicTotal.'_to_'.$targetPublicTotal.'_canonical_001';
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
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'progressive_rollout_manifest_error';
        $key = trim($key, '_');

        return $key === '' ? 'progressive_rollout_manifest_error' : $key;
    }
}
