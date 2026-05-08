<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalRolloutBatchStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED => [
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
            CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED,
        ],
        CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE => [
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
            CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED,
            CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED,
        ],
        CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED => [
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
            CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED,
        ],
        CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED => [
            CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED,
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function transition(array $manifestPayload, string $targetState, ?array $governanceResult = null): array
    {
        $manifest = is_array($manifestPayload['manifest'] ?? null) ? $manifestPayload['manifest'] : $manifestPayload;
        $currentState = (string) ($manifest['rollout_state'] ?? '');
        $targetState = trim($targetState);
        $failures = [];

        if (! in_array($currentState, CanonicalExpansionManifestService::ALLOWED_ROLLOUT_STATES, true)) {
            $failures[] = $this->failure('invalid_current_state', $currentState, $targetState);
        }
        if (! in_array($targetState, CanonicalExpansionManifestService::ALLOWED_ROLLOUT_STATES, true)) {
            $failures[] = $this->failure('invalid_target_state', $currentState, $targetState);
        }
        if ($failures === [] && ! in_array($targetState, self::ALLOWED_TRANSITIONS[$currentState] ?? [], true)) {
            $failures[] = $this->failure('transition_not_allowed', $currentState, $targetState);
        }

        $governanceStatus = (string) ($governanceResult['status'] ?? 'not_provided');
        if ($targetState === CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED && $governanceStatus !== 'pass') {
            $failures[] = $this->failure('publish_requires_governance_pass', $currentState, $targetState);
        }

        $updatedManifest = $manifest;
        if ($failures === []) {
            $updatedManifest['rollout_state'] = $targetState;
            $updatedManifest['projection_state'] = match ($targetState) {
                CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
                CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED => CareerRuntimePublishProjectionService::STATE_QUARANTINED,
                CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED => CareerRuntimePublishProjectionService::STATE_BLOCKED,
                default => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            };
        }

        return [
            'status' => $failures === [] ? 'planned' : 'blocked',
            'read_only' => true,
            'writes_database' => false,
            'current_state' => $currentState,
            'target_state' => $targetState,
            'allowed_states' => CanonicalExpansionManifestService::ALLOWED_ROLLOUT_STATES,
            'published_candidate_semantics' => [
                'state' => 'expected_pre_route_inventory',
                'detail_api_404' => 'expected_pre_route',
                'frontend_route_404' => 'expected_pre_route',
                'public_release_gate_route_validation' => 'not_applicable_before_promotion',
                'public_exposure_failure_condition' => 'published_candidate_visible_on_any_public_runtime_surface',
            ],
            'updated_manifest' => $updatedManifest,
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function failure(string $reason, string $currentState, string $targetState): array
    {
        return [
            'reason' => $reason,
            'current_state' => $currentState,
            'target_state' => $targetState,
        ];
    }
}
