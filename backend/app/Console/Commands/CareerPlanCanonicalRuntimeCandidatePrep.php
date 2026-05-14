<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerRuntimeCandidatePrepPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalRuntimeCandidatePrep extends Command
{
    protected $signature = 'career:plan-canonical-runtime-candidate-prep
        {--target-delta= : Required Career 80 target delta JSON artifact}
        {--target-total= : Optional target public total guard for progressive cohorts}
        {--cohort= : Optional cohort key such as career_80_to_300_delta}
        {--projection= : Optional runtime projection JSON artifact}
        {--truth= : Optional runtime truth JSON artifact}
        {--ledger= : Optional full release ledger JSON artifact}
        {--locales=en,zh : Comma-separated locales to prepare}
        {--json : Emit JSON output}
        {--output= : Optional output path for runtime candidate preparation plan JSON}';

    protected $description = 'Plan read-only Career runtime published_candidate preparation rows for explicit delta slugs.';

    public function handle(): int
    {
        try {
            $targetDeltaPath = $this->requiredOption('target-delta');
            $payload = app(CareerRuntimeCandidatePrepPlanner::class)->plan(
                targetDeltaPlan: $this->readJson($targetDeltaPath, 'target_delta'),
                projection: $this->optionalJson('projection'),
                truth: $this->optionalJson('truth'),
                ledger: $this->optionalJson('ledger'),
                locales: $this->localesOption(),
                targetPublicTotal: $this->nullablePositiveIntOption('target-total'),
                cohort: $this->nullableStringOption('cohort'),
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'planned' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerRuntimeCandidatePrepPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'target' => 'career_80_delta',
                'delta_slug_count' => 0,
                'planned_candidate_rows_count' => 0,
                'planned_candidate_rows' => [],
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
                'apply_allowed' => false,
                'next_required_action' => 'FIX_RUNTIME_CANDIDATE_PREP_PLAN',
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

    private function nullableStringOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
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
    private function localesOption(): array
    {
        $raw = trim((string) ($this->option('locales') ?? 'en,zh'));
        $locales = array_values(array_unique(array_filter(array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            explode(',', $raw)
        ))));
        sort($locales);

        if ($locales === []) {
            throw new RuntimeException('locales_missing');
        }

        return $locales;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalJson(string $option): ?array
    {
        $path = trim((string) ($this->option($option) ?? ''));

        return $path === '' ? null : $this->readJson($path, $option);
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
            $this->line('planned_candidate_rows_count='.(string) ($payload['planned_candidate_rows_count'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'runtime_candidate_prep_error';
        $key = trim($key, '_');

        return $key === '' ? 'runtime_candidate_prep_error' : $key;
    }
}
