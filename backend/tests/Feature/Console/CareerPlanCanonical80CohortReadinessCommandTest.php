<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonical80CohortReadinessCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-80-cohort-readiness', Artisan::all());
    }

    public function test_missing_audit_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--audit' => sys_get_temp_dir().'/missing-career-audit.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['readiness_pass']);
        $this->assertSame('audit_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_malformed_audit_blocks(): void
    {
        $path = $this->tempPath('invalid-audit');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand([
            '--audit' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('audit_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_target_defaults_to_80_and_blocks_when_near_eligible_is_insufficient(): void
    {
        $path = $this->writeAudit(['actuaries']);

        $exitCode = $this->callCommand([
            '--audit' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(80, $payload['target']);
        $this->assertSame(1, $payload['candidate_count']);
        $this->assertSame('insufficient_near_eligible_candidates', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['rollout']['manifest_generation_allowed']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
    }

    public function test_passes_when_near_eligible_reaches_target_and_writes_output(): void
    {
        $audit = $this->writeAudit(['zeta-career', 'alpha-career', 'beta-career']);
        $output = $this->tempPath('readiness-output');

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--target' => 2,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['readiness_pass']);
        $this->assertSame(3, $payload['candidate_count']);
        $this->assertSame(2, $payload['selected_count']);
        $this->assertSame(['alpha-career', 'beta-career'], $payload['selection']['slugs']);
        $this->assertTrue($payload['rollout']['manifest_generation_allowed']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
        $this->assertFileExists($output);
    }

    public function test_selected_slugs_are_unique_and_exclude_remediation_required_rows(): void
    {
        $audit = $this->writeAudit(
            nearEligibleSlugs: ['actuaries', 'actuaries', 'actors', 'artists'],
            remediationSlugs: ['blocked-index']
        );

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--target' => 3,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['actors', 'actuaries', 'artists'], $payload['selection']['slugs']);
        $this->assertNotContains('blocked-index', $payload['selection']['slugs']);
        $this->assertCount(3, array_unique($payload['selection']['slugs']));
    }

    public function test_blocks_when_expected_or_audited_occupation_counts_do_not_match_2786(): void
    {
        $audit = $this->writeAudit(['actuaries', 'actors'], expectedOccupations: 2, auditedOccupations: 2);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('expected_occupations_mismatch', array_column($payload['blockers'], 'reason'));
        $this->assertContains('audited_occupations_mismatch', array_column($payload['blockers'], 'reason'));
    }

    public function test_output_schema_is_stable_and_preserves_sidecars_when_requested(): void
    {
        $audit = $this->writeAudit(['actuaries'], sidecars: [[
            'sidecar_id' => 'surface-evidence',
            'title' => 'Surface evidence remains candidate-stage work',
            'owner_repo' => 'fap-api',
            'scope_relation' => 'external_to_current_pr',
            'introduced_by_current_pr' => false,
            'affected_slugs' => ['actuaries'],
            'affected_locales' => ['en', 'zh'],
            'evidence' => [],
            'severity' => 'info',
            'next_goal' => 'candidate surface verification',
            'may_continue_train' => true,
        ]]);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--target' => 1,
            '--include-sidecars' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame([
            'schema_version',
            'status',
            'readiness_pass',
            'target',
            'candidate_count',
            'selected_count',
            'read_only',
            'writes_database',
            'source_audit',
            'policy_summary',
            'selection',
            'blockers',
            'sidecars',
            'rollout',
            'next_required_action',
        ], array_keys($payload));
        $this->assertSame('career_80_cohort_readiness.v1', $payload['schema_version']);
        $this->assertSame('surface-evidence', $payload['sidecars'][0]['sidecar_id']);
        $this->assertSame('80_MANIFEST_TRAIN_READ_ONLY', $payload['next_required_action']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-80-cohort-readiness', array_merge([
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
     * @param  list<string>  $nearEligibleSlugs
     * @param  list<string>  $remediationSlugs
     * @param  list<array<string, mixed>>  $sidecars
     */
    private function writeAudit(
        array $nearEligibleSlugs,
        array $remediationSlugs = [],
        int $expectedOccupations = 2786,
        int $auditedOccupations = 2786,
        array $sidecars = [],
    ): string {
        $rows = [];
        foreach (array_values(array_unique($nearEligibleSlugs)) as $slug) {
            $rows[] = $this->row($slug, 'en', [
                'sitemap_expected_not_ready',
                'llms_expected_not_ready',
                'surface_unverified',
            ]);
            $rows[] = $this->row($slug, 'zh', [
                'llms_full_expected_not_ready',
                'surface_artifact_missing',
            ]);
        }

        foreach ($remediationSlugs as $slug) {
            $rows[] = $this->row($slug, 'en', ['index_state_missing']);
            $rows[] = $this->row($slug, 'zh', ['index_state_missing']);
        }

        $path = $this->tempPath('audit');
        file_put_contents($path, json_encode([
            'status' => 'blocked',
            'scope' => 'all',
            'expected_occupations' => $expectedOccupations,
            'audited_occupations' => $auditedOccupations,
            'eligible_count' => 0,
            'blocked_count' => count($rows),
            'by_reason' => $this->byReason($rows),
            'context_summary' => ['surface_context' => 'supplied'],
            'policy_summary' => [
                'near_eligible_count' => count(array_unique($nearEligibleSlugs)),
            ],
            'rows' => $rows,
            'sidecars' => $sidecars,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function row(string $slug, string $locale, array $reasons): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'source_scope' => 'all',
            'entity_status' => $this->layer('entity', $this->layerReadiness($reasons, ['occupation_missing', 'entity_field_missing'])),
            'baseline_status' => $this->layer('baseline', 'pass'),
            'index_status' => $this->layer('index', $this->layerReadiness($reasons, ['index_state_missing'])),
            'runtime_status' => $this->layer('runtime', 'pass'),
            'seo_geo_status' => $this->layer('seo_geo', $this->layerReadiness($reasons, [
                'sitemap_expected_not_ready',
                'llms_expected_not_ready',
                'llms_full_expected_not_ready',
            ])),
            'surface_status' => $this->layer('surface', $this->layerReadiness($reasons, [
                'surface_unverified',
                'surface_artifact_missing',
            ])),
            'safety_status' => $this->layer('safety', 'pass'),
            'overall_status' => $reasons === [] ? 'pass' : 'blocked',
            'severity' => $reasons === [] ? 'info' : 'high',
            'reasons' => $reasons,
            'evidence' => [['slug' => $slug]],
            'sidecars' => [],
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $blockedReasons
     */
    private function layerReadiness(array $reasons, array $blockedReasons): string
    {
        return array_intersect($reasons, $blockedReasons) === [] ? 'pass' : 'blocked';
    }

    /**
     * @return array<string, mixed>
     */
    private function layer(string $layer, string $status): array
    {
        return [
            'layer' => $layer,
            'status' => $status,
            'reasons' => [],
            'evidence' => [],
            'source' => 'synthetic_test_fixture',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function byReason(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            foreach ($row['reasons'] as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-80-readiness-'.Str::uuid().'-'.$name.'.json';
    }
}
