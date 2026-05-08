<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutGate;
use Tests\TestCase;

final class RolloutGateTest extends TestCase
{
    public function test_rollout_gate_defaults_disabled(): void
    {
        $decision = $this->gate()->decide($this->attempt());

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_disabled', $decision->reason);
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_manual_approval_granted'));
        $this->assertSame(0, (int) config('big5_result_page_v2.production_rollout_percentage'));
        $this->assertSame(0, (int) config('big5_result_page_v2.production_rollout_max_percentage'));
    }

    public function test_allowlist_only_rollout_accepts_scoped_allowlisted_attempt(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $decision = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));

        $this->assertTrue($decision->allowed);
        $this->assertSame('production_rollout_allowed', $decision->reason);
        $this->assertSame('attempt_id', $decision->matchedBy);
    }

    public function test_allowlist_only_rollout_denies_non_allowlisted_attempt(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $decision = $this->gate()->decide($this->attempt(['id' => 'attempt_other']));

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_allowlist_denied', $decision->reason);
    }

    public function test_percentage_rollout_supports_deterministic_bucket(): void
    {
        $allowedSeed = $this->seedForPercentage(10, true);
        $deniedSeed = $this->seedForPercentage(10, false);

        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 10,
            'production_rollout_max_percentage' => 10,
        ]);

        $allowed = $this->gate()->decide($this->attempt(['id' => $allowedSeed]));
        $denied = $this->gate()->decide($this->attempt(['id' => $deniedSeed]));

        $this->assertTrue($allowed->allowed);
        $this->assertSame('rollout_percentage', $allowed->matchedBy);
        $this->assertFalse($denied->allowed);
        $this->assertSame('production_rollout_percentage_denied', $denied->reason);
    }

    public function test_scoped_rollout_rejects_wrong_tenant_locale_scale_or_form(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $this->assertSame(
            'production_rollout_tenant_denied',
            $this->gate()->decide($this->attempt(['id' => 'attempt_allowed', 'org_id' => 99]))->reason,
        );
        $this->assertSame(
            'production_rollout_locale_denied',
            $this->gate()->decide($this->attempt(['id' => 'attempt_allowed', 'locale' => 'en']))->reason,
        );
        $this->assertSame(
            'production_rollout_scale_denied',
            $this->gate()->decide($this->attempt(['id' => 'attempt_allowed', 'scale_code' => 'MBTI']))->reason,
        );
        $this->assertSame(
            'production_rollout_form_denied',
            $this->gate()->decide($this->attempt([
                'id' => 'attempt_allowed',
                'answers_summary_json' => ['meta' => ['form_code' => 'big5_120']],
            ]))->reason,
        );
    }

    public function test_invalid_config_fails_closed(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 101,
            'production_rollout_max_percentage' => 100,
        ]);

        $decision = $this->gate()->decide($this->attempt());

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_invalid_config', $decision->reason);
        $this->assertContains('production_rollout_percentage_out_of_range', $decision->errors);
        $this->assertContains('production_rollout_blast_radius_exceeded', $decision->errors);
    }

    public function test_rollout_percentage_cannot_exceed_blast_radius_limit(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 25,
            'production_rollout_max_percentage' => 10,
        ]);

        $decision = $this->gate()->decide($this->attempt());

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_invalid_config', $decision->reason);
        $this->assertContains('production_rollout_blast_radius_exceeded', $decision->errors);
    }

    public function test_missing_release_approval_and_import_gate_fail_closed(): void
    {
        config()->set('big5_result_page_v2.production_rollout_enabled', true);
        config()->set('big5_result_page_v2.production_rollout_configured', true);
        config()->set('big5_result_page_v2.production_rollout_manual_approval_granted', false);

        $this->assertSame(
            'production_rollout_manual_approval_missing',
            $this->gate()->decide($this->attempt())->reason,
        );

        config()->set('big5_result_page_v2.production_rollout_manual_approval_granted', true);
        config()->set('big5_result_page_v2.production_import_gate_passed', false);

        $this->assertSame(
            'production_rollout_import_gate_missing',
            $this->gate()->decide($this->attempt())->reason,
        );
    }

    public function test_emergency_disable_fails_closed_before_allowlist(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_emergency_disabled' => true,
        ]);

        $decision = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_emergency_disabled', $decision->reason);
    }

    public function test_disabled_snapshot_fails_closed(): void
    {
        $this->enableBaseRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_disabled_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);

        $decision = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));

        $this->assertFalse($decision->allowed);
        $this->assertSame('production_rollout_snapshot_disabled', $decision->reason);
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
            $seed = 'rollout_seed_'.$i;
            $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;
            $isAllowed = $bucket < ($percentage * 100);

            if ($isAllowed === $allowed) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic rollout seed.');
    }
}
