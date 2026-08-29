<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Policy;

interface CouncilAdmissionGateway
{
    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function admission(string $callerType, array $request): array;
}
