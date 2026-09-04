<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Contracts;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use InvalidArgumentException;

final class CouncilContractValidator
{
    private const FIELDS = [
        'mission_id', 'idempotency_key', 'mission_type', 'family', 'locale',
        'review_domain', 'requested_role', 'evidence_bundle_refs', 'autonomy',
        'budget', 'tool_scope', 'egress_scope', 'resume_from',
    ];

    public function __construct(
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function missionRequest(array $input): array
    {
        $this->exactKeys($input, self::FIELDS, 'MISSION_REQUEST_FIELDS_INVALID');
        if ($this->privacy->containsPrivateData($input)) {
            throw new InvalidArgumentException('PRIVATE_DATA_DENIED');
        }
        foreach (['mission_id', 'idempotency_key'] as $field) {
            if (! is_string($input[$field]) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $input[$field]) !== 1) {
                throw new InvalidArgumentException('MISSION_REQUEST_IDENTIFIER_INVALID');
            }
        }
        try {
            $mission = $this->binding->mission((string) $input['mission_type']);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('MISSION_REQUEST_SCOPE_INVALID');
        }
        if (! in_array($input['family'], (array) $mission['allowed_page_families'], true)
            || ! in_array($input['locale'], (array) $mission['allowed_locales'], true)) {
            throw new InvalidArgumentException('MISSION_REQUEST_SCOPE_INVALID');
        }
        $reviewDomain = $input['review_domain'];
        if ((isset($mission['selector']) && $this->binding->selectorVariant($mission, is_string($reviewDomain) ? $reviewDomain : null) === null)
            || (! isset($mission['selector']) && $reviewDomain !== null)) {
            throw new InvalidArgumentException('MISSION_REVIEW_DOMAIN_INVALID');
        }
        $requestedRole = $input['requested_role'];
        if ($requestedRole !== null && (! is_string($requestedRole) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $requestedRole) !== 1)) {
            throw new InvalidArgumentException('REQUESTED_ROLE_HINT_INVALID');
        }
        if ($input['autonomy'] !== 'L0' || $input['tool_scope'] !== [] || $input['egress_scope'] !== []) {
            throw new InvalidArgumentException('MISSION_SCOPE_EXPANSION_DENIED');
        }
        $this->zeroBudget($input['budget']);
        $this->evidenceRefs($input['evidence_bundle_refs']);
        $this->resume($input['resume_from']);

        return $input;
    }

    /** @param array<string, mixed> $output */
    public function modeOutput(array $output, string $expectedHandoffHash, string $expectedRole): bool
    {
        $fields = [
            'output_id', 'handoff_hash', 'role_id', 'status', 'summary_code', 'execution_allowed',
            'model_calls', 'tool_calls', 'external_calls', 'write_count', 'output_hash',
        ];
        $actual = array_keys($output);
        sort($actual, SORT_STRING);
        sort($fields, SORT_STRING);

        return $actual === $fields
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($output['output_id'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($output['output_hash'] ?? '')) === 1
            && in_array(($output['status'] ?? null), ['PASS', 'WARN', 'BLOCKED', 'HOLD'], true)
            && preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', (string) ($output['summary_code'] ?? '')) === 1
            && ($output['handoff_hash'] ?? null) === $expectedHandoffHash
            && ($output['role_id'] ?? null) === $expectedRole
            && ($output['execution_allowed'] ?? null) === false
            && ($output['model_calls'] ?? null) === 0
            && ($output['tool_calls'] ?? null) === 0
            && ($output['external_calls'] ?? null) === 0
            && ($output['write_count'] ?? null) === 0
            && hash_equals($this->hasher->hashWithout($output, 'output_hash'), (string) ($output['output_hash'] ?? ''));
    }

    /** @return list<string> */
    public function knownRoleIds(): array
    {
        return array_values(array_map(
            static fn (array $role): string => (string) $role['role_id'],
            (array) $this->roles->registry()['roles'],
        ));
    }

    private function zeroBudget(mixed $budget): void
    {
        if (! is_array($budget)) {
            throw new InvalidArgumentException('MISSION_BUDGET_INVALID');
        }
        $expected = [
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'execution_seconds' => 0,
            'retry_count' => 0,
            'context_bytes' => 0,
            'cost_amount' => 0,
            'currency' => 'USD',
        ];
        if ($budget !== $expected) {
            throw new InvalidArgumentException('BUDGET_EXPANSION_DENIED');
        }
    }

    private function evidenceRefs(mixed $refs): void
    {
        if (! is_array($refs) || ! array_is_list($refs) || count($refs) > 32) {
            throw new InvalidArgumentException('EVIDENCE_REFS_INVALID');
        }
        $seen = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                throw new InvalidArgumentException('EVIDENCE_REF_INVALID');
            }
            $this->exactKeys($ref, ['bundle_id', 'bundle_version', 'bundle_hash', 'evidence_type', 'status', 'authority_revision'], 'EVIDENCE_REF_FIELDS_INVALID');
            if (! is_string($ref['bundle_id']) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $ref['bundle_id']) !== 1
                || ! is_int($ref['bundle_version']) || $ref['bundle_version'] < 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) $ref['bundle_hash']) !== 1
                || ! in_array($ref['evidence_type'], $this->binding->evidenceTypes(), true)
                || ! in_array($ref['status'], ['READY', 'EVIDENCE_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE', 'MEASUREMENT_HOLD'], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) $ref['authority_revision']) !== 1) {
                throw new InvalidArgumentException('EVIDENCE_REF_INVALID');
            }
            $identity = $ref['bundle_id'].':'.$ref['bundle_version'];
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('EVIDENCE_REF_DUPLICATE');
            }
            $seen[$identity] = true;
        }
    }

    private function resume(mixed $resume): void
    {
        if ($resume === null) {
            return;
        }
        if (! is_array($resume)) {
            throw new InvalidArgumentException('RESUME_REF_INVALID');
        }
        $fields = [
            'receipt_hash', 'step_hash', 'catalog_hash', 'policy_hash',
            'binding_hash', 'evidence_hash', 'capability_hash',
        ];
        $this->exactKeys($resume, $fields, 'RESUME_REF_FIELDS_INVALID');
        foreach ($fields as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) $resume[$field]) !== 1) {
                throw new InvalidArgumentException('RESUME_REF_INVALID');
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected, string $code): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($code);
        }
    }
}
