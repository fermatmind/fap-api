<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12WeeklyMeasurementEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12C02WeeklyMeasurementTest extends TestCase
{
    public function test_d7_d14_d28_and_public_funnel_produce_read_only_artifact(): void
    {
        $artifact = app(Platform12WeeklyMeasurementEvaluator::class)->evaluate($this->readyEvidence());

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame(['D7', 'D14', 'D28'], array_column($artifact['checkpoints'], 'checkpoint'));
        $this->assertSame('PUBLIC_TOTALS_ONLY', $artifact['public_funnel']['aggregation_level']);
        $this->assertSame('attribution_not_causal', $artifact['cro_candidates'][0]['attribution_caveat']);
        $this->assertTrue($artifact['artifact_only']);
        $this->assertTrue($artifact['read_only']);
        $this->assertFalse($artifact['execution_allowed']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);
    }

    public function test_gai_unavailable_is_explicit_and_does_not_fabricate_metrics_or_sync(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['gai'] = ['capability_state' => 'UNAVAILABLE'];
        $artifact = app(Platform12WeeklyMeasurementEvaluator::class)->evaluate($evidence);

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame('UNAVAILABLE', $artifact['gai']['capability_state']);
        $this->assertNull($artifact['gai']['visibility_count']);
        $this->assertSame('NOT_ASSUMED', $artifact['gai']['automatic_sync_state']);
        $this->assertFalse($artifact['third_party_gai_sync_assumed']);
    }

    public function test_any_incomplete_or_small_checkpoint_and_small_funnel_hold_measurement(): void
    {
        $smallCheckpoint = $this->readyEvidence();
        $smallCheckpoint['checkpoints'][0]['sample_size'] = 99;
        $smallFunnel = $this->readyEvidence();
        $smallFunnel['public_funnel']['sample_size'] = 99;
        $incomplete = $this->readyEvidence();
        $incomplete['checkpoints'][2]['state'] = 'WINDOW_INCOMPLETE';

        foreach ([$smallCheckpoint, $smallFunnel, $incomplete] as $evidence) {
            $this->assertSame(
                'MEASUREMENT_HOLD',
                app(Platform12WeeklyMeasurementEvaluator::class)->evaluate($evidence)['state'],
            );
        }
    }

    public function test_funnel_counts_cannot_exceed_the_declared_public_sample(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['public_funnel']['sample_size'] = 299;

        $artifact = app(Platform12WeeklyMeasurementEvaluator::class)->evaluate($evidence);

        $this->assertSame('MEASUREMENT_HOLD', $artifact['state']);
        $this->assertSame('UNAVAILABLE', $artifact['public_funnel']['availability']);
        $this->assertNull($artifact['public_funnel']['sample_size']);
    }

    public function test_identity_attempt_and_private_result_fields_are_never_read_or_emitted(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['public_funnel']['user_id'] = 123;
        $evidence['public_funnel']['attempt_id'] = 'attempt-secret';
        $evidence['public_funnel']['private_result'] = 'private-result-copy';
        $artifact = app(Platform12WeeklyMeasurementEvaluator::class)->evaluate($evidence);
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR);

        $this->assertFalse($artifact['identity_data_read']);
        $this->assertFalse($artifact['private_result_data_read']);
        $this->assertStringNotContainsString('user_id', $encoded);
        $this->assertStringNotContainsString('attempt-secret', $encoded);
        $this->assertStringNotContainsString('private-result-copy', $encoded);
    }

    public function test_catalog_declares_zero_budget_weekly_measurement_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.weekly_checkpoints_gai_funnel_cro');

        $this->assertIsArray($mission);
        $this->assertSame('weekly:MON:03:20', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.weekly_checkpoints_gai_funnel_cro',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function readyEvidence(): array
    {
        return [
            'evaluated_at' => '2026-09-07T03:20:00Z',
            'checkpoints' => [
                ['checkpoint' => 'D7', 'state' => 'AVAILABLE', 'sample_size' => 120, 'metric_delta_ppm' => 10000],
                ['checkpoint' => 'D14', 'state' => 'AVAILABLE', 'sample_size' => 130, 'metric_delta_ppm' => 15000],
                ['checkpoint' => 'D28', 'state' => 'AVAILABLE', 'sample_size' => 150, 'metric_delta_ppm' => 20000],
            ],
            'gai' => ['capability_state' => 'AVAILABLE', 'source_state' => 'VERIFIED', 'visibility_count' => 12],
            'public_funnel' => ['availability' => 'AVAILABLE', 'sample_size' => 300, 'landing_count' => 300, 'start_count' => 180, 'result_count' => 120],
            'cro_candidates' => [[
                'candidate_ref' => str_repeat('a', 64),
                'confidence_ppm' => 750000,
                'attribution_caveat' => 'attribution_not_causal',
            ]],
        ];
    }
}
