<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class ProductionRuntimeTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_production_runtime_defaults_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_import_gate_passed'));
        $this->assertSame('', config('big5_result_page_v2.production_release_snapshot_id'));
        $this->assertSame([], config('big5_result_page_v2.production_approved_release_snapshot_ids'));
    }

    public function test_production_legacy_runtime_fails_closed_without_governance_gates(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_result_page_v2.production_runtime_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, static fn (): BigFiveResultPageV2TransformerContract => new class implements BigFiveResultPageV2TransformerContract
        {
            public function transform(array $input): array
            {
                throw new \RuntimeException('production runtime gate must fail closed before transformer');
            }
        });
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_default_blocked');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    public function test_production_runtime_requires_import_gate_and_approved_snapshot(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => false,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_missing_import_gate');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    public function test_production_runtime_attaches_only_when_all_governance_gates_pass(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_all_gates_pass');

        $payload = $this->appendRuntime($fixture);

        $this->assertIsArray($payload[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null);
    }

    public function test_production_runtime_supports_rollback_by_release_disable(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
            'production_disabled_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_release_disabled');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    public function test_production_runtime_supports_emergency_disable(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
            'production_emergency_disabled' => true,
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_emergency_disabled');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function setProductionRuntimeConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            config()->set('big5_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function appendRuntime(array $fixture): array
    {
        return app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            ['report' => ['sections' => $fixture['legacy_sections']]],
        );
    }
}
