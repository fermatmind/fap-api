<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutGate;
use Tests\TestCase;

final class KillSwitchTest extends TestCase
{
    public function test_default_global_kill_switch_keeps_rollout_disabled(): void
    {
        $decision = $this->gate()->decide($this->attempt());

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_disabled', $decision->reason);
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
    }

    public function test_emergency_disable_overrides_allowlist_and_percentage(): void
    {
        $seed = $this->seedForPercentage(100, true);
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_or_percentage',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_rollout_percentage' => 100,
            'production_rollout_max_percentage' => 100,
        ]);

        $allowed = $this->gate()->decide($this->attempt(['id' => $seed]));
        $this->assertTrue($allowed->allowed);

        config()->set('big5_result_page_v2.production_emergency_disabled', true);

        $disabled = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($disabled->allowed);
        $this->assertSame('production_rollout_emergency_disabled', $disabled->reason);
        $this->assertNull($disabled->matchedBy);
    }

    public function test_release_scoped_kill_switch_blocks_only_disabled_snapshot(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_release_snapshot_id' => 'snapshot_disabled',
            'production_approved_release_snapshot_ids' => ['snapshot_disabled', 'snapshot_safe'],
            'production_disabled_release_snapshot_ids' => ['snapshot_disabled'],
        ]);

        $blocked = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($blocked->allowed);
        $this->assertSame('production_rollout_snapshot_disabled', $blocked->reason);

        config()->set('big5_result_page_v2.production_release_snapshot_id', 'snapshot_safe');

        $safe = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertTrue($safe->allowed);
        $this->assertSame('production_rollout_allowed', $safe->reason);
    }

    public function test_kill_switch_requires_valid_release_and_import_gate_before_exposure(): void
    {
        $this->enableBaseRollout([
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_import_gate_passed' => false,
        ]);

        $importMissing = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($importMissing->allowed);
        $this->assertSame('production_rollout_import_gate_missing', $importMissing->reason);

        config()->set('big5_result_page_v2.production_import_gate_passed', true);
        config()->set('big5_result_page_v2.production_release_snapshot_id', '');

        $snapshotMissing = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($snapshotMissing->allowed);
        $this->assertSame('production_rollout_snapshot_missing', $snapshotMissing->reason);
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
            $seed = 'kill_switch_seed_'.$i;
            $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;
            $isAllowed = $bucket < ($percentage * 100);

            if ($isAllowed === $allowed) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic rollout seed.');
    }
}
