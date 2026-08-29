<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Carbon\CarbonImmutable;

final class PolicyDecisionFactory
{
    public function __construct(
        private readonly PolicyGatewayRegistry $registry,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param list<string> $reasonCodes @param array{family:string,locale:string,action:?string} $scope @return array<string, mixed> */
    public function make(
        string $stage,
        string $decision,
        array $reasonCodes,
        string $roleId = 'role:withheld',
        array $scope = ['family' => 'family:withheld', 'locale' => 'und', 'action' => null],
    ): array {
        $registry = $this->registry->registry();
        $reasonCodes = array_values(array_unique($reasonCodes));
        sort($reasonCodes, SORT_STRING);
        $evaluatedAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $decisionId = $this->hasher->hash([
            'stage' => $stage,
            'decision' => $decision,
            'reason_codes' => $reasonCodes,
            'registry_hash' => $registry['registry_hash'],
            'role_id' => $roleId,
            'scope' => $scope,
            'evaluated_at' => $evaluatedAt,
        ]);
        $payload = [
            'schema_version' => 'seo.policy_decision.v1',
            'decision_id' => $decisionId,
            'stage' => $stage,
            'decision' => $decision,
            'reason_codes' => $reasonCodes,
            'policy_registry_id' => $registry['registry_id'],
            'policy_registry_version' => $registry['registry_version'],
            'policy_registry_hash' => $registry['registry_hash'],
            'effective_role_id' => $roleId,
            'effective_scope' => $scope,
            'execution_allowed' => false,
            'write_allowed' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'egress_allowed' => false,
            'evaluated_at' => $evaluatedAt,
            'negative_guarantees' => [
                'production_allow' => false,
                'business_write' => false,
                'cms_write' => false,
                'url_truth_write' => false,
                'search_submission' => false,
                'untrusted_value_echo' => false,
            ],
        ];
        $payload['decision_hash'] = $this->hasher->hash($payload);

        return $payload;
    }
}
