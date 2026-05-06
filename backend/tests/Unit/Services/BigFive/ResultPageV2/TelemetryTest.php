<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ResultPageV2\Observability\BigFiveV2ProductionRolloutTelemetry;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutDecision;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class TelemetryTest extends TestCase
{
    public function test_rollout_attach_and_percentage_metrics_are_structured_and_pii_safe(): void
    {
        Log::spy();

        app(BigFiveV2ProductionRolloutTelemetry::class)->recordRolloutDecision(
            $this->attempt(),
            new Result(['id' => 'result-rollout-telemetry']),
            new BigFiveV2ProductionRolloutDecision(
                true,
                'production_rollout_allowed',
                'rollout_percentage',
                ['rollout_percentage' => 5],
            ),
        );

        Log::shouldHaveReceived('info')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'rollout_attach'
                && ($context['rollout_allowed'] ?? null) === true
                && ($context['rollout_attach_count'] ?? null) === 1
                && ($context['rollout_deny_count'] ?? null) === 0
                && ($context['rollout_percentage'] ?? null) === 5
                && ($context['percentage_rollout_evaluated'] ?? null) === true
                && self::hasOnlySafeIdentifiers($context)),
        )->once();
    }

    public function test_rollout_deny_records_fail_closed_metric_without_raw_errors(): void
    {
        Log::spy();

        app(BigFiveV2ProductionRolloutTelemetry::class)->recordRolloutDecision(
            $this->attempt(),
            null,
            new BigFiveV2ProductionRolloutDecision(
                false,
                'production_rollout_invalid_config',
                null,
                ['rollout_percentage' => 101],
                ['production_rollout_percentage_out_of_range'],
            ),
        );

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'rollout_deny'
                && ($context['rollout_allowed'] ?? null) === false
                && ($context['rollout_deny_count'] ?? null) === 1
                && ($context['fail_closed_count'] ?? null) === 1
                && ($context['rollout_error_count'] ?? null) === 1
                && ! array_key_exists('errors', $context)
                && self::hasOnlySafeIdentifiers($context)),
        )->once();
    }

    public function test_failure_telemetry_records_route_composer_and_payload_counters_only(): void
    {
        Log::spy();
        $telemetry = app(BigFiveV2ProductionRolloutTelemetry::class);
        $attempt = $this->attempt();

        $telemetry->recordRouteLookupFailure($attempt, null, 'O9_C9_E9_A9_N9');
        $telemetry->recordComposerFailure($attempt, null, new \RuntimeException('composer leaked details'));
        $telemetry->recordPayloadValidationFailure($attempt, null, ['internal validation detail']);

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'route_lookup_failure'
                && ($context['route_lookup_failed'] ?? null) === true
                && ($context['route_lookup_failure_count'] ?? null) === 1
                && strlen((string) ($context['combination_key_hash'] ?? '')) === 64
                && self::hasOnlySafeIdentifiers($context)),
        )->once();

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'composer_failure'
                && ($context['composer_failed'] ?? null) === true
                && ($context['composer_failure_count'] ?? null) === 1
                && ! array_key_exists('exception_message', $context)
                && self::hasOnlySafeIdentifiers($context)),
        )->once();

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'payload_validation_failure'
                && ($context['payload_validation_failed'] ?? null) === true
                && ($context['payload_validation_error_count'] ?? null) === 1
                && ! array_key_exists('errors', $context)
                && self::hasOnlySafeIdentifiers($context)),
        )->once();
    }

    public function test_selector_suppression_and_generic_fail_closed_metrics_are_observable(): void
    {
        Log::spy();
        $telemetry = app(BigFiveV2ProductionRolloutTelemetry::class);
        $attempt = $this->attempt();

        $telemetry->recordSelectorSuppression($attempt, null, 3, 2);
        $telemetry->recordFailClosed($attempt, null, 'production_rollout_snapshot_missing', [
            'route_lookup_failed' => true,
            'composer_failed' => false,
            'payload_validation_failed' => false,
        ]);

        Log::shouldHaveReceived('info')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'selector_suppression'
                && ($context['selector_suppression_count'] ?? null) === 3
                && ($context['selector_unresolved_ref_count'] ?? null) === 2
                && ($context['selector_suppression_observed'] ?? null) === true
                && self::hasOnlySafeIdentifiers($context)),
        )->once();

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'fail_closed'
                && ($context['fail_closed_count'] ?? null) === 1
                && ($context['fail_closed_reason'] ?? null) === 'production_rollout_snapshot_missing'
                && ($context['route_lookup_failed'] ?? null) === true
                && ($context['payload_attached'] ?? null) === false
                && self::hasOnlySafeIdentifiers($context)),
        )->once();
    }

    private function attempt(): Attempt
    {
        return new Attempt([
            'id' => 'attempt-rollout-telemetry',
            'user_id' => 'user-rollout-telemetry',
            'anon_id' => 'anon-rollout-telemetry',
            'org_id' => 'org-rollout-telemetry',
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private static function hasOnlySafeIdentifiers(array $context): bool
    {
        foreach (['attempt_hash', 'user_hash', 'anon_hash', 'tenant_hash'] as $key) {
            if (strlen((string) ($context[$key] ?? '')) !== 64) {
                return false;
            }
        }

        foreach (['attempt_id', 'user_id', 'anon_id', 'org_id', 'payload_body', 'payload', 'internal_metadata'] as $key) {
            if (array_key_exists($key, $context)) {
                return false;
            }
        }

        return true;
    }
}
