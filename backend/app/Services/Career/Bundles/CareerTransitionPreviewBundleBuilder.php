<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerTransitionPreviewBundle;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Support\Collection;

final class CareerTransitionPreviewBundleBuilder
{
    public function __construct(
        private readonly CareerTransitionPreviewReadinessLookup $readinessLookup,
    ) {}

    public function buildByType(string $type): ?CareerTransitionPreviewBundle
    {
        $requestedType = trim($type);
        $normalizedType = strtoupper($requestedType);
        if ($normalizedType === '') {
            return null;
        }

        $canonicalType = strtoupper(strtok($normalizedType, '-') ?: $normalizedType);

        $paths = $this->matchingTransitionPaths($normalizedType, $canonicalType);
        foreach ($paths as $path) {
            $bundle = $this->buildFromPath($path);
            if ($bundle instanceof CareerTransitionPreviewBundle) {
                return $bundle;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, TransitionPath>
     */
    private function matchingTransitionPaths(string $normalizedType, string $canonicalType): Collection
    {
        /** @var Collection<int, TransitionPath> $paths */
        $paths = TransitionPath::query()
            ->with([
                'recommendationSnapshot.profileProjection',
                'recommendationSnapshot.contextSnapshot',
                'recommendationSnapshot.compileRun',
                'toOccupation',
            ])
            ->whereHas('recommendationSnapshot', static function ($query): void {
                $query->whereNotNull('compiled_at')
                    ->whereNotNull('compile_run_id');
            })
            ->whereHas('recommendationSnapshot.contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('recommendationSnapshot.profileProjection', function ($query) use ($normalizedType, $canonicalType): void {
                $query->where('projection_payload->materialization', 'career_first_wave')
                    ->where(function ($inner) use ($normalizedType, $canonicalType): void {
                        $inner->where('projection_payload->recommendation_subject_meta->type_code', $normalizedType)
                            ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $normalizedType)
                            ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $canonicalType)
                            ->orWhere('projection_payload->recommendation_subject_meta->public_route_slug', strtolower($normalizedType))
                            ->orWhere('projection_payload->recommendation_subject_meta->public_route_slug', strtolower($canonicalType));
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $paths;
    }

    private function buildFromPath(TransitionPath $path): ?CareerTransitionPreviewBundle
    {
        $snapshot = $path->recommendationSnapshot;
        $targetOccupation = $path->toOccupation;

        if (! $snapshot instanceof RecommendationSnapshot || $targetOccupation === null) {
            return null;
        }

        $pathType = trim((string) $path->path_type);
        if ($pathType === '') {
            return null;
        }

        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $claimPermissions = is_array($payload['claim_permissions'] ?? null) ? $payload['claim_permissions'] : [];
        if (($claimPermissions['allow_transition_recommendation'] ?? false) !== true) {
            return null;
        }

        $readiness = $this->readinessLookup->bySlug((string) $targetOccupation->canonical_slug);
        if ($readiness === null) {
            return null;
        }

        if (($readiness['status'] ?? null) !== 'publish_ready' || ($readiness['index_eligible'] ?? false) !== true) {
            return null;
        }

        $scoreBundle = is_array($payload['score_bundle'] ?? null) ? $payload['score_bundle'] : [];
        $reasonCodes = is_array($claimPermissions['reason_codes'] ?? null) ? array_values($claimPermissions['reason_codes']) : [];
        $seoReasonCodes = is_array($readiness['reason_codes'] ?? null) ? array_values($readiness['reason_codes']) : [];

        return new CareerTransitionPreviewBundle(
            pathType: $pathType,
            targetJob: [
                'occupation_uuid' => $targetOccupation->id,
                'canonical_slug' => $targetOccupation->canonical_slug,
                'title' => $targetOccupation->canonical_title_en,
            ],
            scoreSummary: [
                'mobility_score' => $this->compactScore($scoreBundle['mobility_score'] ?? null),
                'confidence_score' => $this->compactScore($scoreBundle['confidence_score'] ?? null),
            ],
            trustSummary: [
                'allow_transition_recommendation' => true,
                'reviewer_status' => $readiness['reviewer_status'] ?? null,
                'reason_codes' => $reasonCodes,
            ],
            seoContract: [
                'canonical_path' => '/career/jobs/'.$targetOccupation->canonical_slug,
                'canonical_target' => null,
                'index_state' => $readiness['index_state'] ?? null,
                'index_eligible' => true,
                'reason_codes' => $seoReasonCodes,
            ],
            provenanceMeta: [
                'recommendation_snapshot_id' => $snapshot->id,
                'transition_path_id' => $path->id,
                'compiler_version' => $snapshot->compiler_version,
                'compiled_at' => optional($snapshot->compiled_at)->toISOString(),
                'compile_run_id' => $snapshot->compile_run_id,
                'import_run_id' => $snapshot->compileRun?->import_run_id,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compactScore(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'value' => null,
                'integrity_state' => null,
                'band' => null,
            ];
        }

        return [
            'value' => $value['value'] ?? null,
            'integrity_state' => $value['integrity_state'] ?? null,
            'band' => $value['band'] ?? null,
        ];
    }
}
