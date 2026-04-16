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

final class CareerLifecycleOperationalSummaryService
{
    public const SUMMARY_KIND = 'career_lifecycle_operational_summary';

    public const SUMMARY_VERSION = 'career.lifecycle.operational.v1';

    public const SCOPE = 'career_all_342';

    public function build(): CareerLifecycleOperationalSummary
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

        foreach ($this->trackedSlugs() as $slugValue) {
            $slug = trim(strtolower((string) $slugValue));
            if ($slug === '') {
                continue;
            }

            $member = $this->buildMember($slug);
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
        } catch (\Throwable) {
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
        $member = $this->buildMember($slug);

        return $member?->toArray() ?? [
            'member_kind' => 'career_tracked_occupation',
            'canonical_slug' => trim(strtolower($slug)),
            'current_projection_uuid' => null,
            'current_recommendation_snapshot_uuid' => null,
            'timeline_entry_count' => 0,
            'latest_feedback_at' => null,
            'delta_available' => false,
            'lifecycle_state' => 'baseline_only',
            'closure_state' => 'baseline_only',
        ];
    }

    private function buildMember(string $slug): ?CareerLifecycleOperationalMember
    {
        $normalizedSlug = trim(strtolower($slug));
        if ($normalizedSlug === '') {
            return null;
        }

        $occupation = Occupation::query()
            ->where('canonical_slug', $normalizedSlug)
            ->first();

        $snapshotCount = 0;
        $currentSnapshot = null;
        if ($occupation instanceof Occupation) {
            $snapshotCount = RecommendationSnapshot::query()
                ->where('occupation_id', $occupation->id)
                ->count();

            $currentSnapshot = RecommendationSnapshot::query()
                ->where('occupation_id', $occupation->id)
                ->orderByDesc('compiled_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $latestFeedbackAt = CareerFeedbackRecord::query()
            ->where('subject_kind', 'recommendation_type')
            ->where('subject_slug', $normalizedSlug)
            ->orderByDesc('created_at')
            ->value('created_at');

        $hasShortlist = CareerShortlistItem::query()
            ->where('subject_kind', 'job_slug')
            ->where('subject_slug', $normalizedSlug)
            ->exists();

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
            currentProjectionUuid: is_string($currentSnapshot?->profile_projection_id)
                ? $currentSnapshot->profile_projection_id
                : null,
            currentRecommendationSnapshotUuid: is_string($currentSnapshot?->id)
                ? $currentSnapshot->id
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
}
