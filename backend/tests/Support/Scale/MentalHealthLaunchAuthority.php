<?php

declare(strict_types=1);

namespace Tests\Support\Scale;

final class MentalHealthLaunchAuthority
{
    public const SCALE_SDS_20 = 'SDS_20';

    public const SCALE_CLINICAL_COMBO_68 = 'CLINICAL_COMBO_68';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_STAGED_NOINDEX = 'STAGED_NOINDEX';

    public const STATE_SAFETY_REVIEWED = 'SAFETY_REVIEWED';

    public const STATE_INDEXABLE_CANARY = 'INDEXABLE_CANARY';

    public const STATE_INDEXABLE_PUBLIC = 'INDEXABLE_PUBLIC';

    public const STATE_RETIRED = 'RETIRED';

    /** @var list<string> */
    private const STATES = [
        self::STATE_DRAFT,
        self::STATE_STAGED_NOINDEX,
        self::STATE_SAFETY_REVIEWED,
        self::STATE_INDEXABLE_CANARY,
        self::STATE_INDEXABLE_PUBLIC,
        self::STATE_RETIRED,
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::STATE_DRAFT => [
            self::STATE_STAGED_NOINDEX,
            self::STATE_RETIRED,
        ],
        self::STATE_STAGED_NOINDEX => [
            self::STATE_SAFETY_REVIEWED,
            self::STATE_RETIRED,
        ],
        self::STATE_SAFETY_REVIEWED => [
            self::STATE_INDEXABLE_CANARY,
            self::STATE_RETIRED,
        ],
        self::STATE_INDEXABLE_CANARY => [
            self::STATE_INDEXABLE_PUBLIC,
            self::STATE_STAGED_NOINDEX,
            self::STATE_RETIRED,
        ],
        self::STATE_INDEXABLE_PUBLIC => [
            self::STATE_STAGED_NOINDEX,
            self::STATE_RETIRED,
        ],
        self::STATE_RETIRED => [],
    ];

    /** @var array<string, string> */
    private const DEFAULT_STATES = [
        self::SCALE_SDS_20 => self::STATE_INDEXABLE_CANARY,
        self::SCALE_CLINICAL_COMBO_68 => self::STATE_STAGED_NOINDEX,
    ];

    /** @return list<string> */
    public function states(): array
    {
        return self::STATES;
    }

    public function isMentalHealthScale(string $scaleCode): bool
    {
        return array_key_exists($this->normalizeScaleCode($scaleCode), self::DEFAULT_STATES);
    }

    public function defaultState(string $scaleCode): ?string
    {
        return self::DEFAULT_STATES[$this->normalizeScaleCode($scaleCode)] ?? null;
    }

    public function resolveState(string $scaleCode, ?string $configuredState = null): ?string
    {
        $normalized = $this->normalizeState($configuredState);

        return $normalized ?? $this->defaultState($scaleCode);
    }

    public function canTransition(string $fromState, string $toState): bool
    {
        $from = $this->normalizeState($fromState);
        $to = $this->normalizeState($toState);

        if ($from === null || $to === null) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function allowsPublicIndexing(string $scaleCode, ?string $configuredState = null): bool
    {
        $scale = $this->normalizeScaleCode($scaleCode);
        $state = $this->resolveState($scale, $configuredState);

        return $scale === self::SCALE_SDS_20
            && in_array($state, [self::STATE_INDEXABLE_CANARY, self::STATE_INDEXABLE_PUBLIC], true);
    }

    public function robotsPolicy(string $scaleCode, ?string $configuredState = null): string
    {
        return $this->allowsPublicIndexing($scaleCode, $configuredState)
            ? 'index,follow'
            : 'noindex,follow';
    }

    /** @return array<string, mixed> */
    public function launchProfile(string $scaleCode, ?string $configuredState = null): array
    {
        $scale = $this->normalizeScaleCode($scaleCode);
        $state = $this->resolveState($scale, $configuredState);

        return [
            'scale_code' => $scale,
            'is_mental_health_scale' => $this->isMentalHealthScale($scale),
            'launch_state' => $state,
            'robots' => $this->robotsPolicy($scale, $state),
            'allows_public_indexing' => $this->allowsPublicIndexing($scale, $state),
            'gate_checklist' => $this->gateChecklist($scale),
        ];
    }

    /** @return array<string, mixed> */
    public function gateChecklist(string $scaleCode): array
    {
        $scale = $this->normalizeScaleCode($scaleCode);

        return [
            'claim_boundary_required' => true,
            'public_naming_review_required' => true,
            'diagnostic_disclaimer_required' => true,
            'scale_source_audit_required' => $scale === self::SCALE_SDS_20,
            'source_audit_scope' => $scale === self::SCALE_SDS_20
                ? ['source', 'authorization', 'translation', 'score_range', 'age_suitability']
                : [],
            'crisis_sentinel_required' => true,
            'crisis_sentinel_scope' => $scale === self::SCALE_SDS_20
                ? ['SDS item 19']
                : ['clinical high-risk items'],
            'locale_aware_crisis_resources_required' => true,
            'hardcoded_global_988_disallowed' => true,
            'sensitive_health_data_consent_required' => true,
            'minor_policy_required' => true,
            'data_retention_policy_required' => true,
            'ad_targeting_disallowed' => true,
            'paid_report_noindex_required' => true,
            'free_safety_vs_paid_personalized_boundary_required' => true,
            'related_articles_required' => [
                'total' => 9,
                'tool' => 3,
                'safety' => 3,
                'growth' => 3,
            ],
            'robots_contract' => [
                'staged_policy' => 'noindex,follow',
                'disallowed_directives' => ['nocache', 'noarchive'],
                'authority' => 'launch_state',
            ],
        ];
    }

    private function normalizeState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $normalized = strtoupper(trim($state));

        return in_array($normalized, self::STATES, true) ? $normalized : null;
    }

    private function normalizeScaleCode(string $scaleCode): string
    {
        return strtoupper(trim($scaleCode));
    }
}
