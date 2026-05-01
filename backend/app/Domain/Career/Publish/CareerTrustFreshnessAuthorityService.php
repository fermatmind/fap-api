<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerTrustFreshnessAuthority;
use App\DTO\Career\CareerTrustFreshnessMember;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use Carbon\CarbonInterface;

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
    ) {}

    public function build(): CareerTrustFreshnessAuthority
    {
        $audit = $this->launchReadinessAuditService->build();
        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $member): string => $member instanceof \App\DTO\Career\CareerFirstWaveLaunchReadinessAuditMember
                ? trim((string) $member->canonicalSlug)
                : '',
            $audit->members,
        ))));
        $profiles = $this->freshnessProfilesBySlug($slugs);
        $members = [];

        foreach ($audit->members as $auditMember) {
            $profile = $profiles[$auditMember->canonicalSlug] ?? [];

            $reviewerStatus = $this->normalizeNullableString($profile['reviewer_status'] ?? null);
            $reviewedAt = $this->normalizeDateString($profile['reviewed_at'] ?? null);
            $lastSubstantiveUpdateAt = $this->normalizeDateString($profile['last_substantive_update_at'] ?? null);
            $nextReviewDueAt = $this->normalizeDateString($profile['next_review_due_at'] ?? null);
            $truthLastReviewedAt = $this->normalizeDateString($profile['truth_last_reviewed_at'] ?? null);

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

    /**
     * @param  list<string>  $slugs
     * @return array<string, array<string, mixed>>
     */
    private function freshnessProfilesBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->get(['id', 'canonical_slug']);

        $slugByOccupationId = [];
        foreach ($occupations as $occupation) {
            $occupationId = (string) $occupation->id;
            $slug = trim((string) $occupation->canonical_slug);
            if ($occupationId === '' || $slug === '') {
                continue;
            }

            $slugByOccupationId[$occupationId] = $slug;
        }

        $profiles = [];
        foreach ($slugs as $slug) {
            $profiles[$slug] = [];
        }

        if ($slugByOccupationId === []) {
            return $profiles;
        }

        $occupationIds = array_keys($slugByOccupationId);

        $seenSnapshot = [];
        RecommendationSnapshot::query()
            ->with(['trustManifest', 'truthMetric'])
            ->whereIn('occupation_id', $occupationIds)
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->orderBy('occupation_id')
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'occupation_id',
                'trust_manifest_id',
                'truth_metric_id',
                'compiled_at',
                'created_at',
            ])
            ->each(function (RecommendationSnapshot $snapshot) use (&$profiles, &$seenSnapshot, $slugByOccupationId): void {
                $occupationId = (string) $snapshot->occupation_id;
                if (isset($seenSnapshot[$occupationId])) {
                    return;
                }

                $slug = $slugByOccupationId[$occupationId] ?? null;
                if ($slug === null || ! isset($profiles[$slug])) {
                    return;
                }

                $seenSnapshot[$occupationId] = true;
                $manifest = $snapshot->trustManifest;
                $truthMetric = $snapshot->truthMetric;

                $profiles[$slug]['reviewer_status'] = $manifest?->reviewer_status;
                $profiles[$slug]['reviewed_at'] = $manifest?->reviewed_at;
                $profiles[$slug]['last_substantive_update_at'] = $manifest?->last_substantive_update_at;
                $profiles[$slug]['next_review_due_at'] = $manifest?->next_review_due_at;
                $profiles[$slug]['truth_last_reviewed_at'] = $truthMetric?->reviewed_at;
            });

        return $profiles;
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

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        return $this->normalizeNullableString($value);
    }
}
