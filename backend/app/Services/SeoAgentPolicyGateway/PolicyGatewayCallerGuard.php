<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

final class PolicyGatewayCallerGuard
{
    public function __construct(
        private readonly PolicyGatewayRegistry $registry,
        private readonly AdmissionPolicy $admission,
        private readonly ExecutionPolicy $execution,
        private readonly PolicyDecisionFactory $decisions,
    ) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function admission(string $callerType, array $request): array
    {
        if (! in_array($callerType, $this->registry->callerTypes(), true)) {
            return $this->decisions->make('admission', 'DENY', ['CALLER_TYPE_DENIED']);
        }

        return $this->admission->decide($request, $callerType);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function execution(string $callerType, array $request): array
    {
        if (! in_array($callerType, $this->registry->callerTypes(), true)) {
            return $this->decisions->make('execution', 'DENY', ['CALLER_TYPE_DENIED']);
        }

        return $this->execution->decide($request, $callerType);
    }
}
