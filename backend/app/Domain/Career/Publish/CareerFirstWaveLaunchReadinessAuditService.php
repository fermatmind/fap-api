<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveLaunchReadinessAudit;
use App\DTO\Career\CareerFirstWaveLaunchReadinessAuditMember;

final class CareerFirstWaveLaunchReadinessAuditService
{
    public const SUMMARY_VERSION = 'career.launch_readiness.audit.v1';

    /**
     * @var array<string, true>
     */
    private const REVIEW_APPROVED_STATUSES = [
        'approved' => true,
        'reviewed' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const DISALLOWED_CROSSWALK_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'unmapped' => true,
    ];

    public function __construct(
        private readonly CareerFirstWaveLaunchTierSummaryService $launchTierSummaryService,
        private readonly FirstWaveReadinessSummaryService $readinessSummaryService,
        private readonly CareerFirstWaveLifecycleSummaryService $lifecycleSummaryService,
        private readonly CareerFirstWaveNextStepLinksService $nextStepLinksService,
    ) {}

    public function build(): CareerFirstWaveLaunchReadinessAudit
    {
        $launchTierSummary = $this->launchTierSummaryService->build()->toArray();
        $readinessSummary = $this->readinessSummaryService->build()->toArray();
        $lifecycleSummary = $this->lifecycleSummaryService->build()->toArray();

        $readinessBySlug = [];
        foreach ((array) ($readinessSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug !== '') {
                $readinessBySlug[$slug] = $row;
            }
        }

        $lifecycleBySlug = [];
        foreach ((array) ($lifecycleSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug !== '') {
                $lifecycleBySlug[$slug] = $row;
            }
        }

        $counts = [
            'total' => 0,
            'launch_ready' => 0,
            'candidate_review' => 0,
            'hold' => 0,
            'blocked' => 0,
        ];
        $members = [];

        foreach ((array) ($launchTierSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $readiness = $readinessBySlug[$slug] ?? [];
            $lifecycle = $lifecycleBySlug[$slug] ?? [];
            $nextStepLinks = $this->nextStepLinksService->buildBySlug($slug)?->toArray();

            $nextStepLinksCount = (int) data_get($nextStepLinks, 'counts.total', 0);
            $familyHubSupportingRoute = (int) data_get($nextStepLinks, 'counts.family_hub', 0) > 0;
            $blockers = $this->curateBlockers(
                readinessStatus: $this->normalizeNullableString($readiness['status'] ?? null),
                blockedGovernanceStatus: $this->normalizeNullableString($row['blocked_governance_status'] ?? null),
                reviewerStatus: $this->normalizeNullableString($row['reviewer_status'] ?? null),
                crosswalkMode: $this->normalizeNullableString($row['crosswalk_mode'] ?? null),
                indexEligible: (bool) ($row['index_eligible'] ?? false),
                allowStrongClaim: (bool) ($row['allow_strong_claim'] ?? false),
                confidenceScore: $this->normalizeNullableInt($row['confidence_score'] ?? null),
                nextStepLinksCount: $nextStepLinksCount,
            );

            $member = new CareerFirstWaveLaunchReadinessAuditMember(
                occupationUuid: (string) ($row['occupation_uuid'] ?? ''),
                canonicalSlug: $slug,
                canonicalTitleEn: (string) ($row['canonical_title_en'] ?? ''),
                launchTier: (string) ($row['launch_tier'] ?? WaveClassification::HOLD),
                readinessStatus: $this->normalizeNullableString($readiness['status'] ?? null) ?? 'blocked_not_safely_remediable',
                lifecycleState: (string) ($lifecycle['lifecycle_state'] ?? CareerIndexLifecycleState::NOINDEX),
                publicIndexState: (string) ($lifecycle['public_index_state'] ?? 'noindex'),
                indexEligible: (bool) ($row['index_eligible'] ?? false),
                reviewerStatus: $this->normalizeNullableString($row['reviewer_status'] ?? null),
                crosswalkMode: $this->normalizeNullableString($row['crosswalk_mode'] ?? null),
                allowStrongClaim: (bool) ($row['allow_strong_claim'] ?? false),
                confidenceScore: $this->normalizeNullableInt($row['confidence_score'] ?? null),
                blockedGovernanceStatus: $this->normalizeNullableString($row['blocked_governance_status'] ?? null),
                nextStepLinksCount: $nextStepLinksCount,
                familyHubSupportingRoute: $familyHubSupportingRoute,
                blockers: $blockers,
                evidenceRefs: $this->buildEvidenceRefs($slug),
            );

            $counts['total']++;
            $counts[$this->classifyMember($member)]++;
            $members[] = $member;
        }

        return new CareerFirstWaveLaunchReadinessAudit(
            summaryVersion: self::SUMMARY_VERSION,
            scope: (string) ($launchTierSummary['scope'] ?? CareerFirstWaveLaunchTierSummaryService::SCOPE),
            counts: $counts,
            members: $members,
        );
    }

    /**
     * @return list<string>
     */
    private function curateBlockers(
        ?string $readinessStatus,
        ?string $blockedGovernanceStatus,
        ?string $reviewerStatus,
        ?string $crosswalkMode,
        bool $indexEligible,
        bool $allowStrongClaim,
        ?int $confidenceScore,
        int $nextStepLinksCount,
    ): array {
        $blockers = [];

        if ($blockedGovernanceStatus !== null) {
            $blockers[] = 'blocked_governance';
        }

        if ($reviewerStatus === null || ! isset(self::REVIEW_APPROVED_STATUSES[strtolower($reviewerStatus)])) {
            $blockers[] = 'reviewer_not_approved';
        }

        if ($crosswalkMode !== null && isset(self::DISALLOWED_CROSSWALK_MODES[strtolower($crosswalkMode)])) {
            $blockers[] = 'crosswalk_disallowed';
        }

        if (! $indexEligible) {
            $blockers[] = 'not_index_eligible';
        }

        if (! $allowStrongClaim) {
            $blockers[] = 'strong_claim_disallowed';
        }

        if ($confidenceScore !== null && $confidenceScore < 60) {
            $blockers[] = 'low_confidence';
        }

        if ($nextStepLinksCount < 1) {
            $blockers[] = 'insufficient_next_step_links';
        }

        if ($readinessStatus !== 'publish_ready') {
            $blockers[] = 'not_publish_ready';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildEvidenceRefs(string $slug): array
    {
        return [
            'launch_tier' => [
                'summary_kind' => 'career_first_wave_launch_tier',
                'canonical_slug' => $slug,
            ],
            'readiness' => [
                'summary_kind' => 'career_first_wave_readiness',
                'canonical_slug' => $slug,
            ],
            'lifecycle' => [
                'summary_kind' => 'career_first_wave_lifecycle',
                'canonical_slug' => $slug,
            ],
            'next_step_links' => [
                'summary_kind' => 'career_first_wave_next_step_links',
                'canonical_slug' => $slug,
            ],
        ];
    }

    private function classifyMember(CareerFirstWaveLaunchReadinessAuditMember $member): string
    {
        if (
            $member->blockedGovernanceStatus !== null
            || in_array($member->readinessStatus, ['blocked_override_eligible', 'blocked_not_safely_remediable'], true)
        ) {
            return 'blocked';
        }

        if ($member->launchTier === WaveClassification::STABLE && $member->readinessStatus === 'publish_ready') {
            return 'launch_ready';
        }

        if (
            $member->launchTier === WaveClassification::CANDIDATE
            || $member->lifecycleState === CareerIndexLifecycleState::PROMOTION_CANDIDATE
        ) {
            return 'candidate_review';
        }

        return 'hold';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
