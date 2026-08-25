<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoAgentCouncilUiContract
{
    /**
     * SEO-PLATFORM-11 has not published a unified production read model.
     * Existing agents remain controlled capabilities, never content authority.
     *
     * @return array{
     *     state:string,
     *     access_level:string,
     *     capability_fields:list<string>,
     *     capabilities:list<never>,
     *     governance_steps:list<string>,
     *     policy_decision:null,
     *     trace:null,
     *     canary:null,
     *     circuit_breaker:null,
     *     rollback:null,
     *     read_only_gsc:true,
     *     search_submission_allowed:false
     * }
     */
    public static function unavailableSnapshot(): array
    {
        return [
            'state' => SeoOperationsUiState::PRODUCTION_UNPROVEN,
            'access_level' => 'l0_read_only',
            'capability_fields' => ['capability', 'inputs', 'outputs', 'tools', 'permissions', 'cost', 'stop_condition', 'current_state'],
            'capabilities' => [],
            'governance_steps' => ['orchestrator', 'policy_gateway', 'safety_review', 'canary', 'circuit_breaker', 'rollback'],
            'policy_decision' => null,
            'trace' => null,
            'canary' => null,
            'circuit_breaker' => null,
            'rollback' => null,
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
        ];
    }
}
