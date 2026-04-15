<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerTrustFreshnessAuthority;
use App\DTO\Career\CareerTrustFreshnessMember;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;

final class CareerTrustFreshnessAuthorityService
{
    /**
     * @var array<string, true>
     */
    private const REVIEW_COMPLETED_STATUSES = [
        'approved' => true,
        'reviewed' => true,
    ];

    public function __construct(
        private readonly CareerFirstWaveLaunchReadinessAuditService $launchReadinessAuditService,
        private readonly CareerJobDetailBundleBuilder $jobDetailBundleBuilder,
    ) {}

    public function build(): CareerTrustFreshnessAuthority
    {
        $audit = $this->launchReadinessAuditService->build();
        $members = [];

        foreach ($audit->members as $auditMember) {
            $bundle = $this->jobDetailBundleBuilder->buildBySlug($auditMember->canonicalSlug);
            $trustManifest = $bundle?->trustManifest ?? [];
            $truthLayer = $bundle?->truthLayer ?? [];

            $reviewerStatus = $this->normalizeNullableString($trustManifest['reviewer_status'] ?? null);
            $reviewedAt = $this->normalizeNullableString($trustManifest['reviewed_at'] ?? null);
            $lastSubstantiveUpdateAt = $this->normalizeNullableString($trustManifest['last_substantive_update_at'] ?? null);
            $nextReviewDueAt = $this->normalizeNullableString($trustManifest['next_review_due_at'] ?? null);
            $truthLastReviewedAt = $this->normalizeNullableString($truthLayer['truth_last_reviewed_at'] ?? null);

            $members[] = new CareerTrustFreshnessMember(
                canonicalSlug: $auditMember->canonicalSlug,
                reviewerStatus: $reviewerStatus,
                reviewedAt: $reviewedAt,
                lastSubstantiveUpdateAt: $lastSubstantiveUpdateAt,
                nextReviewDueAt: $nextReviewDueAt,
                truthLastReviewedAt: $truthLastReviewedAt,
                reviewDueKnown: $nextReviewDueAt !== null,
                reviewFreshnessBasis: $this->resolveFreshnessBasis(
                    nextReviewDueAt: $nextReviewDueAt,
                    reviewedAt: $reviewedAt,
                    lastSubstantiveUpdateAt: $lastSubstantiveUpdateAt,
                    truthLastReviewedAt: $truthLastReviewedAt,
                ),
                reviewStalenessState: $this->resolveStalenessState(
                    reviewerStatus: $reviewerStatus,
                    reviewedAt: $reviewedAt,
                    nextReviewDueAt: $nextReviewDueAt,
                ),
                signals: [
                    'has_review_timestamp' => $reviewedAt !== null,
                    'has_due_date' => $nextReviewDueAt !== null,
                    'has_substantive_update_timestamp' => $lastSubstantiveUpdateAt !== null,
                ],
            );
        }

        return new CareerTrustFreshnessAuthority(
            scope: $audit->scope,
            members: $members,
        );
    }

    private function resolveFreshnessBasis(
        ?string $nextReviewDueAt,
        ?string $reviewedAt,
        ?string $lastSubstantiveUpdateAt,
        ?string $truthLastReviewedAt,
    ): string {
        return match (true) {
            $nextReviewDueAt !== null => 'trust_manifest_next_review_due_at',
            $reviewedAt !== null => 'trust_manifest_reviewed_at',
            $lastSubstantiveUpdateAt !== null => 'trust_manifest_last_substantive_update_at',
            $truthLastReviewedAt !== null => 'truth_metric_reviewed_at',
            default => 'no_review_freshness_basis',
        };
    }

    private function resolveStalenessState(
        ?string $reviewerStatus,
        ?string $reviewedAt,
        ?string $nextReviewDueAt,
    ): string {
        if (
            $reviewedAt === null
            && ! isset(self::REVIEW_COMPLETED_STATUSES[strtolower((string) $reviewerStatus)])
        ) {
            return 'review_unreviewed';
        }

        if ($nextReviewDueAt === null) {
            return 'unknown_due_date';
        }

        $dueAt = strtotime($nextReviewDueAt);
        if ($dueAt === false) {
            return 'unknown_due_date';
        }

        return $dueAt > time() ? 'review_scheduled' : 'review_due';
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
