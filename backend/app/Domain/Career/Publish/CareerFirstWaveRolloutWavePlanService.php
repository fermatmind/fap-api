<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveRolloutWavePlan;
use App\DTO\Career\CareerFirstWaveRolloutWavePlanMember;

final class CareerFirstWaveRolloutWavePlanService
{
    /**
     * @var array<string, true>
     */
    private const REVIEW_APPROVED_STATUSES = [
        'approved' => true,
        'reviewed' => true,
    ];

    public function __construct(
        private readonly CareerFirstWaveLaunchManifestService $launchManifestService,
        private readonly CareerFirstWaveLaunchReadinessAuditV2Service $launchReadinessAuditV2Service,
    ) {}

    public function build(): CareerFirstWaveRolloutWavePlan
    {
        $manifest = $this->launchManifestService->build()->toArray();
        $audit = $this->launchReadinessAuditV2Service->build()->toArray();

        $reviewerStatusBySlug = [];
        foreach ((array) ($audit['members'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $reviewerStatusBySlug[$slug] = $this->normalizeNullableString($row['reviewer_status'] ?? null);
        }

        $cohortBySlug = [];
        foreach (['stable', 'candidate', 'hold', 'blocked'] as $cohort) {
            foreach ((array) data_get($manifest, 'groups.'.$cohort, []) as $slug) {
                if (! is_string($slug) || trim($slug) === '') {
                    continue;
                }

                $cohortBySlug[(string) $slug] = $cohort;
            }
        }

        $members = [];
        $manualReviewNeeded = [];

        foreach ((array) ($manifest['members'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $rolloutCohort = $cohortBySlug[$slug] ?? 'hold';
            $trustFreshness = $this->buildTrustFreshnessSummary((array) ($row['trust_freshness'] ?? []));
            $reviewerStatus = $reviewerStatusBySlug[$slug] ?? null;

            if ($this->requiresManualReview($reviewerStatus, $trustFreshness)) {
                $manualReviewNeeded[] = $slug;
            }

            $members[] = new CareerFirstWaveRolloutWavePlanMember(
                canonicalSlug: $slug,
                rolloutCohort: $rolloutCohort,
                launchTier: (string) ($row['launch_tier'] ?? ''),
                readinessStatus: (string) ($row['readiness_status'] ?? ''),
                lifecycleState: (string) ($row['lifecycle_state'] ?? ''),
                publicIndexState: (string) ($row['public_index_state'] ?? ''),
                supportingRoutes: [
                    'family_hub' => (bool) data_get($row, 'supporting_routes.family_hub', false),
                    'next_step_links_count' => (int) data_get($row, 'supporting_routes.next_step_links_count', 0),
                ],
                trustFreshness: $trustFreshness,
                deferReasons: $this->buildDeferReasons(
                    rolloutCohort: $rolloutCohort,
                    readinessStatus: (string) ($row['readiness_status'] ?? ''),
                    publicIndexState: (string) ($row['public_index_state'] ?? ''),
                    nextStepLinksCount: (int) data_get($row, 'supporting_routes.next_step_links_count', 0),
                    trustStalenessState: (string) $trustFreshness['review_staleness_state'],
                ),
            );
        }

        $manualReviewNeeded = array_values(array_unique($manualReviewNeeded));
        sort($manualReviewNeeded);

        return new CareerFirstWaveRolloutWavePlan(
            scope: (string) ($manifest['scope'] ?? CareerFirstWaveLaunchTierSummaryService::SCOPE),
            counts: [
                'stable' => (int) data_get($manifest, 'counts.stable', 0),
                'candidate' => (int) data_get($manifest, 'counts.candidate', 0),
                'hold' => (int) data_get($manifest, 'counts.hold', 0),
                'blocked' => (int) data_get($manifest, 'counts.blocked', 0),
                'manual_review_needed' => count($manualReviewNeeded),
            ],
            cohorts: [
                'stable' => array_values((array) data_get($manifest, 'groups.stable', [])),
                'candidate' => array_values((array) data_get($manifest, 'groups.candidate', [])),
                'hold' => array_values((array) data_get($manifest, 'groups.hold', [])),
                'blocked' => array_values((array) data_get($manifest, 'groups.blocked', [])),
                'manual_review_needed' => $manualReviewNeeded,
            ],
            members: $members,
        );
    }

    /**
     * @param  array<string, mixed>  $trustFreshness
     * @return array{review_due_known:bool,review_staleness_state:string}
     */
    private function buildTrustFreshnessSummary(array $trustFreshness): array
    {
        $reviewStalenessState = $this->normalizeNullableString($trustFreshness['review_staleness_state'] ?? null) ?? 'unknown_due_date';

        return [
            'review_due_known' => (bool) ($trustFreshness['review_due_known'] ?? false),
            'review_staleness_state' => $reviewStalenessState,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildDeferReasons(
        string $rolloutCohort,
        string $readinessStatus,
        string $publicIndexState,
        int $nextStepLinksCount,
        string $trustStalenessState,
    ): array {
        $reasons = [];

        if ($rolloutCohort === 'blocked') {
            $reasons[] = 'blocked';
        }

        if ($readinessStatus !== 'publish_ready') {
            $reasons[] = 'not_publish_ready';
        }

        if ($publicIndexState !== 'indexable') {
            $reasons[] = 'not_indexable';
        }

        if ($nextStepLinksCount < 1) {
            $reasons[] = 'insufficient_next_step_links';
        }

        if (in_array($trustStalenessState, ['review_due', 'review_unreviewed'], true)) {
            $reasons[] = $trustStalenessState;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array{review_due_known:bool,review_staleness_state:string}  $trustFreshness
     */
    private function requiresManualReview(?string $reviewerStatus, array $trustFreshness): bool
    {
        if ($reviewerStatus === null || ! isset(self::REVIEW_APPROVED_STATUSES[strtolower($reviewerStatus)])) {
            return true;
        }

        return in_array($trustFreshness['review_staleness_state'], ['review_due', 'review_unreviewed'], true);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
