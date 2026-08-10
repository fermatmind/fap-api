<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\BigFiveReportUnlockRolloutGate;
use Tests\TestCase;

final class BigFiveReportUnlockRolloutGateTest extends TestCase
{
    public function test_allowlist_scope_and_stable_percentage_use_the_same_attempt_seed(): void
    {
        config()->set('report_unlock.big5_rollout', [
            'mode' => 'allowlist_only',
            'percentage' => 0,
            'max_percentage' => 10,
            'emergency_disabled' => false,
            'allowed_attempt_ids' => [],
            'allowed_user_ids' => [],
            'allowed_anon_ids' => ['operator-anon'],
            'allowed_org_ids' => [],
            'allowed_tenant_ids' => ['0'],
            'allowed_form_codes' => ['big5_90', 'big5_120'],
            'allowed_locales' => ['zh-CN'],
        ]);
        $attempt = $this->attempt('attempt-1', 'operator-anon');
        $gate = app(BigFiveReportUnlockRolloutGate::class);

        self::assertTrue($gate->allows($attempt));

        config()->set('report_unlock.big5_rollout.mode', 'allowlist_or_percentage');
        config()->set('report_unlock.big5_rollout.allowed_anon_ids', []);
        config()->set('report_unlock.big5_rollout.percentage', 10);

        self::assertSame($gate->allows($attempt), $gate->allows($attempt));
    }

    public function test_non_matching_scope_and_emergency_disable_fail_closed(): void
    {
        config()->set('report_unlock.big5_rollout', [
            'mode' => 'allowlist_only',
            'percentage' => 0,
            'max_percentage' => 10,
            'emergency_disabled' => false,
            'allowed_anon_ids' => ['operator-anon'],
            'allowed_tenant_ids' => ['0'],
            'allowed_form_codes' => ['big5_90'],
            'allowed_locales' => ['zh-CN'],
        ]);
        $gate = app(BigFiveReportUnlockRolloutGate::class);

        self::assertFalse($gate->allows($this->attempt('attempt-2', 'operator-anon', 'big5_120')));

        config()->set('report_unlock.big5_rollout.emergency_disabled', true);
        self::assertFalse($gate->allows($this->attempt('attempt-3', 'operator-anon')));
    }

    public function test_missing_meta_uses_dir_version_or_unique_question_count(): void
    {
        $this->configureAllowlist();
        $gate = app(BigFiveReportUnlockRolloutGate::class);

        self::assertTrue($gate->allows($this->attempt('attempt-dir-120', 'operator-anon', null, 'v1', 0)));
        self::assertTrue($gate->allows($this->attempt('attempt-dir-90', 'operator-anon', null, 'v1-form-90', 0)));
        self::assertTrue($gate->allows($this->attempt('attempt-count-120', 'operator-anon', null, '', 120)));
        self::assertTrue($gate->allows($this->attempt('attempt-count-90', 'operator-anon', null, '', 90)));
    }

    public function test_invalid_or_conflicting_form_identity_fails_closed(): void
    {
        $this->configureAllowlist();
        $gate = app(BigFiveReportUnlockRolloutGate::class);

        self::assertFalse($gate->allows($this->attempt('attempt-invalid', 'operator-anon', 'big5_unknown', 'v1', 120)));
        self::assertFalse($gate->allows($this->attempt('attempt-meta-dir-conflict', 'operator-anon', 'big5_90', 'v1', 90)));
        self::assertFalse($gate->allows($this->attempt('attempt-dir-count-conflict', 'operator-anon', null, 'v1-form-90', 120)));
        self::assertFalse($gate->allows($this->attempt('attempt-unresolved', 'operator-anon', null, 'legacy-unknown', 0)));
    }

    private function configureAllowlist(): void
    {
        config()->set('report_unlock.big5_rollout', [
            'mode' => 'allowlist_only',
            'percentage' => 0,
            'max_percentage' => 10,
            'emergency_disabled' => false,
            'allowed_attempt_ids' => [],
            'allowed_user_ids' => [],
            'allowed_anon_ids' => ['operator-anon'],
            'allowed_org_ids' => [],
            'allowed_tenant_ids' => ['0'],
            'allowed_form_codes' => ['big5_90', 'big5_120'],
            'allowed_locales' => ['zh-CN'],
        ]);
    }

    private function attempt(
        string $id,
        string $anonId,
        ?string $formCode = 'big5_90',
        string $dirVersion = '',
        int $questionCount = 0,
    ): object {
        return (object) [
            'id' => $id,
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'dir_version' => $dirVersion,
            'question_count' => $questionCount,
            'answers_summary_json' => $formCode === null ? [] : ['meta' => ['form_code' => $formCode]],
        ];
    }
}
