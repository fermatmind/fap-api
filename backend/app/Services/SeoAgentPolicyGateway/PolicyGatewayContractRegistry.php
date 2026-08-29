<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class PolicyGatewayContractRegistry
{
    private const SCHEMAS = [
        'schemas/seo.policy_admission_request.v1.schema.json',
        'schemas/seo.policy_execution_request.v1.schema.json',
        'schemas/seo.action_scoped_manifest.v1.schema.json',
        'schemas/seo.policy_decision.v1.schema.json',
        'schemas/seo.policy_gateway_contract_manifest.v1.schema.json',
    ];

    private const POLICIES = [
        'seo.policy_gateway_registry.v1.json',
        'seo.logical_field_catalog.v1.json',
        'seo.manifest_trust_registry.v1.json',
        'seo.manifest_revocation_registry.v1.json',
        'seo.policy_runtime_controls.v1.json',
    ];

    public function __construct(
        private readonly PolicyGatewayAssetLoader $assets,
        private readonly PolicyGatewayRegistry $registry,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $registry = $this->registry->registry();
        $manifest = [
            'schema_version' => 'seo.policy_gateway_contract_manifest.v1',
            'manifest_version' => '1.0.0',
            'registry_ref' => [
                'id' => $registry['registry_id'],
                'version' => $registry['registry_version'],
                'hash' => $registry['registry_hash'],
            ],
            'contracts' => array_map(fn (string $path): array => $this->entry($path, true), self::SCHEMAS),
            'policies' => array_map(fn (string $path): array => $this->entry($path, false), self::POLICIES),
            'negative_guarantees' => [
                'allow_enum_present' => false,
                'trusted_signing_key_count' => 0,
                'active_manifest_count' => 0,
                'model_invocation' => false,
                'tool_invocation' => false,
                'external_egress' => false,
                'business_write' => false,
            ],
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest): bool
    {
        return is_string($manifest['manifest_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($manifest, 'manifest_hash'), $manifest['manifest_hash'])
            && hash_equals($this->manifest()['manifest_hash'], $manifest['manifest_hash']);
    }

    /** @return array{id:string,version:string,path:string,hash:string} */
    private function entry(string $path, bool $schema): array
    {
        $payload = $this->assets->load($path);
        $id = $schema
            ? (string) ($payload['schema_id'] ?? '')
            : (string) ($payload['registry_id'] ?? $payload['catalog_id'] ?? '');
        $version = $schema
            ? (string) ($payload['schema_version'] ?? '')
            : (string) ($payload['registry_version'] ?? $payload['catalog_version'] ?? '');

        return [
            'id' => $id,
            'version' => $version,
            'path' => 'backend/resources/seo-agent/policy-gateway/'.$path,
            'hash' => $this->hasher->hash($payload),
        ];
    }
}
