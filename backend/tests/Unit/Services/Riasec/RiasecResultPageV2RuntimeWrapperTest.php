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

    public function test_staging_behavior_is_unchanged_when_production_flags_are_present(): void
    {
        $this->enableStagingGate();
        config()->set('riasec_result_page_v2.production_runtime_enabled', true);

        $payload = $this->compose(ReportAccess::VARIANT_FULL, ['riasec_result_page_v2_staging' => true]);

        $this->assertIsArray($payload);
        $this->assertSame('staging_only', $payload['runtime_use'] ?? null);
        $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
    }

    public function test_production_runtime_returns_payload_only_after_real_rollout_gate_allows(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->enableProductionGate();

        $payload = $this->compose(ReportAccess::VARIANT_FULL, []);

        $this->assertIsArray($payload);
        $this->assertSame('production', $payload['runtime_use'] ?? null);
        $this->assertTrue((bool) ($payload['production_use_allowed'] ?? false));
        $this->assertTrue((bool) ($payload['ready_for_production'] ?? false));
        $this->assertTrue((bool) data_get($payload, 'gate.production_runtime_enabled'));
        $this->assertSame('attempt_id', data_get($payload, 'gate.gate_matched_rule'));

        $this->assertNull($this->compose(ReportAccess::VARIANT_FREE, []));
    }

    public function test_production_runtime_falls_back_when_emergency_disabled(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->enableProductionGate();
        config()->set('riasec_result_page_v2.production_emergency_disabled', true);

        $this->assertNull($this->compose(ReportAccess::VARIANT_FULL, []));
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

    private function enableProductionGate(): void
    {
        foreach ([
            'production_runtime_enabled' => true,
            'production_rollout_enabled' => true,
            'production_rollout_configured' => true,
            'production_rollout_manual_approval_granted' => true,
            'production_import_gate_passed' => true,
            'production_emergency_disabled' => false,
            'production_release_snapshot_id' => 'riasec_result_page_v2_prod_v0_2',
            'production_approved_release_snapshot_ids' => ['riasec_result_page_v2_prod_v0_2'],
            'production_disabled_release_snapshot_ids' => [],
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_percentage' => 0,
            'production_rollout_max_percentage' => 0,
            'production_rollout_allowed_attempt_ids' => ['attempt_riasec_runtime_wrapper'],
            'production_rollout_allowed_user_ids' => [],
            'production_rollout_allowed_anon_ids' => [],
            'production_rollout_allowed_org_ids' => [],
            'production_rollout_require_tenant_scope' => true,
            'production_rollout_allowed_tenant_ids' => ['0'],
            'production_rollout_allowed_scale_codes' => ['RIASEC'],
            'production_rollout_allowed_form_codes' => ['riasec_60', 'riasec_140'],
            'production_rollout_allowed_locales' => ['zh-CN'],
            'production_post_deploy_smoke_required' => true,
            'production_post_deploy_smoke_procedure_id' => 'riasec_result_page_v2_post_deploy_smoke_v0_2',
        ] as $key => $value) {
            config()->set('riasec_result_page_v2.'.$key, $value);
        }
    }

    private function attempt(): Attempt
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'attempt_riasec_runtime_wrapper';
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';
        $attempt->org_id = 0;
        $attempt->answers_summary_json = ['meta' => ['form_code' => 'riasec_60']];

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
