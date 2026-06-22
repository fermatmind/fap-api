<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Services\Report\RiasecReportComposer;
use Tests\TestCase;

final class RiasecResultPageV2RuntimeWrapperTest extends TestCase
{
    public function test_runtime_wrapper_is_default_off(): void
    {
        config()->set('riasec_result_page_v2.enabled', false);
        config()->set('riasec_result_page_v2.staging_runtime_enabled', false);

        $payload = $this->compose(ReportAccess::VARIANT_FULL, ['riasec_result_page_v2_staging' => true]);

        $this->assertNull($payload);
    }

    public function test_runtime_wrapper_returns_staging_payload_for_full_variant_only(): void
    {
        $this->enableStagingGate();

        $payload = $this->compose(ReportAccess::VARIANT_FULL, ['riasec_result_page_v2_staging' => true]);

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

        $free = $this->compose(ReportAccess::VARIANT_FREE, ['riasec_result_page_v2_staging' => true]);
        $this->assertNull($free);
    }

    public function test_runtime_wrapper_denies_production_rollout_flags(): void
    {
        $this->enableStagingGate();
        config()->set('riasec_result_page_v2.production_runtime_enabled', true);

        $payload = $this->compose(ReportAccess::VARIANT_FULL, ['riasec_result_page_v2_staging' => true]);

        $this->assertNull($payload);
    }

    /**
     * @param  array<string,mixed>  $ctx
     */
    private function compose(string $variant, array $ctx): ?array
    {
        $result = app(RiasecReportComposer::class)->composeVariant(
            $this->attempt(),
            $this->riasecResult(),
            $variant,
            array_merge(['snapshot_bound' => true], $ctx)
        );
        $this->assertTrue((bool) ($result['ok'] ?? false));

        return data_get($result, 'report._meta.result_page_v2');
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
        $result->result_json = [
            'top_code' => 'RIA',
            'primary_type' => 'R',
            'secondary_type' => 'I',
            'tertiary_type' => 'A',
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'scores_0_100' => [
                'R' => 100,
                'I' => 80,
                'A' => 60,
                'S' => 40,
                'E' => 20,
                'C' => 10,
            ],
        ];

        return $result;
    }
}
