<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayStatusProjection;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;

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
     *     policy_mode:string,
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
        $gateway = app(PolicyGatewayStatusProjection::class)->snapshot();
        $runtime = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
        $binding = app(RoleCapabilityBindingRegistry::class)->reference();

        return [
            'state' => SeoOperationsUiState::DEPLOYED_DISABLED,
            'policy_mode' => $gateway['mode'],
            'runtime_mode' => $runtime['runtime_mode'],
            'access_level' => 'l0_read_only',
            'capability_fields' => ['capability', 'inputs', 'outputs', 'tools', 'permissions', 'cost', 'stop_condition', 'current_state'],
            'capabilities' => [],
            'governance_steps' => ['orchestrator', 'policy_gateway', 'binding', 'route_plan', 'independent_review', 'receipt'],
            'policy_decision' => $gateway['decision'],
            'trace' => null,
            'canary' => null,
            'circuit_breaker' => null,
            'rollback' => null,
            'read_only_gsc' => $gateway['read_only_gsc'],
            'search_submission_allowed' => $gateway['search_submission_allowed'],
            'global_guards' => $gateway['global_guards'],
            'active_manifest_count' => $gateway['active_manifest_count'],
            'trusted_signing_key_count' => $gateway['trusted_signing_key_count'],
            'binding_metadata' => $binding,
            'mission_submission_enabled' => true,
            'mission_execution_enabled' => false,
            'registry_metadata' => [
                'registry_id' => $gateway['registry']['id'],
                'registry_version' => $gateway['registry']['version'],
                'registry_status' => $gateway['registry']['state'],
                'registry_hash' => $gateway['registry']['hash'],
                'owner_repository' => 'fap-api',
                'role_count' => 9,
                'capability_count' => 20,
            ],
        ];
    }
}
