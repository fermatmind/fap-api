<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Governance;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class RoleCapabilityBindingRegistry
{
    public const BINDING_VERSION = '2.0.0';

    public const V1_FILE_SHA256 = 'adb88f7c02f1a44069d36d22ee5e6d0413071960aa2957e872d7547465252932';

    private const MISSIONS = [
        'weekly_opportunity', 'monthly_portfolio', 'breakthrough_sprint', 'global_portfolio',
        'bounded_review', 'independent_registry_review', 'career_candidate_generation',
    ];

    private const MISSION_FIELDS = [
        'mission_id', 'mission_version', 'admission_role', 'required_capabilities',
        'eligible_roles', 'deterministic_tools', 'required_evidence', 'reviewer_requirement',
        'max_modes', 'allowed_page_families', 'allowed_locales', 'authority_ceiling',
        'stop_conditions', 'execution_allowed', 'allow_delegation', 'route_rule',
    ];

    private const EVIDENCE_TYPES = [
        'runtime_health', 'authority_parity', 'release_separation', 'search_measurement',
        'content_claim', 'entity', 'duplicate', 'lifecycle', 'competitor_public',
        'gateway_competitor_public', 'stability', 'cache_projection', 'funnel_aggregate',
        'career_candidate', 'career_manifest_validation', 'selector_required_evidence',
    ];

    private const REQUEST_EVIDENCE_TYPES = [
        'runtime_health', 'authority_parity', 'release_separation', 'search_measurement',
        'content_claim', 'entity', 'duplicate', 'lifecycle', 'competitor_public',
        'gateway_competitor_public', 'stability', 'cache_projection', 'funnel_aggregate',
        'career_candidate', 'career_manifest_validation',
    ];

    private const REVIEWER_REQUIREMENTS = [
        'independent_review_before_execution_authority',
        'selected_role_review_only',
        'self_independent_review',
        'independent_reviewer_mandatory_after_content_claim_entity_audit',
    ];

    public function __construct(
        private readonly SeoRoleCapabilityRegistry $registry,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function binding(): array
    {
        $binding = $this->load();
        if (! $this->validationReport($binding)['valid']) {
            throw new RuntimeException('SEO Council Binding v2 is invalid or drifted.');
        }

        return $binding;
    }

    /** @param array<string, mixed>|null $candidate @return array<string, int|bool> */
    public function validationReport(?array $candidate = null): array
    {
        try {
            $binding = $candidate ?? $this->load();
            $registry = $this->registry->registry();
            $roles = array_column((array) $registry['roles'], null, 'role_id');
            $capabilities = array_column((array) $registry['capabilities'], null, 'capability_id');
            $tools = array_column((array) ($binding['deterministic_tool_registry'] ?? []), null, 'tool_id');
            $missions = array_column((array) ($binding['missions'] ?? []), null, 'mission_id');
            $schemaFailed = 0;
            $unknownRoles = $unknownCapabilities = $unknownTools = 0;

            $requiredTop = [
                'schema_version', 'binding_id', 'binding_version', 'supersedes', 'registry_ref',
                'runtime_state', 'requested_role_policy', 'evidence_type_registry', 'role_bindings', 'deterministic_tool_registry',
                'missions', 'prohibited_capabilities', 'negative_guarantees', 'binding_hash',
            ];
            $schemaFailed += $this->missingCount($binding, $requiredTop);
            $schemaFailed += (int) (($binding['schema_version'] ?? null) !== 'seo.role_capability_binding.v2');
            $schemaFailed += (int) (($binding['binding_version'] ?? null) !== self::BINDING_VERSION);
            $schemaFailed += (int) (($binding['runtime_state'] ?? null) !== 'dormant_not_authorized');
            $schemaFailed += (int) (($binding['requested_role_policy'] ?? null) !== 'non_authoritative_selected_role_hint_only');
            $schemaFailed += (int) (($binding['evidence_type_registry'] ?? null) !== self::REQUEST_EVIDENCE_TYPES);
            $schemaFailed += (int) (($binding['registry_ref'] ?? null) !== [
                'id' => $registry['registry_id'], 'version' => $registry['registry_version'], 'hash' => $registry['registry_hash'],
            ]);
            $schemaFailed += (int) (($binding['supersedes']['file_sha256'] ?? null) !== self::V1_FILE_SHA256);
            $schemaFailed += (int) (! hash_equals(self::V1_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json')) ?: ''));
            $schemaFailed += $this->duplicateCount(array_column((array) ($binding['deterministic_tool_registry'] ?? []), 'tool_id'));
            $schemaFailed += $this->duplicateCount(array_column((array) ($binding['missions'] ?? []), 'mission_id'));

            foreach ((array) ($binding['role_bindings'] ?? []) as $roleId => $roleCapabilities) {
                $unknownRoles += (int) (! isset($roles[$roleId]));
                $schemaFailed += $this->duplicateCount((array) $roleCapabilities);
                foreach ((array) $roleCapabilities as $capabilityId) {
                    $unknownCapabilities += $this->invalidCapabilityCount((string) $capabilityId, $capabilities, $binding);
                }
            }
            $schemaFailed += (int) (count((array) ($binding['role_bindings'] ?? [])) !== count($roles));

            foreach ((array) ($binding['deterministic_tool_registry'] ?? []) as $tool) {
                $schemaFailed += $this->missingCount((array) $tool, [
                    'tool_id', 'service_class', 'execution_kind', 'agent_tool_invocation',
                    'model_invocation', 'external_egress', 'write_permissions', 'execution_allowed',
                ]);
                $schemaFailed += (int) (($tool['execution_kind'] ?? null) !== 'deterministic_internal_service');
                $schemaFailed += (int) (($tool['agent_tool_invocation'] ?? null) !== false);
                $schemaFailed += (int) (($tool['model_invocation'] ?? null) !== false);
                $schemaFailed += (int) (($tool['external_egress'] ?? null) !== false);
                $schemaFailed += (int) (($tool['write_permissions'] ?? null) !== []);
                $schemaFailed += (int) (($tool['execution_allowed'] ?? null) !== false);
                $unknownTools += (int) (! is_string($tool['service_class'] ?? null) || ! class_exists($tool['service_class']));
            }

            foreach ((array) ($binding['missions'] ?? []) as $mission) {
                $mission = (array) $mission;
                $schemaFailed += $this->missingCount($mission, self::MISSION_FIELDS);
                $schemaFailed += (int) (($mission['mission_version'] ?? null) !== self::BINDING_VERSION);
                $schemaFailed += (int) (($mission['execution_allowed'] ?? null) !== false);
                $schemaFailed += (int) (($mission['allow_delegation'] ?? null) !== false);
                $schemaFailed += (int) (! is_int($mission['max_modes'] ?? null) || (int) ($mission['max_modes'] ?? 0) < 1);
                $schemaFailed += (int) (! is_string($mission['reviewer_requirement'] ?? null) || $mission['reviewer_requirement'] === '');
                $schemaFailed += (int) (! in_array($mission['reviewer_requirement'] ?? null, self::REVIEWER_REQUIREMENTS, true));
                $schemaFailed += (int) (! in_array($mission['authority_ceiling'] ?? null, ['recommendation_only', 'review_verdict', 'candidate_only'], true));
                foreach (['required_capabilities', 'eligible_roles', 'deterministic_tools', 'required_evidence', 'allowed_page_families', 'allowed_locales', 'stop_conditions'] as $field) {
                    $schemaFailed += (int) (! is_array($mission[$field] ?? null) || $mission[$field] === []);
                    $schemaFailed += $this->duplicateCount((array) ($mission[$field] ?? []));
                }
                $schemaFailed += (int) (! is_array($mission['route_rule'] ?? null));
                $schemaFailed += (int) (count((array) ($mission['eligible_roles'] ?? [])) < (int) ($mission['max_modes'] ?? 0));
                $schemaFailed += (int) (array_diff((array) ($mission['allowed_page_families'] ?? []), ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public']) !== []);
                $schemaFailed += (int) (array_diff((array) ($mission['allowed_locales'] ?? []), ['en', 'zh-CN']) !== []);
                $schemaFailed += (int) (array_diff((array) ($mission['required_evidence'] ?? []), self::EVIDENCE_TYPES) !== []);
                foreach ($this->missionRoles($mission) as $roleId) {
                    $unknownRoles += (int) (! isset($roles[$roleId]));
                }
                foreach ($this->missionCapabilities($mission) as $capabilityId) {
                    $unknownCapabilities += $this->invalidCapabilityCount($capabilityId, $capabilities, $binding);
                }
                foreach ($this->missionTools($mission) as $toolId) {
                    $unknownTools += (int) (! isset($tools[$toolId]));
                }
                foreach ((array) ($mission['route_rule']['conditional_roles'] ?? []) as $conditional) {
                    $schemaFailed += (int) (array_diff((array) ($conditional['evidence_types'] ?? []), self::EVIDENCE_TYPES) !== []);
                }
                if (($mission['mission_id'] ?? null) === 'bounded_review') {
                    $variants = (array) ($mission['selector']['variants'] ?? []);
                    $schemaFailed += (int) (($mission['selector']['field'] ?? null) !== 'review_domain');
                    $schemaFailed += (int) (array_column($variants, 'value') !== ['technical', 'analytics', 'content', 'competitor', 'stability', 'cro']);
                    foreach ($variants as $variant) {
                        $schemaFailed += $this->missingCount((array) $variant, ['value', 'required_capabilities', 'eligible_roles', 'deterministic_tools', 'required_evidence', 'reviewer_requirement']);
                        $schemaFailed += (int) (! in_array($variant['reviewer_requirement'] ?? null, self::REVIEWER_REQUIREMENTS, true));
                        $schemaFailed += (int) (array_diff((array) ($variant['required_evidence'] ?? []), self::EVIDENCE_TYPES) !== []);
                    }
                }
            }

            $unbound = count(array_diff(self::MISSIONS, array_keys($missions))) + count(array_diff(array_keys($missions), self::MISSIONS));
            $hashDrift = (int) (! is_string($binding['binding_hash'] ?? null)
                || ! hash_equals($this->hasher->hashWithout($binding, 'binding_hash'), (string) $binding['binding_hash']));
            $failed = $schemaFailed + $hashDrift + $unbound + $unknownRoles + $unknownCapabilities + $unknownTools;

            return [
                'valid' => $failed === 0,
                'binding_schema_probe_total' => 1,
                'binding_schema_probe_passed' => (int) ($failed === 0),
                'binding_schema_probe_failed' => (int) ($failed !== 0),
                'binding_hash_drift_count' => $hashDrift,
                'unbound_mission_count' => $unbound,
                'unknown_role_count' => $unknownRoles,
                'unknown_capability_count' => $unknownCapabilities,
                'unknown_tool_count' => $unknownTools,
            ];
        } catch (\Throwable) {
            return [
                'valid' => false, 'binding_schema_probe_total' => 1, 'binding_schema_probe_passed' => 0,
                'binding_schema_probe_failed' => 1, 'binding_hash_drift_count' => 1,
                'unbound_mission_count' => count(self::MISSIONS), 'unknown_role_count' => 1,
                'unknown_capability_count' => 1, 'unknown_tool_count' => 1,
            ];
        }
    }

    public function status(): string
    {
        return $this->validationReport()['valid'] ? 'READY' : 'DEPENDENCY_HOLD';
    }

    /** @return array{id:string,version:string,hash:string} */
    public function reference(): array
    {
        $binding = $this->binding();

        return ['id' => (string) $binding['binding_id'], 'version' => (string) $binding['binding_version'], 'hash' => (string) $binding['binding_hash']];
    }

    /** @return array<string, mixed> */
    public function mission(string $missionId): array
    {
        foreach ((array) $this->binding()['missions'] as $mission) {
            if (($mission['mission_id'] ?? null) === $missionId) {
                return $mission;
            }
        }

        throw new InvalidArgumentException('MISSION_NOT_BOUND');
    }

    /** @return array<string, mixed>|null */
    public function selectorVariant(array $mission, ?string $value): ?array
    {
        foreach ((array) ($mission['selector']['variants'] ?? []) as $variant) {
            if (($variant['value'] ?? null) === $value) {
                return $variant;
            }
        }

        return null;
    }

    public function admissionRoleFor(MissionRequestData $request): string
    {
        $mission = $this->mission((string) $request->payload['mission_type']);
        if (($mission['admission_role'] ?? null) === 'selector_role') {
            $variant = $this->selectorVariant($mission, $request->payload['review_domain']);

            return (string) (($variant['eligible_roles'][0] ?? null) ?: throw new InvalidArgumentException('MISSION_REVIEW_DOMAIN_INVALID'));
        }

        return (string) $mission['admission_role'];
    }

    public function validateRequestScope(MissionRequestData $request): void
    {
        $mission = $this->mission((string) $request->payload['mission_type']);
        if (! in_array($request->payload['family'], (array) $mission['allowed_page_families'], true)) {
            throw new InvalidArgumentException('MISSION_PAGE_FAMILY_EXPANSION_DENIED');
        }
        if (! in_array($request->payload['locale'], (array) $mission['allowed_locales'], true)) {
            throw new InvalidArgumentException('MISSION_LOCALE_EXPANSION_DENIED');
        }
        $variant = $this->selectorVariant($mission, $request->payload['review_domain']);
        if (isset($mission['selector']) && $variant === null) {
            throw new InvalidArgumentException('MISSION_REVIEW_DOMAIN_INVALID');
        }
        $requestedRole = $request->payload['requested_role'];
        $eligibleRoles = $variant['eligible_roles'] ?? $mission['eligible_roles'];
        if ($requestedRole !== null && ! in_array($requestedRole, (array) $eligibleRoles, true)) {
            throw new InvalidArgumentException('REQUESTED_ROLE_EXPANSION_DENIED');
        }
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
        return array_values((array) ($this->binding()['role_bindings'][$roleId] ?? []));
    }

    /** @return list<string> */
    public function evidenceTypes(): array
    {
        return array_values((array) $this->binding()['evidence_type_registry']);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        try {
            $binding = json_decode((string) file_get_contents(resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SEO Council Binding JSON is invalid.', previous: $exception);
        }
        if (! is_array($binding)) {
            throw new RuntimeException('SEO Council Binding is invalid.');
        }

        return $binding;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function missingCount(array $value, array $keys): int
    {
        return count(array_diff($keys, array_keys($value)));
    }

    /** @param array<int, mixed> $values */
    private function duplicateCount(array $values): int
    {
        return count($values) - count(array_unique(array_map(static fn (mixed $value): string => (string) $value, $values)));
    }

    /** @param array<string, array<string, mixed>> $capabilities @param array<string, mixed> $binding */
    private function invalidCapabilityCount(string $capabilityId, array $capabilities, array $binding): int
    {
        $capability = $capabilities[$capabilityId] ?? null;

        return (int) (! is_array($capability)
            || ($capability['runtime_state'] ?? null) !== 'dormant_not_authorized'
            || ($capability['agent_invocable'] ?? null) !== false
            || in_array($capabilityId, (array) ($binding['prohibited_capabilities'] ?? []), true));
    }

    /** @param array<string, mixed> $mission @return list<string> */
    private function missionRoles(array $mission): array
    {
        $roles = array_merge((array) ($mission['eligible_roles'] ?? []), (array) ($mission['route_rule']['base_roles'] ?? []));
        if (($mission['admission_role'] ?? null) !== 'selector_role') {
            $roles[] = $mission['admission_role'] ?? '';
        }
        foreach ((array) ($mission['route_rule']['conditional_roles'] ?? []) as $conditional) {
            $roles[] = $conditional['role_id'] ?? '';
        }
        foreach ((array) ($mission['selector']['variants'] ?? []) as $variant) {
            $roles = array_merge($roles, (array) ($variant['eligible_roles'] ?? []));
        }

        return array_values(array_unique(array_filter($roles, 'is_string')));
    }

    /** @param array<string, mixed> $mission @return list<string> */
    private function missionCapabilities(array $mission): array
    {
        $capabilities = (array) ($mission['required_capabilities'] ?? []);
        foreach ((array) ($mission['selector']['variants'] ?? []) as $variant) {
            $capabilities = array_merge($capabilities, (array) ($variant['required_capabilities'] ?? []));
        }

        return array_values(array_unique($capabilities));
    }

    /** @param array<string, mixed> $mission @return list<string> */
    private function missionTools(array $mission): array
    {
        $tools = (array) ($mission['deterministic_tools'] ?? []);
        foreach ((array) ($mission['selector']['variants'] ?? []) as $variant) {
            $tools = array_merge($tools, (array) ($variant['deterministic_tools'] ?? []));
        }

        return array_values(array_unique($tools));
    }
}
