<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Services\Riasec\Rollout\RiasecResultPageProductionRolloutGate;
use Tests\TestCase;

final class RolloutGateTest extends TestCase
{
    public function test_rollout_gate_defaults_disabled(): void
    {
        $decision = $this->gate()->decide($this->attempt());

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_disabled', $decision->reason);
        $this->assertFalse((bool) config('riasec_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('riasec_result_page_v2.production_rollout_manual_approval_granted'));
        $this->assertSame(0, (int) config('riasec_result_page_v2.production_rollout_percentage'));
        $this->assertSame(0, (int) config('riasec_result_page_v2.production_rollout_max_percentage'));
    }

    public function test_allowlist_rollout_requires_manual_import_snapshot_and_scope(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $allowed = $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_allowed']));
        $denied = $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_denied']));

        $this->assertTrue($allowed->allowed);
        $this->assertSame('production_rollout_allowed', $allowed->reason);
        $this->assertSame('attempt_id', $allowed->matchedBy);
        $this->assertFalse($denied->allowed);
        $this->assertSame('production_rollout_allowlist_denied', $denied->reason);
    }

    public function test_scoped_rollout_rejects_wrong_tenant_locale_scale_or_form(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $this->assertSame('production_rollout_tenant_denied', $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_allowed', 'org_id' => 99]))->reason);
        $this->assertSame('production_rollout_locale_denied', $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_allowed', 'locale' => 'en-US']))->reason);
        $this->assertSame('production_rollout_scale_denied', $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_allowed', 'scale_code' => 'MBTI']))->reason);
        $this->assertSame('production_rollout_form_denied', $this->gate()->decide($this->attempt([
            'attempt_id' => 'attempt_allowed',
            'answers_summary_json' => ['meta' => ['form_code' => 'riasec_36']],
        ]))->reason);
    }

    public function test_percentage_blast_radius_and_missing_smoke_procedure_fail_closed(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 25,
            'production_rollout_max_percentage' => 10,
        ]);

        $blastRadius = $this->gate()->decide($this->attempt());
        $this->assertFalse($blastRadius->allowed);
        $this->assertSame('production_rollout_invalid_config', $blastRadius->reason);
        $this->assertContains('production_rollout_blast_radius_exceeded', $blastRadius->errors);

        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 5,
            'production_rollout_max_percentage' => 5,
            'production_post_deploy_smoke_procedure_id' => '',
        ]);

        $missingSmoke = $this->gate()->decide($this->attempt());
        $this->assertFalse($missingSmoke->allowed);
        $this->assertSame('production_rollout_invalid_config', $missingSmoke->reason);
        $this->assertContains('production_rollout_post_deploy_smoke_procedure_missing', $missingSmoke->errors);
    }

    public function test_manual_approval_import_gate_snapshot_and_kill_switch_fail_closed(): void
    {
        config()->set('riasec_result_page_v2.production_rollout_enabled', true);
        config()->set('riasec_result_page_v2.production_rollout_configured', true);
        config()->set('riasec_result_page_v2.production_rollout_manual_approval_granted', false);

        $this->assertSame('production_rollout_manual_approval_missing', $this->gate()->decide($this->attempt())->reason);

        config()->set('riasec_result_page_v2.production_rollout_manual_approval_granted', true);
        config()->set('riasec_result_page_v2.production_import_gate_passed', false);
        $this->assertSame('production_rollout_import_gate_missing', $this->gate()->decide($this->attempt())->reason);

        $this->enableBaseRollout([
            'production_release_snapshot_id' => '',
        ]);
        $this->assertSame('production_rollout_snapshot_missing', $this->gate()->decide($this->attempt())->reason);

        $this->enableBaseRollout([
            'production_emergency_disabled' => true,
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);
        $halted = $this->gate()->decide($this->attempt(['attempt_id' => 'attempt_allowed']));
        $this->assertFalse($halted->allowed);
        $this->assertSame('production_rollout_emergency_disabled', $halted->reason);
    }

    private function gate(): RiasecResultPageProductionRolloutGate
    {
        return new RiasecResultPageProductionRolloutGate;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function enableBaseRollout(array $overrides = []): void
    {
        foreach (array_merge([
            'production_rollout_enabled' => true,
            'production_rollout_configured' => true,
            'production_rollout_manual_approval_granted' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'riasec_result_page_v2_rc_0_1',
            'production_approved_release_snapshot_ids' => ['riasec_result_page_v2_rc_0_1'],
            'production_disabled_release_snapshot_ids' => [],
            'production_emergency_disabled' => false,
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_percentage' => 0,
            'production_rollout_max_percentage' => 0,
            'production_rollout_allowed_attempt_ids' => [],
            'production_rollout_allowed_user_ids' => [],
            'production_rollout_allowed_anon_ids' => [],
            'production_rollout_allowed_org_ids' => [],
            'production_rollout_allowed_tenant_ids' => ['42'],
            'production_rollout_allowed_scale_codes' => ['RIASEC'],
            'production_rollout_allowed_form_codes' => ['riasec_60'],
            'production_rollout_allowed_locales' => ['zh-CN'],
            'production_post_deploy_smoke_required' => true,
            'production_post_deploy_smoke_procedure_id' => 'riasec_result_page_v2_post_deploy_smoke_v0_1',
        ], $overrides) as $key => $value) {
            config()->set('riasec_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function attempt(array $overrides = []): Attempt
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'attempt_default';
        $attempt->anon_id = 'anon_default';
        $attempt->user_id = 'user_default';
        $attempt->org_id = 42;
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';
        $attempt->answers_summary_json = ['meta' => ['form_code' => 'riasec_60']];

        foreach ($overrides as $key => $value) {
            $attempt->{$key} = $value;
        }

        return $attempt;
    }
}
