<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

interface CompetitiveGatewayReader
{
    /** @param array<string, mixed> $context @param array<string, mixed> $semantic @return array<string, mixed> */
    public function fetch(string $sourceId, string $url, array $context, array $semantic): array;
}
