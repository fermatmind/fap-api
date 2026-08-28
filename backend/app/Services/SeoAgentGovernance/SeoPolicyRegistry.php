<?php

declare(strict_types=1);

namespace App\Services\SeoAgentGovernance;

use JsonException;
use RuntimeException;

final class SeoPolicyRegistry
{
    private const POLICIES = [
        'seo.execution_hold.v1.json',
        'seo.evidence_boundary.v1.json',
        'seo.release_separation.v1.json',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /**
     * @return array<string, array{id:string,version:string,hash:string,path:string}>
     * @throws JsonException
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (self::POLICIES as $file) {
            $path = resource_path('seo-agent/policies/'.$file);
            $bytes = file_get_contents($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('SEO policy is unreadable: '.$file);
            }

            $policy = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($policy) || ! is_string($policy['policy_id'] ?? null) || ! is_string($policy['policy_version'] ?? null)) {
                throw new RuntimeException('SEO policy identity is invalid: '.$file);
            }

            $definitions[$policy['policy_id']] = [
                'id' => $policy['policy_id'],
                'version' => $policy['policy_version'],
                'hash' => $this->hasher->hash($policy),
                'path' => 'backend/resources/seo-agent/policies/'.$file,
            ];
        }

        return $definitions;
    }
}
