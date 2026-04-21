<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerLifecycleOperationalMember;
use App\DTO\Career\CareerLifecycleOperationalSummary;
use App\Models\CareerFeedbackRecord;
use App\Models\CareerShortlistItem;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CareerLifecycleOperationalSummaryService
{
    public const SUMMARY_KIND = 'career_lifecycle_operational_summary';

    public const SUMMARY_VERSION = 'career.lifecycle.operational.v1';

    public const SCOPE = 'career_all_342';

    public function build(): CareerLifecycleOperationalSummary
    {
        return $this->buildForTrackedSlugs($this->trackedSlugs());
    }

    /**
     * @param  list<string>  $slugs
     */
    public function buildForTrackedSlugs(array $slugs): CareerLifecycleOperationalSummary
    {
        $counts = [
            'total' => 0,
            'with_feedback' => 0,
            'without_feedback' => 0,
            'with_multiple_snapshots' => 0,
            'timeline_active' => 0,
            'delta_available' => 0,
            'conversion_ready' => 0,
        ];

        $members = [];
        $trackedSlugs = $this->normalizeSlugs($slugs);
        $profiles = $this->buildMemberProfiles($trackedSlugs);

        foreach ($trackedSlugs as $slug) {
            $member = $this->buildMemberFromProfile($slug, $profiles[$slug] ?? []);
            if (! $member instanceof CareerLifecycleOperationalMember) {
                continue;
            }

            $counts['total']++;
            if ($member->latestFeedbackAt !== null) {
                $counts['with_feedback']++;
            } else {
                $counts['without_feedback']++;
            }
            if ($member->timelineEntryCount >= 2) {
                $counts['with_multiple_snapshots']++;
            }
            if ($member->lifecycleState === 'timeline_active') {
                $counts['timeline_active']++;
            }
            if ($member->deltaAvailable) {
                $counts['delta_available']++;
            }
            if ($member->closureState === 'conversion_ready') {
                $counts['conversion_ready']++;
            }

            $members[] = $member;
        }

        usort($members, static fn (CareerLifecycleOperationalMember $left, CareerLifecycleOperationalMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        return new CareerLifecycleOperationalSummary(
            summaryKind: self::SUMMARY_KIND,
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            members: $members,
        );
    }

    /**
     * @return list<string>
     */
    private function trackedSlugs(): array
    {
        try {
            $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
            $slugs = array_values(array_filter(array_map(
                static fn (mixed $member): ?string => is_array($member)
                    ? trim(strtolower((string) ($member['canonical_slug'] ?? '')))
                    : null,
                (array) ($ledger['members'] ?? []),
            )));

            if ($slugs !== []) {
                return array_values(array_unique($slugs));
            }
        } catch (\Throwable $e) {
            unset($e);
            // Fallback keeps the authority operational even if ledger bootstrapping fails in local/dev.
        }

        return Occupation::query()
            ->orderBy('canonical_slug')
            ->pluck('canonical_slug')
            ->map(static fn (string $slug): string => trim(strtolower($slug)))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForSlug(string $slug): array
    {
        $normalizedSlug = trim(strtolower($slug));
        $profiles = $this->buildMemberProfiles([$normalizedSlug]);
        $member = $this->buildMemberFromProfile($normalizedSlug, $profiles[$normalizedSlug] ?? []);

        return $member?->toArray() ?? [
            'member_kind' => 'career_tracked_occupation',
            'canonical_slug' => $normalizedSlug,
            'current_projection_uuid' => null,
            'current_recommendation_snapshot_uuid' => null,
            'timeline_entry_count' => 0,
            'latest_feedback_at' => null,
            'delta_available' => false,
            'lifecycle_state' => 'baseline_only',
            'closure_state' => 'baseline_only',
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array<string, mixed>>
     */
    private function buildMemberProfiles(array $slugs): array
    {
        $normalizedSlugs = $this->normalizeSlugs($slugs);
        if ($normalizedSlugs === []) {
            return [];
        }

        /** @var Collection<int, Occupation> $occupations */
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $normalizedSlugs)
            ->get(['id', 'canonical_slug']);

        $occupationIdBySlug = [];
        $slugByOccupationId = [];
        foreach ($occupations as $occupation) {
            $slug = trim(strtolower((string) $occupation->canonical_slug));
            $id = (string) $occupation->id;
            if ($slug === '' || $id === '') {
                continue;
            }

            $occupationIdBySlug[$slug] = $id;
            $slugByOccupationId[$id] = $slug;
        }

        $profiles = [];
        foreach ($normalizedSlugs as $slug) {
            $profiles[$slug] = [
                'snapshot_count' => 0,
                'current_projection_uuid' => null,
                'current_recommendation_snapshot_uuid' => null,
                'latest_feedback_at' => null,
                'has_shortlist' => false,
            ];
        }

        $occupationIds = array_values($occupationIdBySlug);
        if ($occupationIds !== []) {
            $snapshotCounts = RecommendationSnapshot::query()
                ->selectRaw('occupation_id, COUNT(*) as aggregate_count')
                ->whereIn('occupation_id', $occupationIds)
                ->groupBy('occupation_id')
                ->pluck('aggregate_count', 'occupation_id');

            foreach ($snapshotCounts as $occupationId => $snapshotCount) {
                $slug = $slugByOccupationId[(string) $occupationId] ?? null;
                if ($slug !== null && isset($profiles[$slug])) {
                    $profiles[$slug]['snapshot_count'] = (int) $snapshotCount;
                }
            }

            $seenLatest = [];
            RecommendationSnapshot::query()
                ->whereIn('occupation_id', $occupationIds)
                ->orderBy('occupation_id')
                ->orderByDesc('compiled_at')
                ->orderByDesc('created_at')
                ->get(['id', 'occupation_id', 'profile_projection_id', 'compiled_at', 'created_at'])
                ->each(function (RecommendationSnapshot $snapshot) use (&$profiles, &$seenLatest, $slugByOccupationId): void {
                    $occupationId = (string) $snapshot->occupation_id;
                    if (isset($seenLatest[$occupationId])) {
                        return;
                    }

                    $slug = $slugByOccupationId[$occupationId] ?? null;
                    if ($slug === null || ! isset($profiles[$slug])) {
                        return;
                    }

                    $seenLatest[$occupationId] = true;
                    $profiles[$slug]['current_projection_uuid'] = is_string($snapshot->profile_projection_id)
                        ? $snapshot->profile_projection_id
                        : null;
                    $profiles[$slug]['current_recommendation_snapshot_uuid'] = is_string($snapshot->id)
                        ? $snapshot->id
                        : null;
                });
        }

        $latestFeedbackBySlug = CareerFeedbackRecord::query()
            ->selectRaw('subject_slug, MAX(created_at) as latest_feedback_at')
            ->where('subject_kind', 'recommendation_type')
            ->whereIn('subject_slug', $normalizedSlugs)
            ->groupBy('subject_slug')
            ->pluck('latest_feedback_at', 'subject_slug');

        foreach ($latestFeedbackBySlug as $slug => $latestFeedbackAt) {
            $normalizedSlug = trim(strtolower((string) $slug));
            if ($normalizedSlug !== '' && isset($profiles[$normalizedSlug])) {
                $profiles[$normalizedSlug]['latest_feedback_at'] = $latestFeedbackAt;
            }
        }

        $shortlistSlugs = CareerShortlistItem::query()
            ->select('subject_slug')
            ->where('subject_kind', 'job_slug')
            ->whereIn('subject_slug', $normalizedSlugs)
            ->distinct()
            ->pluck('subject_slug');

        foreach ($shortlistSlugs as $slug) {
            $normalizedSlug = trim(strtolower((string) $slug));
            if ($normalizedSlug !== '' && isset($profiles[$normalizedSlug])) {
                $profiles[$normalizedSlug]['has_shortlist'] = true;
            }
        }

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function buildMemberFromProfile(string $slug, array $profile): ?CareerLifecycleOperationalMember
    {
        $normalizedSlug = trim(strtolower($slug));
        if ($normalizedSlug === '') {
            return null;
        }

        $snapshotCount = (int) ($profile['snapshot_count'] ?? 0);
        $latestFeedbackAt = $profile['latest_feedback_at'] ?? null;
        $hasShortlist = (bool) ($profile['has_shortlist'] ?? false);

        $hasFeedback = $latestFeedbackAt !== null;
        $deltaAvailable = $snapshotCount >= 2;

        $lifecycleState = match (true) {
            $snapshotCount >= 2 => 'timeline_active',
            $hasFeedback => 'feedback_active',
            default => 'baseline_only',
        };

        $closureState = match (true) {
            $deltaAvailable && $hasFeedback && $hasShortlist => 'conversion_ready',
            $hasFeedback => 'feedback_active',
            $snapshotCount >= 2 => 'timeline_active',
            default => 'baseline_only',
        };

        return new CareerLifecycleOperationalMember(
            memberKind: 'career_tracked_occupation',
            canonicalSlug: $normalizedSlug,
            currentProjectionUuid: is_string($profile['current_projection_uuid'] ?? null)
                ? $profile['current_projection_uuid']
                : null,
            currentRecommendationSnapshotUuid: is_string($profile['current_recommendation_snapshot_uuid'] ?? null)
                ? $profile['current_recommendation_snapshot_uuid']
                : null,
            timelineEntryCount: $snapshotCount,
            latestFeedbackAt: $latestFeedbackAt instanceof CarbonInterface
                ? $latestFeedbackAt->toISOString()
                : (is_string($latestFeedbackAt) ? $latestFeedbackAt : null),
            deltaAvailable: $deltaAvailable,
            lifecycleState: $lifecycleState,
            closureState: $closureState,
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function normalizeSlugs(array $slugs): array
    {
        $normalized = [];
        foreach ($slugs as $slug) {
            $value = trim(strtolower((string) $slug));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
