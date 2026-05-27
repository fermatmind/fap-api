<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerRuntimeArtifactRefreshPlanner;
use App\Domain\Career\Audit\CareerRuntimeCandidateAwareArtifactRefresh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalRuntimeArtifactRefresh extends Command
{
    protected $signature = 'career:plan-canonical-runtime-artifact-refresh
        {--target=career_80_delta : Refresh target or progressive cohort key}
        {--delta-plan= : Optional Career 80 target delta artifact}
        {--candidate-prep-plan= : Optional runtime candidate prep plan artifact}
        {--candidate-prep-apply= : Optional runtime candidate prep apply artifact}
        {--projection= : Optional source runtime projection artifact for candidate-aware refresh}
        {--truth= : Optional source runtime truth artifact for candidate-aware refresh}
        {--ledger= : Optional source full release ledger artifact for candidate-aware refresh}
        {--candidate-aware : Emit candidate-aware projection/truth/ledger artifacts from a verified candidate prep apply overlay}
        {--projection-output=/tmp/career_80_delta_runtime_projection_candidate_aware.json : Candidate-aware projection output path}
        {--truth-output=/tmp/career_80_delta_runtime_truth_candidate_aware.json : Candidate-aware truth output path}
        {--ledger-output=/tmp/career_80_delta_full_release_ledger_candidate_aware.json : Candidate-aware ledger output path}
        {--expect-slug-count= : Expected verified candidate prep apply slug count in candidate-aware mode}
        {--json : Emit JSON output}
        {--output= : Optional output path for runtime artifact refresh plan JSON}';

    protected $description = 'Plan the read-only Career runtime artifact refresh sequence after candidate preparation apply.';

    public function handle(): int
    {
        try {
            if ((bool) $this->option('candidate-aware')) {
                return $this->handleCandidateAwareRefresh();
            }

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

    private function handleCandidateAwareRefresh(): int
    {
        $candidatePrepApplyPath = $this->requiredPathOption('candidate-prep-apply');
        $projectionPath = $this->requiredPathOption('projection');
        $truthPath = $this->requiredPathOption('truth');
        $ledgerPath = $this->requiredPathOption('ledger');
        $projectionOutputPath = $this->requiredPathOption('projection-output');
        $truthOutputPath = $this->requiredPathOption('truth-output');
        $ledgerOutputPath = $this->requiredPathOption('ledger-output');
        $target = trim((string) ($this->option('target') ?? 'career_80_delta')) ?: 'career_80_delta';
        $expectedSlugCount = $this->positiveIntOption('expect-slug-count', $this->defaultExpectedSlugCount($target));

        try {
            $result = app(CareerRuntimeCandidateAwareArtifactRefresh::class)->build(
                candidatePrepApply: $this->readJson($candidatePrepApplyPath, 'candidate-prep-apply'),
                candidatePrepApplyPath: $candidatePrepApplyPath,
                candidatePrepApplyFileSha256: hash_file('sha256', $candidatePrepApplyPath) ?: null,
                projection: $this->readJson($projectionPath, 'projection'),
                projectionPath: $projectionPath,
                truth: $this->readJson($truthPath, 'truth'),
                truthPath: $truthPath,
                ledger: $this->readJson($ledgerPath, 'ledger'),
                ledgerPath: $ledgerPath,
                projectionOutputPath: $projectionOutputPath,
                truthOutputPath: $truthOutputPath,
                ledgerOutputPath: $ledgerOutputPath,
                expectedSlugCount: $expectedSlugCount,
                target: $target,
            );

            $this->writeJson($projectionOutputPath, $result['projection']);
            $this->writeJson($truthOutputPath, $result['truth']);
            $this->writeJson($ledgerOutputPath, $result['ledger']);

            return $this->finish($result['summary'], self::SUCCESS);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerRuntimeCandidateAwareArtifactRefresh::SCHEMA_VERSION,
                'status' => 'blocked',
                'source_apply_artifact' => [
                    'path' => $candidatePrepApplyPath,
                    'write_verified' => null,
                    'slug_count' => 0,
                    'artifact_sha256' => null,
                ],
                'target' => $target,
                'delta_slug_count' => 0,
                'expected_delta_locale_rows' => 0,
                'projection' => [
                    'source_path' => $projectionPath,
                    'output_path' => $projectionOutputPath,
                    'overlay_rows' => 0,
                    'source' => CareerRuntimeCandidateAwareArtifactRefresh::OVERLAY_SOURCE,
                ],
                'truth' => [
                    'source_path' => $truthPath,
                    'output_path' => $truthOutputPath,
                    'overlay_rows' => 0,
                    'source' => CareerRuntimeCandidateAwareArtifactRefresh::OVERLAY_SOURCE,
                ],
                'ledger' => [
                    'source_path' => $ledgerPath,
                    'output_path' => $ledgerOutputPath,
                    'overlay_members' => 0,
                    'source' => CareerRuntimeCandidateAwareArtifactRefresh::OVERLAY_SOURCE,
                ],
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                    'evidence' => [],
                ]],
                'writes_database' => false,
                'read_only' => true,
                'apply_allowed' => false,
                'next_required_action' => 'REPAIR_RUNTIME_CANDIDATE_ARTIFACT_REFRESH',
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

    private function requiredPathOption(string $name): string
    {
        $path = trim((string) ($this->option($name) ?? ''));
        if ($path === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $path;
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

    private function defaultExpectedSlugCount(string $target): int
    {
        $normalized = strtolower(trim($target));
        $key = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($key, '_') === CareerRuntimeArtifactRefreshPlanner::TARGET_DETAIL_READY_1048
            ? 1018
            : 51;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new RuntimeException('failed_to_encode_json_payload');
        }

        File::put($path, $encoded.PHP_EOL);
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
