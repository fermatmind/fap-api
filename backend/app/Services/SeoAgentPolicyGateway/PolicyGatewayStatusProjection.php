<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

final class PolicyGatewayStatusProjection
{
    public const MODE = 'DETERMINISTIC_DENY_ONLY';

    public function __construct(private readonly PolicyGatewayRegistry $registry) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $registry = $this->registry->registry();
        $guards = (array) $registry['guards'];

        return [
            'state' => 'DEPLOYED_DISABLED',
            'mode' => self::MODE,
            'decision' => 'DENY',
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'registry' => [
                'id' => $registry['registry_id'],
                'version' => $registry['registry_version'],
                'state' => $registry['registry_state'],
                'hash' => $registry['registry_hash'],
            ],
            'global_guards' => [
                'global_write_gate' => $guards['global_write_gate'],
                'post12_agent_write_enabled' => $guards['post12_agent_write_enabled'],
                'agent_default_write_permission' => $guards['agent_default_write_permission'],
                'model_invocation_enabled' => $guards['model_invocation_enabled'],
                'tool_invocation_enabled' => $guards['tool_invocation_enabled'],
                'external_egress_enabled' => $guards['external_egress_enabled'],
            ],
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'capabilities' => [],
            'trace' => null,
            'canary' => null,
            'circuit_breaker' => null,
            'rollback' => null,
        ];
    }
}
