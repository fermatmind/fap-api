<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerGenerateCanonicalExpansionManifestTrainCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:generate-canonical-expansion-manifest-train', Artisan::all());
    }

    public function test_missing_readiness_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--readiness' => sys_get_temp_dir().'/missing-career-readiness.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('readiness_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_invalid_json_blocks(): void
    {
        $path = $this->tempPath('invalid-readiness');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand([
            '--readiness' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('readiness_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_readiness_pass_false_blocks(): void
    {
        $path = $this->writeReadiness(['alpha-career'], readinessPass: false);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('readiness_not_passed', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_manifest_generation_not_allowed_blocks(): void
    {
        $path = $this->writeReadiness(['alpha-career'], manifestGenerationAllowed: false);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('manifest_generation_not_allowed', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
    }

    public function test_selected_count_below_target_blocks(): void
    {
        $path = $this->writeReadiness(['alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('selected_count_below_target', array_column($payload['blockers'], 'reason'));
    }

    public function test_rollout_candidate_gate_below_target_blocks(): void
    {
        $path = $this->writeReadiness(['alpha-career', 'beta-career'], rolloutCandidateEligibleCount: 1);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('rollout_candidate_gate_below_target', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_duplicate_slugs_block(): void
    {
        $path = $this->writeReadiness(['alpha-career', 'alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('selected_slugs_duplicate', $payload['blockers'][0]['reason']);
        $this->assertSame('alpha-career', $payload['blockers'][0]['evidence']['slug']);
    }

    public function test_selected_rollout_candidate_ineligible_row_blocks(): void
    {
        $path = $this->writeReadiness(['alpha-career'], ineligibleSelectedSlugs: ['alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('selected_slug_not_rollout_candidate_eligible', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
    }

    public function test_target_defaults_to_80(): void
    {
        $path = $this->writeReadiness(['alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(80, $payload['target']);
        $this->assertContains('selected_count_below_target', array_column($payload['blockers'], 'reason'));
    }

    public function test_command_accepts_target_override_and_writes_manifest_train(): void
    {
        $path = $this->writeReadiness(['beta-career', 'alpha-career', 'gamma-career']);
        $output = $this->tempPath('manifest-train');

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('career_canonical_expansion_manifest_train.v1', $payload['schema_version']);
        $this->assertSame(2, $payload['target']);
        $this->assertSame(1, $payload['manifest_count']);
        $this->assertSame(2, $payload['selected_count']);
        $this->assertSame(['beta-career', 'alpha-career'], $payload['batches'][0]['slugs']);
        $this->assertFileExists($output);
    }

    public function test_rollback_group_is_explicit_slug_list(): void
    {
        $path = $this->writeReadiness(['alpha-career', 'beta-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['alpha-career', 'beta-career'], $payload['rollback_group']);
        $this->assertSame(['alpha-career', 'beta-career'], $payload['batches'][0]['rollback_group']);
    }

    public function test_expected_locale_rows_equals_selected_count_times_locales(): void
    {
        $path = $this->writeReadiness(['alpha-career', 'beta-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 2,
            '--locales' => 'en,zh,es',
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['en', 'zh', 'es'], $payload['batches'][0]['locales']);
        $this->assertSame(6, $payload['batches'][0]['expected_locale_rows']);
    }

    public function test_apply_allowed_is_always_false_and_dry_run_allowed_only_when_valid(): void
    {
        $passing = $this->writeReadiness(['alpha-career']);
        $blocked = $this->writeReadiness(['alpha-career'], readinessPass: false);

        $this->assertSame(0, $this->callCommand([
            '--readiness' => $passing,
            '--target' => 1,
        ]));
        $passPayload = $this->payload();

        $this->assertFalse($passPayload['apply_allowed']);
        $this->assertFalse($passPayload['batches'][0]['apply_allowed']);
        $this->assertTrue($passPayload['dry_run_allowed']);
        $this->assertTrue($passPayload['batches'][0]['dry_run_allowed']);
        $this->assertFalse($passPayload['rollout_dry_run_executed']);
        $this->assertFalse($passPayload['rollout_apply_executed']);

        $this->assertSame(1, $this->callCommand([
            '--readiness' => $blocked,
            '--target' => 1,
        ]));
        $blockedPayload = $this->payload();

        $this->assertFalse($blockedPayload['apply_allowed']);
        $this->assertFalse($blockedPayload['dry_run_allowed']);
        $this->assertFalse($blockedPayload['writes_database']);
    }

    public function test_json_output_schema_is_stable(): void
    {
        $path = $this->writeReadiness(['alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame([
            'schema_version',
            'status',
            'target',
            'source_readiness',
            'manifest_count',
            'selected_count',
            'batch_id',
            'rollback_group',
            'rollout_allowed',
            'dry_run_allowed',
            'apply_allowed',
            'read_only',
            'writes_database',
            'rollout_dry_run_executed',
            'rollout_apply_executed',
            'batches',
            'blockers',
            'sidecars',
            'next_required_action',
        ], array_keys($payload));
        $this->assertSame('80_ROLLOUT_DRY_RUN_READ_ONLY', $payload['next_required_action']);
    }

    public function test_command_does_not_mutate_db_or_invoke_rollout(): void
    {
        $path = $this->writeReadiness(['alpha-career']);

        $exitCode = $this->callCommand([
            '--readiness' => $path,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertFalse($payload['rollout_dry_run_executed']);
        $this->assertFalse($payload['rollout_apply_executed']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:generate-canonical-expansion-manifest-train', array_merge([
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
     * @param  list<string>  $slugs
     */
    private function writeReadiness(
        array $slugs,
        bool $readinessPass = true,
        bool $manifestGenerationAllowed = true,
        ?int $rolloutCandidateEligibleCount = null,
        array $ineligibleSelectedSlugs = [],
    ): string {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rolloutEligible = ! in_array($slug, $ineligibleSelectedSlugs, true);
            $rows[] = [
                'slug' => $slug,
                'locales' => ['en', 'zh'],
                'score' => $index + 1,
                'reasons' => [],
                'rollout_candidate_eligible' => $rolloutEligible,
                'runtime_state_evidence' => [
                    'runtime_publish_states' => ['published_candidate'],
                    'truth_states' => ['published_candidate'],
                    'projection_states' => ['published_candidate'],
                    'canonical_public_types' => ['public_canonical_job'],
                    'candidate_pre_route_expected' => [true],
                    'candidate_unexpected_exposures' => [],
                ],
                'deferred_until_candidate' => ['surface_unverified'],
                'expected_not_ready' => [],
                'remediation_required' => [],
                'rollout_candidate_exclusions' => $rolloutEligible ? [] : ['already_published'],
            ];
        }

        $payload = [
            'schema_version' => 'career_80_cohort_readiness.v1',
            'status' => $readinessPass ? 'pass' : 'blocked',
            'readiness_pass' => $readinessPass,
            'target' => 80,
            'candidate_count' => count($slugs),
            'selected_count' => count($slugs),
            'read_only' => true,
            'writes_database' => false,
            'rollout_candidate_gate' => [
                'required' => true,
                'expected_runtime_state' => 'published_candidate',
                'eligible_count' => $rolloutCandidateEligibleCount ?? count($slugs),
                'excluded_count' => count($ineligibleSelectedSlugs),
                'exclusions_by_reason' => $ineligibleSelectedSlugs === [] ? [] : ['already_published' => count($ineligibleSelectedSlugs)],
                'eligible_slugs' => array_values(array_diff($slugs, $ineligibleSelectedSlugs)),
                'excluded_rows' => [],
            ],
            'source_audit' => [
                'path' => '/tmp/synthetic-career-audit.json',
                'status' => 'blocked',
                'expected_occupations' => 2786,
                'audited_occupations' => 2786,
            ],
            'policy_summary' => [
                'near_eligible_count' => count($slugs),
            ],
            'selection' => [
                'strategy' => 'policy_near_eligible_ranked',
                'slugs' => $slugs,
                'rows' => $rows,
            ],
            'blockers' => [],
            'sidecars' => [],
            'rollout' => [
                'manifest_generation_allowed' => $manifestGenerationAllowed,
                'apply_allowed' => false,
                'reason' => 'readiness only; apply requires separate approval',
            ],
            'next_required_action' => $readinessPass ? '80_MANIFEST_TRAIN_READ_ONLY' : 'FIX_BLOCKERS',
        ];

        $path = $this->tempPath('readiness');
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-80-manifest-train-'.Str::uuid().'-'.$name.'.json';
    }
}
