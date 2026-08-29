<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

interface ExternalContentTransport
{
    /** @return array{status:int,headers:array<string,string>,body:string,connected_ip:string} */
    public function request(string $method, string $url, string $approvedIp, int $connectTimeoutSeconds, int $requestTimeoutSeconds, int $maxBytes): array;
}
