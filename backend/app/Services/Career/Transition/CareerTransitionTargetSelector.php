<?php

declare(strict_types=1);

namespace App\Services\Career\Transition;

use App\Domain\Career\ReviewerStatus;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Support\Collection;

final class CareerTransitionTargetSelector
{
    /**
     * @var list<string>
     */
    private const SAFE_CROSSWALK_MODES = ['exact', 'direct_match', 'trust_inheritance'];

    public function __construct(
        private readonly CareerTransitionPreviewReadinessLookup $readinessLookup,
    ) {}

    public function selectForSnapshot(RecommendationSnapshot $snapshot): ?Occupation
    {
        $sourceOccupation = $snapshot->occupation;
        if (! $sourceOccupation instanceof Occupation) {
            return null;
        }

        /** @var Collection<int, Occupation> $candidates */
        $candidates = Occupation::query()
            ->where('family_id', $sourceOccupation->family_id)
            ->where('entity_level', $sourceOccupation->entity_level)
            ->whereKeyNot($sourceOccupation->id)
            ->get();

        /** @var Collection<int, Occupation> $eligible */
        $eligible = $candidates
            ->filter(function (Occupation $candidate): bool {
                return $this->targetReadinessRow($candidate) !== null;
            })
            ->sort(function (Occupation $left, Occupation $right): int {
                return $this->compareCandidates($left, $right);
            })
            ->values();

        /** @var Occupation|null $selected */
        $selected = $eligible->first();

        return $selected;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function targetReadinessRow(Occupation $candidate): ?array
    {
        $readiness = $this->readinessLookup->bySlug((string) $candidate->canonical_slug);
        if (! is_array($readiness)) {
            return null;
        }

        if (($readiness['status'] ?? null) !== 'publish_ready') {
            return null;
        }

        if (($readiness['index_eligible'] ?? false) !== true) {
            return null;
        }

        $reviewerStatus = strtolower(trim((string) ($readiness['reviewer_status'] ?? '')));
        if ($reviewerStatus !== ReviewerStatus::APPROVED) {
            return null;
        }

        $crosswalkMode = strtolower(trim((string) ($readiness['crosswalk_mode'] ?? $candidate->crosswalk_mode ?? '')));
        if (! in_array($crosswalkMode, self::SAFE_CROSSWALK_MODES, true)) {
            return null;
        }

        return $readiness;
    }

    private function compareCandidates(Occupation $left, Occupation $right): int
    {
        $leftCrosswalkRank = $this->crosswalkRank($left);
        $rightCrosswalkRank = $this->crosswalkRank($right);
        if ($leftCrosswalkRank !== $rightCrosswalkRank) {
            return $leftCrosswalkRank <=> $rightCrosswalkRank;
        }

        $leftTitle = strtolower(trim((string) $left->canonical_title_en));
        $rightTitle = strtolower(trim((string) $right->canonical_title_en));
        if ($leftTitle !== $rightTitle) {
            return $leftTitle <=> $rightTitle;
        }

        $leftSlug = strtolower(trim((string) $left->canonical_slug));
        $rightSlug = strtolower(trim((string) $right->canonical_slug));
        if ($leftSlug !== $rightSlug) {
            return $leftSlug <=> $rightSlug;
        }

        return strcmp((string) $left->id, (string) $right->id);
    }

    private function crosswalkRank(Occupation $candidate): int
    {
        $row = $this->targetReadinessRow($candidate);
        $mode = strtolower(trim((string) ($row['crosswalk_mode'] ?? $candidate->crosswalk_mode ?? '')));

        return match ($mode) {
            'exact' => 0,
            'direct_match' => 1,
            'trust_inheritance' => 2,
            default => 9,
        };
    }
}
