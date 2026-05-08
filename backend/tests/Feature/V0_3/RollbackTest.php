<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutGate;
use Tests\TestCase;

final class RollbackTest extends TestCase
{
    public function test_percentage_rollout_can_rollback_to_zero_without_release_drift(): void
    {
        $seed = $this->seedForPercentage(20, true);
        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 20,
            'production_rollout_max_percentage' => 20,
        ]);

        $allowed = $this->gate()->decide($this->attempt(['id' => $seed]));
        $this->assertTrue($allowed->allowed);
        $this->assertSame('rollout_percentage', $allowed->matchedBy);

        config()->set('big5_result_page_v2.production_rollout_percentage', 0);

        $rolledBack = $this->gate()->decide($this->attempt(['id' => $seed]));
        $this->assertFalse($rolledBack->allowed);
        $this->assertSame('production_rollout_percentage_denied', $rolledBack->reason);
        $this->assertSame('snapshot_rc_test', config('big5_result_page_v2.production_release_snapshot_id'));
    }

    public function test_allowlist_revoke_removes_scoped_exposure(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $allowed = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertTrue($allowed->allowed);
        $this->assertSame('attempt_id', $allowed->matchedBy);

        config()->set('big5_result_page_v2.production_rollout_allowed_attempt_ids', []);

        $revoked = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($revoked->allowed);
        $this->assertSame('production_rollout_allowlist_denied', $revoked->reason);
    }

    public function test_release_snapshot_rollback_blocks_disabled_snapshot_and_allows_revert_target(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_release_snapshot_id' => 'snapshot_rc_next',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test', 'snapshot_rc_next'],
            'production_disabled_release_snapshot_ids' => ['snapshot_rc_next'],
        ]);

        $blocked = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($blocked->allowed);
        $this->assertSame('production_rollout_snapshot_disabled', $blocked->reason);

        config()->set('big5_result_page_v2.production_release_snapshot_id', 'snapshot_rc_test');

        $reverted = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertTrue($reverted->allowed);
        $this->assertSame('production_rollout_allowed', $reverted->reason);
    }

    public function test_fail_closed_config_rollback_recovers_without_enabling_runtime(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_or_percentage',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_rollout_percentage' => 25,
            'production_rollout_max_percentage' => 10,
        ]);

        $invalid = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($invalid->allowed);
        $this->assertSame('production_rollout_invalid_config', $invalid->reason);
        $this->assertContains('production_rollout_blast_radius_exceeded', $invalid->errors);

        config()->set('big5_result_page_v2.production_rollout_percentage', 0);

        $recovered = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertTrue($recovered->allowed);
        $this->assertSame('production_rollout_allowed', $recovered->reason);
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
    }

    private function gate(): BigFiveV2ProductionRolloutGate
    {
        return new BigFiveV2ProductionRolloutGate;
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
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
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
            'production_rollout_allowed_scale_codes' => ['BIG5_OCEAN'],
            'production_rollout_allowed_form_codes' => ['big5_90'],
            'production_rollout_allowed_locales' => ['zh-CN'],
        ], $overrides) as $key => $value) {
            config()->set('big5_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function attempt(array $overrides = []): Attempt
    {
        return new Attempt(array_merge([
            'id' => 'attempt_default',
            'anon_id' => 'anon_default',
            'user_id' => 'user_default',
            'org_id' => 42,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
        ], $overrides));
    }

    private function seedForPercentage(int $percentage, bool $allowed): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $seed = 'rollback_seed_'.$i;
            $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;
            $isAllowed = $bucket < ($percentage * 100);

            if ($isAllowed === $allowed) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic rollout seed.');
    }
}
