<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerRuntimeArtifactRefreshPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalRuntimeArtifactRefresh extends Command
{
    protected $signature = 'career:plan-canonical-runtime-artifact-refresh
        {--target=career_80_delta : Refresh target}
        {--delta-plan= : Optional Career 80 target delta artifact}
        {--candidate-prep-plan= : Optional runtime candidate prep plan artifact}
        {--candidate-prep-apply= : Optional runtime candidate prep apply artifact}
        {--json : Emit JSON output}
        {--output= : Optional output path for runtime artifact refresh plan JSON}';

    protected $description = 'Plan the read-only Career runtime artifact refresh sequence after candidate preparation apply.';

    public function handle(): int
    {
        try {
            $payload = app(CareerRuntimeArtifactRefreshPlanner::class)->plan(
                target: trim((string) ($this->option('target') ?? 'career_80_delta')),
                deltaPlan: $this->optionalJson('delta-plan'),
                candidatePrepPlan: $this->optionalJson('candidate-prep-plan'),
                candidatePrepApply: $this->optionalJson('candidate-prep-apply'),
            )->toArray();

            return $this->finish($payload, in_array(($payload['status'] ?? null), ['planned', 'blocked'], true) ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerRuntimeArtifactRefreshPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'target' => 'career_80_delta',
                'phase' => 'blocked',
                'delta_slug_count' => 0,
                'candidate_prep_required' => true,
                'candidate_prep_apply_required' => true,
                'writes_database' => false,
                'read_only' => true,
                'required_inputs' => [],
                'required_outputs' => [],
                'commands' => [],
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                    'evidence' => [],
                ]],
                'approval_gates' => [],
                'next_required_action' => 'FIX_RUNTIME_ARTIFACT_REFRESH_PLAN',
            ], self::FAILURE);
        }
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
            throw new RuntimeException(str_replace('-', '_', $kind).'_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException(str_replace('-', '_', $kind).'_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException(str_replace('-', '_', $kind).'_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException(str_replace('-', '_', $kind).'_artifact_shape_invalid');
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
            $this->line('phase='.(string) ($payload['phase'] ?? 'unknown'));
            $this->line('writes_database='.json_encode((bool) ($payload['writes_database'] ?? false)));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'runtime_artifact_refresh_error';
        $key = trim($key, '_');

        return $key === '' ? 'runtime_artifact_refresh_error' : $key;
    }
}
