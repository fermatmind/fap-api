<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Routing;

use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;

final class DeterministicMissionRouter
{
    public function __construct(private readonly RoleCapabilityBindingRegistry $binding) {}

    /** @return array{status:string,roles:list<string>,max_modes:int,all_team:bool,binding_ref:array{id:string,version:string,hash:string}} */
    public function route(MissionRequestData $request): array
    {
        $bindingRef = $this->binding->reference();
        $mission = $this->binding->mission((string) $request->payload['mission_type']);
        $maxModes = (int) $mission['max_modes'];
        $hold = static fn (string $status): array => [
            'status' => $status,
            'roles' => [],
            'max_modes' => $maxModes,
            'all_team' => false,
            'binding_ref' => $bindingRef,
        ];

        if (! in_array($request->payload['family'], (array) $mission['allowed_page_families'], true)
            || ! in_array($request->payload['locale'], (array) $mission['allowed_locales'], true)) {
            return $hold('MISSION_SCOPE_HOLD');
        }

        $variant = isset($mission['selector'])
            ? $this->binding->selectorVariant($mission, $request->payload['review_domain'])
            : null;
        if (isset($mission['selector']) && $variant === null) {
            return $hold('MISSION_SCOPE_HOLD');
        }

        $requiredEvidence = (array) ($variant['required_evidence'] ?? $mission['required_evidence']);
        $evidenceTypes = array_values(array_unique(array_map(
            static fn (array $ref): string => (string) $ref['evidence_type'],
            (array) $request->payload['evidence_bundle_refs'],
        )));
        if (array_diff($requiredEvidence, $evidenceTypes) !== []) {
            return $hold('EVIDENCE_HOLD');
        }

        if (is_array($variant)) {
            $roles = array_values((array) $variant['eligible_roles']);
        } else {
            $roles = array_values((array) $mission['route_rule']['base_roles']);
            foreach ((array) $mission['route_rule']['conditional_roles'] as $conditional) {
                $required = (array) $conditional['evidence_types'];
                $eligible = ($conditional['role_id'] ?? null) === 'seo.expert.competitor_research'
                    ? array_diff($required, $evidenceTypes) === []
                    : array_intersect($evidenceTypes, $required) !== [];
                if ($eligible) {
                    $roles[] = (string) $conditional['role_id'];
                }
            }
            $roles = array_values(array_unique($roles));
        }

        $requestedRole = $request->payload['requested_role'];
        if ($requestedRole !== null && ! in_array($requestedRole, $roles, true)) {
            return $hold('REQUESTED_ROLE_EXPANSION_HOLD');
        }
        if ($requestedRole !== null && ! is_array($variant) && ($mission['route_rule']['all_team'] ?? false) !== true) {
            $roles = array_values(array_unique([
                ...(array) $mission['route_rule']['base_roles'],
                $requestedRole,
            ]));
        }
        if ($roles === [] || count($roles) > $maxModes) {
            return $hold('ROUTING_SCOPE_HOLD');
        }

        return [
            'status' => 'SOURCE_CAPABILITY_UNAVAILABLE',
            'roles' => $roles,
            'max_modes' => $maxModes,
            'all_team' => ($mission['route_rule']['all_team'] ?? false) === true,
            'binding_ref' => $bindingRef,
        ];
    }
}
