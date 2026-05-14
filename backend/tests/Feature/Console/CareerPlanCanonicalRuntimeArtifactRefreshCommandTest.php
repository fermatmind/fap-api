<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonicalRuntimeArtifactRefreshCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-runtime-artifact-refresh', Artisan::all());
    }

    public function test_missing_candidate_prep_apply_blocks_refresh_readiness(): void
    {
        $exitCode = $this->callCommand([
            '--delta-plan' => $this->writeJson('delta-plan', $this->deltaPlan()),
            '--candidate-prep-plan' => $this->writeJson('prep-plan', $this->candidatePrepPlan()),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('candidate_prep_apply_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_invalid_candidate_prep_apply_json_blocks(): void
    {
        $path = $this->tempPath('bad-apply');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand([
            '--candidate-prep-apply' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('candidate_prep_apply_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_verified_candidate_prep_apply_allows_read_only_refresh_plan(): void
    {
        $exitCode = $this->callCommand([
            '--delta-plan' => $this->writeJson('delta-plan', $this->deltaPlan()),
            '--candidate-prep-plan' => $this->writeJson('prep-plan', $this->candidatePrepPlan()),
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApply(writeVerified: true)),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $payload['status']);
        $this->assertSame('post_apply_ready', $payload['phase']);
        $this->assertSame('RUNTIME_ARTIFACT_REFRESH_READ_ONLY', $payload['next_required_action']);
        $this->assertSame([], $payload['blockers']);
    }

    public function test_unverified_candidate_prep_apply_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--delta-plan' => $this->writeJson('delta-plan', $this->deltaPlan()),
            '--candidate-prep-plan' => $this->writeJson('prep-plan', $this->candidatePrepPlan()),
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApply(writeVerified: false)),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('candidate_prep_apply_not_verified', $payload['blockers'][0]['reason']);
    }

    public function test_writes_output_file_without_executing_exports(): void
    {
        $output = $this->tempPath('output');
        $projectionOutput = '/tmp/career_80_delta_runtime_projection_after_candidate_prep.json';
        $truthOutput = '/tmp/career_80_delta_runtime_truth_after_candidate_prep.json';
        $ledgerOutput = '/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json';

        $before = $this->fileFingerprints([$projectionOutput, $truthOutput, $ledgerOutput]);

        $exitCode = $this->callCommand([
            '--delta-plan' => $this->writeJson('delta-plan', $this->deltaPlan()),
            '--candidate-prep-plan' => $this->writeJson('prep-plan', $this->candidatePrepPlan()),
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApply(writeVerified: true)),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($output);
        $this->assertSame($before, $this->fileFingerprints([$projectionOutput, $truthOutput, $ledgerOutput]));
        $this->assertSame([true, true, true], array_column($payload['commands'], 'read_only'));
    }

    public function test_json_output_shape_is_stable(): void
    {
        $this->callCommand([]);
        $payload = $this->payload();

        $this->assertSame([
            'schema_version',
            'status',
            'target',
            'phase',
            'delta_slug_count',
            'candidate_prep_required',
            'candidate_prep_apply_required',
            'writes_database',
            'read_only',
            'required_inputs',
            'required_outputs',
            'commands',
            'blockers',
            'approval_gates',
            'next_required_action',
        ], array_keys($payload));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-runtime-artifact-refresh', array_merge([
            '--json' => true,
        ], $options));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaPlan(): array
    {
        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'delta_promotion_count' => 51,
            'recommended_rollout_delta_slugs' => $this->slugs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePrepPlan(): array
    {
        return [
            'schema_version' => 'career_runtime_candidate_prep_plan.v1',
            'status' => 'planned',
            'target' => 'career_80_delta',
            'delta_slug_count' => 51,
            'planned_candidate_rows_count' => 102,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePrepApply(bool $writeVerified): array
    {
        return [
            'status' => $writeVerified ? 'applied' : 'blocked',
            'writes_database' => $writeVerified,
            'write_verified' => $writeVerified,
            'created_count' => $writeVerified ? 51 : 0,
            'verified_count' => $writeVerified ? 51 : 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        $slugs = [];
        for ($i = 1; $i <= 51; $i++) {
            $slugs[] = sprintf('delta-%03d', $i);
        }

        return $slugs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-runtime-artifact-refresh-'.Str::uuid().'-'.$name.'.json';
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    private function fileFingerprints(array $paths): array
    {
        clearstatcache();

        $fingerprints = [];
        foreach ($paths as $path) {
            $fingerprints[$path] = is_file($path) ? filemtime($path).':'.filesize($path) : null;
        }

        return $fingerprints;
    }
}
