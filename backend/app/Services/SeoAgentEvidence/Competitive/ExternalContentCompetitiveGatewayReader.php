<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\External\ExternalContentGateway;

final class ExternalContentCompetitiveGatewayReader implements CompetitiveGatewayReader
{
    public function __construct(private readonly ExternalContentGateway $gateway) {}

    public function fetch(string $sourceId, string $url, array $context, array $semantic): array
    {
        return $this->gateway->fetchCompetitive($sourceId, $url, $context, $semantic);
    }
}
