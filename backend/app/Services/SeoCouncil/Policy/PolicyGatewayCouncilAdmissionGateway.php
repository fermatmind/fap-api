<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Policy;

use App\Services\SeoAgentPolicyGateway\PolicyDecisionFactory;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Platform12ReadOnlyRuntimeGate;

final class PolicyGatewayCouncilAdmissionGateway implements CouncilAdmissionGateway
{
    public function __construct(
        private readonly PolicyGatewayCallerGuard $gateway,
        private readonly PolicyDecisionFactory $decisions,
        private readonly RuntimeCapabilitySnapshotBuilder $runtime,
        private readonly Platform12ReadOnlyRuntimeGate $readOnlyGate,
    ) {}

    public function admission(string $callerType, array $request): array
    {
        $snapshot = $this->runtime->snapshot();
        $policyRequest = $request;
        if (($request['autonomy'] ?? null) === 'L1'
            && ($snapshot['read_only_runtime_test_switch'] ?? null) === true
            && app()->environment('testing')) {
            $policyRequest['autonomy'] = 'L0';
        }
        $decision = $this->gateway->admission($callerType, $policyRequest);
        if (($decision['decision'] ?? null) !== 'HOLD'
            || ($decision['reason_codes'] ?? null) !== ['ROLE_CAPABILITY_BINDING_UNAVAILABLE']) {
            return $decision;
        }
        if (($snapshot['read_only_runtime_test_switch'] ?? null) !== true || ! app()->environment('testing')) {
            return $decision;
        }

        $scope = [
            'family' => (string) ($request['family'] ?? 'family:withheld'),
            'locale' => (string) ($request['locale'] ?? 'und'),
            'action' => null,
        ];
        $roleId = (string) ($request['requested_role_id'] ?? 'role:withheld');
        if (! $this->readOnlyGate->admits($request, $snapshot)) {
            $reason = ($snapshot['read_only_runtime_reason'] ?? null) === 'CAPABILITY_VERSION_DRIFT'
                ? 'CAPABILITY_VERSION_DRIFT'
                : 'READ_ONLY_RUNTIME_HOLD';

            return $this->decisions->make('admission', 'HOLD', [$reason], $roleId, $scope);
        }

        return $this->decisions->make('admission', 'ALLOW', ['READ_ONLY_RUNTIME_ADMITTED'], $roleId, $scope);
    }
}
