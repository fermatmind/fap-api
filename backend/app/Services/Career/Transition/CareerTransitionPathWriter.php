<?php

declare(strict_types=1);

namespace App\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Domain\Career\Transition\TransitionPathType;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Support\Facades\DB;

final class CareerTransitionPathWriter
{
    public function __construct(
        private readonly CareerTransitionPreviewReadinessLookup $readinessLookup,
        private readonly CareerTransitionStepAuthor $stepAuthor,
    ) {}

    public function rewriteForSnapshot(RecommendationSnapshot $snapshot): int
    {
        return DB::transaction(function () use ($snapshot): int {
            TransitionPath::query()
                ->where('recommendation_snapshot_id', $snapshot->id)
                ->delete();

            if (! $this->isEligibleSourceSnapshot($snapshot)) {
                return 0;
            }

            $sourceOccupation = $snapshot->occupation;
            if (! $sourceOccupation instanceof Occupation) {
                return 0;
            }

            $readiness = $this->readinessLookup->bySlug($sourceOccupation->canonical_slug);
            if (! is_array($readiness)) {
                return 0;
            }

            if (($readiness['status'] ?? null) !== 'publish_ready' || ($readiness['index_eligible'] ?? false) !== true) {
                return 0;
            }

            $pathPayload = TransitionPathPayload::from([
                'steps' => $this->stepAuthor->authorForOccupation($sourceOccupation),
            ]);

            TransitionPath::query()->create([
                'recommendation_snapshot_id' => $snapshot->id,
                'from_occupation_id' => $sourceOccupation->id,
                'to_occupation_id' => $sourceOccupation->id,
                'path_type' => TransitionPathType::StableUpside->value,
                'path_payload' => $pathPayload->toArray(),
            ]);

            return 1;
        });
    }

    private function isEligibleSourceSnapshot(RecommendationSnapshot $snapshot): bool
    {
        if ($snapshot->compiled_at === null || ! is_string($snapshot->compile_run_id) || $snapshot->compile_run_id === '') {
            return false;
        }

        $contextPayload = is_array($snapshot->contextSnapshot?->context_payload)
            ? $snapshot->contextSnapshot->context_payload
            : [];
        if (($contextPayload['materialization'] ?? null) !== 'career_first_wave') {
            return false;
        }

        $projectionPayload = is_array($snapshot->profileProjection?->projection_payload)
            ? $snapshot->profileProjection->projection_payload
            : [];
        if (($projectionPayload['materialization'] ?? null) !== 'career_first_wave') {
            return false;
        }

        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $claimPermissions = is_array($payload['claim_permissions'] ?? null) ? $payload['claim_permissions'] : [];

        return ($claimPermissions['allow_transition_recommendation'] ?? false) === true;
    }
}
