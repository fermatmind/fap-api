<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use RuntimeException;

final class PolicyGatewayAssetLoader
{
    /** @return array<string, mixed> */
    public function load(string $relativePath): array
    {
        $path = resource_path('seo-agent/policy-gateway/'.$relativePath);
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('Policy Gateway asset is unreadable.');
        }

        $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Policy Gateway asset must be a JSON object.');
        }

        return $payload;
    }
}
