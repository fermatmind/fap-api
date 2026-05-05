<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\BigFiveV2PilotRuntimeObservability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveV2PilotRuntimeFailClosedTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_missing_domain_percentile_fails_closed_without_pilot_payload(): void
    {
        Log::spy();
        $fixture = $this->authorizedPilotFixture('anon_big5_v2_fail_closed_missing_percentile');
        $this->replaceDomainPercentiles($fixture, [
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
        ]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->safeGenerationFailureContext($context)),
        )->once();
    }

    public function test_out_of_range_percentile_fails_closed_without_pilot_payload(): void
    {
        Log::spy();
        $fixture = $this->authorizedPilotFixture('anon_big5_v2_fail_closed_out_of_range');
        $this->replaceDomainPercentiles($fixture, [
            'O' => 101,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->safeGenerationFailureContext($context)),
        )->once();
    }

    public function test_norm_missing_payload_validation_fails_closed_without_public_score_claims(): void
    {
        Log::spy();
        $fixture = $this->authorizedPilotFixture('anon_big5_v2_fail_closed_norm_missing');
        $this->replaceNormStatus($fixture, 'MISSING');

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->safeValidationFailureContext($context)),
        )->once();
    }

    public function test_rollback_flag_off_keeps_existing_report_response(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_fail_closed_rollback');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    /**
     * @return array{attempt:\App\Models\Attempt,result:\App\Models\Result,attempt_id:string,anon_id:string,token:string,legacy_sections:list<array<string,mixed>>}
     */
    private function authorizedPilotFixture(string $anonId): array
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture($anonId);
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        return $fixture;
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function appendWrapperPayload(array $fixture): array
    {
        return app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            ['report' => ['sections' => $fixture['legacy_sections']]],
        );
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @param  array<string,int>  $domainPercentiles
     */
    private function replaceDomainPercentiles(array $fixture, array $domainPercentiles): void
    {
        $result = $fixture['result'];
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        data_set($resultJson, 'normed_json.scores_0_100.domains_percentile', $domainPercentiles);
        data_set($resultJson, 'breakdown_json.score_result.scores_0_100.domains_percentile', $domainPercentiles);
        data_set($resultJson, 'axis_scores_json.score_result.scores_0_100.domains_percentile', $domainPercentiles);
        $result->result_json = $resultJson;
        $result->save();
    }

    /**
     * @param  array<string,mixed>  $fixture
     */
    private function replaceNormStatus(array $fixture, string $status): void
    {
        $result = $fixture['result'];
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        data_set($resultJson, 'normed_json.norms.status', $status);
        data_set($resultJson, 'breakdown_json.score_result.norms.status', $status);
        data_set($resultJson, 'axis_scores_json.score_result.norms.status', $status);
        $result->result_json = $resultJson;
        $result->save();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function safeGenerationFailureContext(array $context): bool
    {
        return ($context['fallback_reason'] ?? null) === 'payload_generation_failed'
            && ($context['payload_attached'] ?? null) === false
            && ($context['payload_validation_failed'] ?? null) === false
            && ! array_key_exists('attempt_id', $context)
            && ! array_key_exists('payload_body', $context)
            && strlen((string) ($context['attempt_hash'] ?? '')) === 64;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function safeValidationFailureContext(array $context): bool
    {
        return ($context['fallback_reason'] ?? null) === 'payload_validation_failed'
            && ($context['payload_attached'] ?? null) === false
            && ($context['payload_validation_failed'] ?? null) === true
            && ($context['payload_validation_error_count'] ?? 0) > 0
            && ! array_key_exists('errors', $context)
            && ! array_key_exists('payload_body', $context)
            && strlen((string) ($context['attempt_hash'] ?? '')) === 64;
    }
}
