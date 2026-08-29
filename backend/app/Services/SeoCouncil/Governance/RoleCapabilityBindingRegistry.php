<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Governance;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use JsonException;
use RuntimeException;

final class RoleCapabilityBindingRegistry
{
    public function __construct(
        private readonly SeoRoleCapabilityRegistry $registry,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function binding(): array
    {
        $path = resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json');
        try {
            $binding = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SEO Council Binding JSON is invalid.', previous: $exception);
        }
        if (! is_array($binding)) {
            throw new RuntimeException('SEO Council Binding is invalid.');
        }
        $binding['binding_hash'] = $this->hasher->hash($binding);

        return $binding;
    }

    public function status(): string
    {
        try {
            $registry = $this->registry->registry();
            $binding = $this->binding();
            if (($binding['registry_ref'] ?? null) !== [
                'id' => $registry['registry_id'],
                'version' => $registry['registry_version'],
                'hash' => $registry['registry_hash'],
            ] || count((array) $registry['roles']) !== 9 || count((array) $registry['capabilities']) !== 20) {
                return 'DEPENDENCY_HOLD';
            }
            $roles = array_column((array) $registry['roles'], 'role_id');
            $capabilities = array_column((array) $registry['capabilities'], 'capability_id');
            $bindings = (array) ($binding['roles'] ?? []);
            if (array_keys($bindings) !== $roles) {
                return 'DEPENDENCY_HOLD';
            }
            $bound = [];
            foreach ($bindings as $roleId => $roleCapabilities) {
                if (! in_array($roleId, $roles, true) || ! is_array($roleCapabilities)) {
                    return 'DEPENDENCY_HOLD';
                }
                foreach ($roleCapabilities as $capabilityId) {
                    if (! is_string($capabilityId) || ! in_array($capabilityId, $capabilities, true)) {
                        return 'DEPENDENCY_HOLD';
                    }
                    $bound[] = $capabilityId;
                }
            }
            if (array_intersect($bound, (array) ($binding['prohibited_capabilities'] ?? [])) !== []) {
                return 'DEPENDENCY_HOLD';
            }

            return 'READY';
        } catch (\Throwable) {
            return 'DEPENDENCY_HOLD';
        }
    }

    /** @return array{id:string,version:string,hash:string} */
    public function reference(): array
    {
        $binding = $this->binding();

        return [
            'id' => (string) $binding['binding_id'],
            'version' => (string) $binding['binding_version'],
            'hash' => (string) $binding['binding_hash'],
        ];
    }

    public function roleVersion(string $roleId): ?string
    {
        foreach ((array) $this->registry->registry()['roles'] as $role) {
            if (($role['role_id'] ?? null) === $roleId) {
                return (string) $role['role_version'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function capabilitiesFor(string $roleId): array
    {
        return array_values((array) ($this->binding()['roles'][$roleId] ?? []));
    }
}
