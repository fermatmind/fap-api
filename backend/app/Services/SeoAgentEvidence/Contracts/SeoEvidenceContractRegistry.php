<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Contracts;

use RuntimeException;

final class SeoEvidenceContractRegistry
{
    public const CONTRACT_VERSION = 'seo.evidence_contract_manifest.v2';

    private const FILES = [
        'schemas/seo-evidence-bundle.v1.schema.json',
        'schemas/seo-evidence-dependency-snapshot.v1.schema.json',
        'schemas/seo-evidence-context.v1.schema.json',
        'schemas/seo-external-content-result.v1.schema.json',
        'policies/seo-query-privacy.v2.json',
        'policies/seo-private-negative-set.v2.json',
        'policies/seo-external-content-gateway.v2.json',
        'policies/seo-context-minimization.v2.json',
        'policies/seo-evidence-retention.v2.json',
    ];

    private const HISTORICAL_FILES = [
        'policies/seo-query-privacy.v1.json',
        'policies/seo-private-negative-set.v1.json',
        'policies/seo-external-content-gateway.v1.json',
        'policies/seo-context-minimization.v1.json',
        'policies/seo-evidence-retention.v1.json',
    ];

    public function __construct(private readonly SeoEvidenceCanonicalHasher $hasher) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $entries = [];
        $seen = [];
        foreach (self::FILES as $relative) {
            $path = resource_path('seo-agent/evidence/'.$relative);
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Evidence contract must be a JSON object.');
            }
            $id = $decoded['schema_id'] ?? $decoded['policy_id'] ?? null;
            $version = $decoded['schema_version'] ?? $decoded['policy_version'] ?? null;
            if (! is_string($id) || ! preg_match('/^seo\.[a-z0-9_.-]+$/', $id)
                || ! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                throw new RuntimeException('Evidence contract identity is invalid.');
            }
            if (isset($seen[$id])) {
                throw new RuntimeException('Duplicate evidence contract identity.');
            }
            $seen[$id] = true;
            $entries[] = [
                'id' => $id,
                'version' => $version,
                'path' => 'backend/resources/seo-agent/evidence/'.$relative,
                'hash' => $this->hasher->hash($decoded),
            ];
        }

        $root = resource_path('seo-agent/evidence');
        $actual = [];
        foreach (['schemas', 'policies'] as $dir) {
            foreach (glob($root.'/'.$dir.'/*.json') ?: [] as $path) {
                $actual[] = $dir.'/'.basename($path);
            }
        }
        sort($actual, SORT_STRING);
        $expected = [...self::FILES, ...self::HISTORICAL_FILES];
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('Unknown or missing evidence contract file.');
        }

        $manifest = [
            'schema_version' => self::CONTRACT_VERSION,
            'manifest_version' => '2.0.0',
            'contracts' => $entries,
            'negative_guarantees' => [
                'changes_11a_registry' => false,
                'model_invocation' => false,
                'tool_invocation' => false,
                'agent_write_authority' => false,
                'search_submission' => false,
                'v1_contract_bytes_changed' => false,
            ],
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest): bool
    {
        return isset($manifest['manifest_hash'])
            && is_string($manifest['manifest_hash'])
            && hash_equals($this->hasher->hashWithout($manifest, 'manifest_hash'), $manifest['manifest_hash'])
            && hash_equals($this->manifest()['manifest_hash'], $manifest['manifest_hash']);
    }
}
