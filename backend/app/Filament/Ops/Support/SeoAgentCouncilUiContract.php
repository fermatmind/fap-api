<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;

final class SeoAgentCouncilUiContract
{
    /**
     * The frozen registry is metadata only. Runtime evidence and actions stay unavailable.
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
     *     registry_metadata:array{registry_id:string,registry_version:string,registry_status:string,registry_hash:string,owner_repository:string,role_count:int,capability_count:int}
     * }
     */
    public static function unavailableSnapshot(): array
    {
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();

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
            'registry_metadata' => [
                'registry_id' => $registry['registry_id'],
                'registry_version' => $registry['registry_version'],
                'registry_status' => $registry['registry_status'],
                'registry_hash' => $registry['registry_hash'],
                'owner_repository' => $registry['owner_repository'],
                'role_count' => count($registry['roles']),
                'capability_count' => count($registry['capabilities']),
            ],
        ];
    }
}
