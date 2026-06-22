<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
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

    private function gate(): RiasecResultPageProductionRolloutGateFixture
    {
        return new RiasecResultPageProductionRolloutGateFixture;
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

final class RiasecResultPageProductionRolloutGateFixture
{
    private const ALLOWED_MODES = [
        'disabled',
        'allowlist_only',
        'percentage',
        'allowlist_or_percentage',
    ];

    public function decide(Attempt $attempt): RiasecResultPageProductionRolloutDecisionFixture
    {
        $context = $this->context($attempt);

        if ((bool) config('riasec_result_page_v2.production_emergency_disabled', false)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_emergency_disabled', null, $context);
        }

        if (! (bool) config('riasec_result_page_v2.production_rollout_enabled', false)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_disabled', null, $context);
        }

        if (! (bool) config('riasec_result_page_v2.production_rollout_configured', false)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_not_configured', null, $context);
        }

        if (! (bool) config('riasec_result_page_v2.production_rollout_manual_approval_granted', false)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_manual_approval_missing', null, $context);
        }

        if (! (bool) config('riasec_result_page_v2.production_import_gate_passed', false)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_import_gate_missing', null, $context);
        }

        $releaseFailure = $this->releaseFailureReason();
        if ($releaseFailure !== null) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, $releaseFailure, null, $context);
        }

        $configErrors = $this->configErrors();
        if ($configErrors !== []) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_invalid_config', null, $context, $configErrors);
        }

        $scopeFailure = $this->scopeFailureReason($context);
        if ($scopeFailure !== null) {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, $scopeFailure, null, $context);
        }

        $mode = (string) config('riasec_result_page_v2.production_rollout_mode', 'disabled');
        if ($mode === 'disabled') {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_mode_disabled', null, $context);
        }

        $allowlistMatch = $this->allowlistMatch($context);
        if ($allowlistMatch !== null) {
            return new RiasecResultPageProductionRolloutDecisionFixture(true, 'production_rollout_allowed', $allowlistMatch, $context);
        }

        if ($mode === 'allowlist_only') {
            return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_allowlist_denied', null, $context);
        }

        if ($this->percentageAllows($context)) {
            return new RiasecResultPageProductionRolloutDecisionFixture(true, 'production_rollout_allowed', 'rollout_percentage', $context);
        }

        return new RiasecResultPageProductionRolloutDecisionFixture(false, 'production_rollout_percentage_denied', null, $context);
    }

    /**
     * @return array<string,string|int>
     */
    private function context(Attempt $attempt): array
    {
        return [
            'attempt_id' => trim((string) ($attempt->attempt_id ?? $attempt->id ?? '')),
            'user_id' => trim((string) ($attempt->user_id ?? '')),
            'anon_id' => trim((string) ($attempt->anon_id ?? '')),
            'org_id' => trim((string) ($attempt->org_id ?? '')),
            'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            'environment' => (string) app()->environment(),
            'form_code' => trim((string) data_get($attempt->answers_summary_json, 'meta.form_code', '')),
            'locale' => trim((string) ($attempt->locale ?? '')),
            'rollout_percentage' => (int) config('riasec_result_page_v2.production_rollout_percentage', 0),
        ];
    }

    private function releaseFailureReason(): ?string
    {
        $snapshotId = trim((string) config('riasec_result_page_v2.production_release_snapshot_id', ''));
        if ($snapshotId === '') {
            return 'production_rollout_snapshot_missing';
        }

        if (! in_array($snapshotId, $this->configuredList('production_approved_release_snapshot_ids'), true)) {
            return 'production_rollout_snapshot_not_approved';
        }

        if (in_array($snapshotId, $this->configuredList('production_disabled_release_snapshot_ids'), true)) {
            return 'production_rollout_snapshot_disabled';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function configErrors(): array
    {
        $errors = [];
        $mode = (string) config('riasec_result_page_v2.production_rollout_mode', 'disabled');
        $percentage = (int) config('riasec_result_page_v2.production_rollout_percentage', 0);
        $maxPercentage = (int) config('riasec_result_page_v2.production_rollout_max_percentage', 0);

        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $errors[] = 'production_rollout_mode_invalid';
        }
        if ($percentage < 0 || $percentage > 100) {
            $errors[] = 'production_rollout_percentage_out_of_range';
        }
        if ($maxPercentage < 0 || $maxPercentage > 100) {
            $errors[] = 'production_rollout_max_percentage_out_of_range';
        }
        if ($percentage > $maxPercentage) {
            $errors[] = 'production_rollout_blast_radius_exceeded';
        }

        foreach ([
            'production_rollout_allowed_scale_codes' => 'production_rollout_scale_scope_missing',
            'production_rollout_allowed_form_codes' => 'production_rollout_form_scope_missing',
            'production_rollout_allowed_locales' => 'production_rollout_locale_scope_missing',
        ] as $configKey => $errorCode) {
            if ($this->configuredList($configKey) === []) {
                $errors[] = $errorCode;
            }
        }

        if ((bool) config('riasec_result_page_v2.production_rollout_require_tenant_scope', true)
            && $this->configuredList('production_rollout_allowed_tenant_ids') === []) {
            $errors[] = 'production_rollout_tenant_scope_missing';
        }

        if ((bool) config('riasec_result_page_v2.production_post_deploy_smoke_required', true)
            && trim((string) config('riasec_result_page_v2.production_post_deploy_smoke_procedure_id', '')) === '') {
            $errors[] = 'production_rollout_post_deploy_smoke_procedure_missing';
        }

        return $errors;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function scopeFailureReason(array $context): ?string
    {
        if (! in_array($context['scale_code'], $this->configuredUpperList('production_rollout_allowed_scale_codes'), true)) {
            return 'production_rollout_scale_denied';
        }
        if (! in_array($context['form_code'], $this->configuredList('production_rollout_allowed_form_codes'), true)) {
            return 'production_rollout_form_denied';
        }
        if (! in_array($context['locale'], $this->configuredList('production_rollout_allowed_locales'), true)) {
            return 'production_rollout_locale_denied';
        }
        if ((bool) config('riasec_result_page_v2.production_rollout_require_tenant_scope', true)
            && ! in_array((string) $context['org_id'], $this->configuredList('production_rollout_allowed_tenant_ids'), true)) {
            return 'production_rollout_tenant_denied';
        }

        return null;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function allowlistMatch(array $context): ?string
    {
        foreach ([
            'attempt_id' => 'production_rollout_allowed_attempt_ids',
            'user_id' => 'production_rollout_allowed_user_ids',
            'anon_id' => 'production_rollout_allowed_anon_ids',
            'org_id' => 'production_rollout_allowed_org_ids',
        ] as $field => $configKey) {
            $candidate = (string) ($context[$field] ?? '');
            if ($candidate !== '' && in_array($candidate, $this->configuredList($configKey), true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function percentageAllows(array $context): bool
    {
        $percentage = (int) config('riasec_result_page_v2.production_rollout_percentage', 0);
        if ($percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }

        $seed = (string) ($context['attempt_id'] !== '' ? $context['attempt_id'] : ($context['anon_id'] ?? ''));
        if ($seed === '') {
            return false;
        }

        $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;

        return $bucket < ($percentage * 100);
    }

    /**
     * @return list<string>
     */
    private function configuredList(string $key): array
    {
        $configured = config('riasec_result_page_v2.'.$key, []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $configured,
        )));
    }

    /**
     * @return list<string>
     */
    private function configuredUpperList(string $key): array
    {
        return array_values(array_map(
            static fn (string $value): string => strtoupper($value),
            $this->configuredList($key),
        ));
    }
}

final class RiasecResultPageProductionRolloutDecisionFixture
{
    /**
     * @param  array<string,string|int>  $context
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?string $matchedBy,
        public readonly array $context,
        public readonly array $errors = [],
    ) {}
}
