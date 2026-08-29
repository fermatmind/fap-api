<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Policy;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;

final class PolicyGatewayCouncilAdmissionGateway implements CouncilAdmissionGateway
{
    public function __construct(private readonly PolicyGatewayCallerGuard $gateway) {}

    public function admission(string $callerType, array $request): array
    {
        return $this->gateway->admission($callerType, $request);
    }
}
