<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

interface ExternalDnsResolver
{
    /** @return list<string> */
    public function resolveAll(string $host): array;
}
