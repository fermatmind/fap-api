<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyGscCoreRuntimeEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12B01DailyGscCoreRuntimeTest extends TestCase
{
    public function test_offline_fixtures_cover_ready_zero_delayed_mapping_window_and_unavailable(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(resource_path('seo-agent/council/platform12/fixtures/seo.platform12_daily_gsc_core_runtime.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $evaluator = app(Platform12DailyGscCoreRuntimeEvaluator::class);

        foreach ($fixture['cases'] as $case) {
            $receipt = $evaluator->evaluate($case['evidence']);
            $this->assertSame($case['expected_state'], $receipt['state'], $case['id']);
            $this->assertSame($case['expected_gsc_capability'], $receipt['gsc']['capability_state'], $case['id']);
            $this->assertTrue($receipt['gsc']['source_read_only']);
            $this->assertTrue($receipt['read_only']);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertFalse($receipt['writes_allowed']);
            $this->assertSame(
                app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'),
                $receipt['receipt_hash'],
            );
        }
    }

    public function test_lag_of_three_days_is_ready_and_four_days_holds(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['gsc']['data_max_date'] = '2026-09-01';
        $ready = app(Platform12DailyGscCoreRuntimeEvaluator::class)->evaluate($evidence);
        $evidence['gsc']['data_max_date'] = '2026-08-31';
        $held = app(Platform12DailyGscCoreRuntimeEvaluator::class)->evaluate($evidence);

        $this->assertSame(3, $ready['gsc']['lag_days']);
        $this->assertSame('READY', $ready['state']);
        $this->assertSame(4, $held['gsc']['lag_days']);
        $this->assertSame('DATA_FRESHNESS_HOLD', $held['state']);
    }

    public function test_catalog_adds_definition_without_runtime_or_schedule_activation(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.daily_gsc_core_runtime');

        $this->assertIsArray($mission);
        $this->assertSame('daily', $mission['cadence']);
        $this->assertSame('daily:ALL:06:20', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertFalse((bool) config('seo_council.scheduler_enabled', false));
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());

        $consoleRoutes = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('seo.platform12.daily_gsc_core_runtime', $consoleRoutes);
    }

    public function test_evaluator_never_recomputes_or_emits_production_metrics(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['gsc']['clicks'] = 999;
        $evidence['gsc']['queries'] = ['private query'];
        $receipt = app(Platform12DailyGscCoreRuntimeEvaluator::class)->evaluate($evidence);
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);

        $this->assertSame('READY', $receipt['state']);
        $this->assertStringNotContainsString('clicks', $encoded);
        $this->assertStringNotContainsString('private query', $encoded);
        $this->assertStringNotContainsString('queries', $encoded);
    }

    /** @return array<string, mixed> */
    private function readyEvidence(): array
    {
        return [
            'evaluated_at' => '2026-09-04T02:00:00Z',
            'gsc' => [
                'availability' => 'AVAILABLE',
                'scheduled_receipt_status' => 'success',
                'trigger_mode' => 'scheduled',
                'data_max_date' => '2026-09-02',
                'row_count' => 1,
                'mapping_state' => 'READY',
                'data_quality_state' => 'READY',
                'window_state' => 'COMPLETE',
            ],
            'runtime' => [
                'core_runtime_state' => 'AVAILABLE',
                'public_api_state' => 'AVAILABLE',
                'readback_state' => 'AVAILABLE',
                'production_sha' => str_repeat('a', 40),
                'readback_sha' => str_repeat('a', 40),
            ],
        ];
    }
}
