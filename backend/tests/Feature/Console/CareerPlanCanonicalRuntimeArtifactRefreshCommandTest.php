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

    public function test_malformed_candidate_prep_plan_blocks_refresh_readiness(): void
    {
        $exitCode = $this->callCommand([
            '--delta-plan' => $this->writeJson('delta-plan', $this->deltaPlan()),
            '--candidate-prep-plan' => $this->writeJson('prep-plan', [
                'schema_version' => 'career_runtime_candidate_prep_plan.v1',
                'status' => 'planned',
                'target' => 'career_80_delta',
                'delta_slug_count' => 51,
                'planned_candidate_rows_count' => 51,
            ]),
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApply(writeVerified: true)),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('candidate_prep_plan_locale_row_count_mismatch', array_column($payload['blockers'], 'reason'));
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

    public function test_candidate_aware_mode_normalizes_zh_cn_locale_alias(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', [
                ...$this->candidatePrepApplyForSlugs(['delta-001']),
                'locales' => ['en-US', 'zh-CN'],
            ]),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['en', 'zh'], $payload['locales']);
        $this->assertSame(2, $payload['projection']['overlay_rows']);
    }

    public function test_candidate_aware_mode_blocks_unsupported_locale(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', [
                ...$this->candidatePrepApplyForSlugs(['delta-001']),
                'locales' => ['en', 'zh-TW'],
            ]),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('candidate_prep_apply_locale_unsupported_zh_tw', $payload['blockers'][0]['reason']);
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

    public function test_candidate_aware_mode_blocks_when_source_projection_is_missing(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs(['delta-001'])),
            '--projection' => sys_get_temp_dir().'/missing-candidate-aware-projection.json',
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('projection_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_candidate_aware_mode_blocks_unverified_apply_artifact(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', [
                ...$this->candidatePrepApplyForSlugs(['delta-001']),
                'write_verified' => false,
            ]),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('candidate_prep_apply_not_verified', $payload['blockers'][0]['reason']);
    }

    public function test_candidate_aware_mode_blocks_apply_artifact_with_failures(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs(['delta-001'], failures: [['reason' => 'write_failed']])),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('candidate_prep_apply_failures_present', $payload['blockers'][0]['reason']);
    }

    public function test_candidate_aware_mode_blocks_slug_count_mismatch(): void
    {
        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs(['delta-001'])),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('candidate_prep_apply_slug_count_mismatch', $payload['blockers'][0]['reason']);
    }

    public function test_candidate_aware_mode_writes_overlay_artifacts(): void
    {
        $projectionOutput = $this->tempPath('candidate-aware-projection');
        $truthOutput = $this->tempPath('candidate-aware-truth');
        $ledgerOutput = $this->tempPath('candidate-aware-ledger');

        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs(['delta-001', 'delta-002'])),
            '--projection' => $this->writeJson('projection', $this->sourceProjection()),
            '--truth' => $this->writeJson('truth', $this->sourceTruth()),
            '--ledger' => $this->writeJson('ledger', $this->sourceLedger()),
            '--projection-output' => $projectionOutput,
            '--truth-output' => $truthOutput,
            '--ledger-output' => $ledgerOutput,
            '--expect-slug-count' => 2,
        ]);
        $payload = $this->payload();
        $projection = json_decode((string) file_get_contents($projectionOutput), true, flags: JSON_THROW_ON_ERROR);
        $truth = json_decode((string) file_get_contents($truthOutput), true, flags: JSON_THROW_ON_ERROR);
        $ledger = json_decode((string) file_get_contents($ledgerOutput), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_runtime_candidate_aware_artifact_refresh.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertSame(4, $payload['projection']['overlay_rows']);
        $this->assertSame(4, $payload['truth']['overlay_rows']);
        $this->assertSame(2, $payload['ledger']['overlay_members']);
        $this->assertSame('candidate_prep_apply_overlay', $projection['items'][1]['overlay_source']);
        $this->assertSame('published_candidate', $projection['items'][1]['runtime_publish_state']);
        $this->assertSame('candidate_prep_apply_overlay', $truth['items'][1]['overlay_source']);
        $this->assertTrue($truth['items'][1]['candidate_pre_route_expected']);
        $this->assertSame('candidate_prep_apply_overlay', $ledger['members'][1]['overlay_source']);
        $this->assertFalse($ledger['members'][1]['canonical_ledger_authority_claimed']);
    }

    public function test_candidate_aware_mode_supports_220_delta_progressive_overlay(): void
    {
        $payload = $this->runCandidateAwareProgressiveOverlay(220, 'career_80_to_300_delta');

        $this->assertSame('pass', $payload['status']);
        $this->assertSame('career_80_to_300_delta', $payload['target']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame(440, $payload['projection']['overlay_rows']);
        $this->assertSame(440, $payload['truth']['overlay_rows']);
        $this->assertSame(220, $payload['ledger']['overlay_members']);
        $this->assertSame('PROGRESSIVE_ROLLOUT_DRY_RUN', $payload['next_required_action']);
    }

    public function test_candidate_aware_mode_supports_500_and_1986_delta_progressive_overlays(): void
    {
        $fiveHundred = $this->runCandidateAwareProgressiveOverlay(500, 'career_300_to_800_delta');

        $this->assertSame(500, $fiveHundred['delta_slug_count']);
        $this->assertSame(1000, $fiveHundred['projection']['overlay_rows']);
        $this->assertSame(1000, $fiveHundred['truth']['overlay_rows']);
        $this->assertSame(500, $fiveHundred['ledger']['overlay_members']);

        $full = $this->runCandidateAwareProgressiveOverlay(1986, 'career_800_to_2786_delta');

        $this->assertSame(1986, $full['delta_slug_count']);
        $this->assertSame(3972, $full['projection']['overlay_rows']);
        $this->assertSame(3972, $full['truth']['overlay_rows']);
        $this->assertSame(1986, $full['ledger']['overlay_members']);
    }

    public function test_candidate_aware_mode_supports_detail_ready_1048_overlay_without_runtime_exposure(): void
    {
        $projectionOutput = $this->tempPath('detail-ready-1048-projection');
        $truthOutput = $this->tempPath('detail-ready-1048-truth');
        $ledgerOutput = $this->tempPath('detail-ready-1048-ledger');
        $slugs = $this->slugs(1018, 'ready');

        $exitCode = $this->callCommand([
            '--target' => 'detail_ready_1048',
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs($slugs)),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--projection-output' => $projectionOutput,
            '--truth-output' => $truthOutput,
            '--ledger-output' => $ledgerOutput,
        ]);
        $payload = $this->payload();
        $projection = json_decode((string) file_get_contents($projectionOutput), true, flags: JSON_THROW_ON_ERROR);
        $truth = json_decode((string) file_get_contents($truthOutput), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('detail_ready_1048', $payload['target']);
        $this->assertSame(1018, $payload['delta_slug_count']);
        $this->assertSame(2036, $payload['expected_delta_locale_rows']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_GATE_DRY_RUN', $payload['next_required_action']);
        $this->assertSame([
            'dataset_hub',
            'career_jobs_api',
            'career_job_detail_api',
            'sitemap',
            'llms',
            'llms_full',
        ], $payload['runtime_authority_contract']['consumers']);
        $this->assertSame(0, $projection['counts']['dataset_visible']);
        $this->assertSame(0, $projection['counts']['detail_route_enabled']);
        $this->assertSame(0, $projection['counts']['sitemap_live']);
        $this->assertSame(0, $projection['counts']['llms_live']);
        $this->assertSame(0, $projection['counts']['llms_full_live']);
        $this->assertSame(2036, $truth['counts']['candidate_pre_route_expected_count']);
        $this->assertSame(0, $truth['counts']['candidate_unexpected_route_exposure_count']);
        $this->assertSame(0, $truth['counts']['candidate_unexpected_sitemap_exposure_count']);
    }

    public function test_candidate_aware_artifacts_pass_runtime_candidate_pool_synthetic_integration(): void
    {
        $slugs = ['alpha-career', 'beta-career'];
        $projectionOutput = $this->tempPath('candidate-aware-projection');
        $truthOutput = $this->tempPath('candidate-aware-truth');
        $ledgerOutput = $this->tempPath('candidate-aware-ledger');

        $exitCode = $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs($slugs)),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--projection-output' => $projectionOutput,
            '--truth-output' => $truthOutput,
            '--ledger-output' => $ledgerOutput,
            '--expect-slug-count' => 2,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $poolExitCode = Artisan::call('career:plan-canonical-80-runtime-candidate-pool', [
            '--audit' => $this->writeAudit($slugs),
            '--projection' => $projectionOutput,
            '--truth' => $truthOutput,
            '--ledger' => $ledgerOutput,
            '--target' => 2,
            '--json' => true,
        ]);
        $pool = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $poolExitCode, Artisan::output());
        $this->assertTrue($pool['pool_pass']);
        $this->assertSame(2, $pool['selected_count']);
        $this->assertSame($slugs, $pool['selection']['slugs']);
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
            'target_public_total',
            'expected_locale_rows',
            'candidate_prep_required',
            'candidate_prep_apply_required',
            'writes_database',
            'read_only',
            'target_authority',
            'runtime_authority_contract',
            'required_inputs',
            'required_outputs',
            'commands',
            'blockers',
            'approval_gates',
            'next_required_action',
        ], array_keys($payload));
    }

    public function test_candidate_aware_json_output_shape_is_stable(): void
    {
        $this->callCommand([
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs(['delta-001'])),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame([
            'schema_version',
            'status',
            'source_apply_artifact',
            'target',
            'delta_slug_count',
            'expected_delta_locale_rows',
            'locales',
            'target_authority',
            'runtime_authority_contract',
            'projection',
            'truth',
            'ledger',
            'blockers',
            'writes_database',
            'read_only',
            'apply_allowed',
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
    private function runCandidateAwareProgressiveOverlay(int $count, string $target): array
    {
        $projectionOutput = $this->tempPath($target.'-projection');
        $truthOutput = $this->tempPath($target.'-truth');
        $ledgerOutput = $this->tempPath($target.'-ledger');
        $summaryOutput = $this->tempPath($target.'-summary');

        $exitCode = $this->callCommand([
            '--target' => $target,
            '--candidate-aware' => true,
            '--candidate-prep-apply' => $this->writeJson('prep-apply', $this->candidatePrepApplyForSlugs($this->slugs($count))),
            '--projection' => $this->writeJson('projection', ['items' => []]),
            '--truth' => $this->writeJson('truth', ['items' => []]),
            '--ledger' => $this->writeJson('ledger', ['members' => []]),
            '--projection-output' => $projectionOutput,
            '--truth-output' => $truthOutput,
            '--ledger-output' => $ledgerOutput,
            '--expect-slug-count' => $count,
            '--output' => $summaryOutput,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($summaryOutput);
        $this->assertFileExists($projectionOutput);
        $this->assertFileExists($truthOutput);
        $this->assertFileExists($ledgerOutput);

        return json_decode((string) file_get_contents($summaryOutput), true, flags: JSON_THROW_ON_ERROR);
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
            'locales' => ['en', 'zh'],
            'expected_locale_rows' => 102,
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
            'slug_count' => $writeVerified ? 51 : 0,
            'expected_locale_rows' => $writeVerified ? 102 : 0,
            'created_count' => $writeVerified ? 51 : 0,
            'verified_count' => $writeVerified ? 51 : 0,
            'failures' => [],
            'locales' => ['en', 'zh'],
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<array<string, mixed>>  $failures
     * @return array<string, mixed>
     */
    private function candidatePrepApplyForSlugs(array $slugs, bool $writeVerified = true, array $failures = []): array
    {
        return [
            'status' => $writeVerified ? 'applied' : 'blocked',
            'writes_database' => $writeVerified,
            'write_verified' => $writeVerified,
            'slug_count' => count($slugs),
            'expected_locale_rows' => count($slugs) * 2,
            'created_count' => $writeVerified ? count($slugs) : 0,
            'verified_count' => $writeVerified ? count($slugs) : 0,
            'failures' => $failures,
            'locales' => ['en', 'zh'],
            'artifact_sha256' => 'fixture-sha',
            'created' => array_map(static fn (string $slug): array => [
                'canonical_slug' => $slug,
                'index_state_id' => 'fixture-'.$slug,
                'runtime_publish_state' => 'published_candidate',
            ], $slugs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProjection(): array
    {
        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => [[
                'slug' => 'published-baseline',
                'locale' => 'en',
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'search_visible' => true,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceTruth(): array
    {
        return [
            'truth_kind' => 'career_canonical_runtime_truth',
            'items' => [[
                'slug' => 'published-baseline',
                'locale' => 'en',
                'public_resolution_type' => 'public_canonical_job',
                'projection_state' => 'published',
                'route_exists' => true,
                'final_200' => true,
                'dataset_visible' => true,
                'search_visible' => true,
                'candidate_pre_route_expected' => false,
                'candidate_unexpected_exposures' => [],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceLedger(): array
    {
        return [
            'ledger_kind' => 'career_full_release_ledger',
            'members' => [[
                'member_kind' => 'career_tracked_occupation',
                'canonical_slug' => 'published-baseline',
                'release_cohort' => 'public_detail_indexable',
                'public_index_state' => 'indexable',
            ]],
        ];
    }

    /**
     * @param  list<string>  $nearEligibleSlugs
     */
    private function writeAudit(array $nearEligibleSlugs): string
    {
        $rows = [];
        foreach (array_values(array_unique($nearEligibleSlugs)) as $slug) {
            $rows[] = $this->auditRow($slug, 'en');
            $rows[] = $this->auditRow($slug, 'zh');
        }

        return $this->writeJson('audit', [
            'status' => 'blocked',
            'scope' => 'all',
            'expected_occupations' => 2786,
            'audited_occupations' => 2786,
            'eligible_count' => 0,
            'blocked_count' => count($rows),
            'by_reason' => [
                'llms_expected_not_ready' => count($rows),
                'surface_unverified' => count($rows),
            ],
            'context_summary' => ['surface_context' => 'supplied'],
            'policy_summary' => ['near_eligible_count' => count($nearEligibleSlugs)],
            'rows' => $rows,
            'sidecars' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRow(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'source_scope' => 'all',
            'entity_status' => $this->layer('entity', 'pass'),
            'baseline_status' => $this->layer('baseline', 'pass'),
            'index_status' => $this->layer('index', 'pass'),
            'runtime_status' => $this->layer('runtime', 'pass', [
                ['runtime_publish_state' => 'published_candidate'],
                ['truth_state' => 'published_candidate'],
                ['canonical_public_type' => 'public_canonical_job'],
                ['candidate_pre_route_expected' => true],
            ]),
            'seo_geo_status' => $this->layer('seo_geo', 'blocked', [], ['llms_expected_not_ready']),
            'surface_status' => $this->layer('surface', 'blocked', [], ['surface_unverified']),
            'safety_status' => $this->layer('safety', 'pass'),
            'overall_status' => 'blocked',
            'severity' => 'high',
            'reasons' => ['llms_expected_not_ready', 'surface_unverified'],
            'evidence' => [['slug' => $slug]],
            'sidecars' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function layer(string $layer, string $status, array $evidence = [], array $reasons = []): array
    {
        return [
            'layer' => $layer,
            'status' => $status,
            'reasons' => $status === 'pass' ? [] : $reasons,
            'evidence' => $evidence,
            'source' => 'synthetic_test_fixture',
        ];
    }

    /**
     * @return list<string>
     */
    private function slugs(int $count = 51, string $prefix = 'delta'): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%04d', $prefix, $i);
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
