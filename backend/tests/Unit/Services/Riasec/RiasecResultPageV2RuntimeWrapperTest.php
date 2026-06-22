<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Services\Riasec\RiasecResultPageV2RuntimeWrapper;
use Tests\TestCase;

final class RiasecResultPageV2RuntimeWrapperTest extends TestCase
{
    public function test_runtime_wrapper_is_default_off(): void
    {
        config()->set('riasec_result_page_v2.enabled', false);
        config()->set('riasec_result_page_v2.staging_runtime_enabled', false);

        $payload = app(RiasecResultPageV2RuntimeWrapper::class)->build(
            $this->attempt(),
            $this->riasecResult(),
            ReportAccess::VARIANT_FULL,
            $this->projection(),
            ['riasec_result_page_v2_staging' => true]
        );

        $this->assertNull($payload);
    }

    public function test_runtime_wrapper_returns_staging_payload_for_full_variant_only(): void
    {
        $this->enableStagingGate();

        $payload = app(RiasecResultPageV2RuntimeWrapper::class)->build(
            $this->attempt(),
            $this->riasecResult(),
            ReportAccess::VARIANT_FULL,
            $this->projection(),
            ['riasec_result_page_v2_staging' => true]
        );

        $this->assertIsArray($payload);
        $this->assertSame('fap.riasec.result_page_v2.runtime_wrapper.v0.1', $payload['schema_version'] ?? null);
        $this->assertSame('staging_only', $payload['runtime_use'] ?? null);
        $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($payload['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($payload['frontend_fallback_allowed'] ?? true));
        $this->assertTrue((bool) data_get($payload, 'redaction_policy.fail_closed'));
        $this->assertFalse((bool) data_get($payload, 'redaction_policy.free_payload_allowed', true));
        $this->assertFalse((bool) data_get($payload, 'redaction_policy.locked_payload_allowed', true));
        $this->assertSame('riasec.deep_content_slots.v1', data_get($payload, 'selector_inputs.deep_content_slots_schema_version'));

        $free = app(RiasecResultPageV2RuntimeWrapper::class)->build(
            $this->attempt(),
            $this->riasecResult(),
            ReportAccess::VARIANT_FREE,
            $this->projection(),
            ['riasec_result_page_v2_staging' => true]
        );
        $this->assertNull($free);
    }

    public function test_runtime_wrapper_denies_production_rollout_flags(): void
    {
        $this->enableStagingGate();
        config()->set('riasec_result_page_v2.production_runtime_enabled', true);

        $payload = app(RiasecResultPageV2RuntimeWrapper::class)->build(
            $this->attempt(),
            $this->riasecResult(),
            ReportAccess::VARIANT_FULL,
            $this->projection(),
            ['riasec_result_page_v2_staging' => true]
        );

        $this->assertNull($payload);
    }

    private function enableStagingGate(): void
    {
        config()->set('riasec_result_page_v2.enabled', true);
        config()->set('riasec_result_page_v2.staging_runtime_enabled', true);
        config()->set('riasec_result_page_v2.pilot_runtime_enabled', false);
        config()->set('riasec_result_page_v2.allowed_environments', ['testing']);
        config()->set('riasec_result_page_v2.production_runtime_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_manual_approval_granted', false);
    }

    private function attempt(): Attempt
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'attempt_riasec_runtime_wrapper';
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';

        return $attempt;
    }

    private function riasecResult(): Result
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function projection(): array
    {
        return [
            'schema_version' => 'riasec.public_projection.v2',
            'top_code' => 'RIA',
            'form' => [
                'form_code' => 'riasec_60',
            ],
            'quality' => [
                'quality_state' => 'normal',
            ],
            'interpretation_state' => [
                'profile_shape' => 'clear_primary',
            ],
            'module_visibility_policy' => [
                'schema_version' => 'riasec.module_visibility_policy.v1',
                'policy_id' => 'riasec_module_visibility_policy_v1',
            ],
            'deep_content_slots_v1' => [
                'schema_version' => 'riasec.deep_content_slots.v1',
                'source_policy' => [
                    'frontend_fallback_allowed' => false,
                ],
            ],
        ];
    }
}
