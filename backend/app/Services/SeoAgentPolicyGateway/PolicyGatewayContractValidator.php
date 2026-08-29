<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

final class PolicyGatewayContractValidator
{
    private const ADMISSION_FIELDS = [
        'schema_version', 'caller_type', 'mission_id', 'mission_type', 'requested_role_id',
        'family', 'locale', 'claim_risk', 'autonomy', 'budget', 'deadline_seconds',
        'tool_scope', 'egress_scope', 'evidence_context', 'request_metadata',
    ];

    private const CONTEXT_FIELDS = [
        'schema_version', 'context_id', 'context_version', 'context_hash', 'mission_id',
        'mission_type', 'role_id', 'page_family', 'locale', 'built_at', 'expires_at',
        'bundle_refs', 'source_capability_states', 'evidence_summary', 'payload', 'status',
        'execution_allowed', 'model_invocation', 'tool_invocation', 'write_permissions',
        'tool_allowlist', 'egress_allowlist',
    ];

    private const MANIFEST_FIELDS = [
        'schema_version', 'manifest_id', 'manifest_version', 'policy_registry_ref', 'role_id',
        'mission_type', 'capability_id', 'target_environment', 'family', 'locale', 'action',
        'allowed_fields', 'forbidden_fields', 'autonomy', 'max_urls', 'shared_layer_allowed',
        'evidence_threshold', 'rollback_unit', 'approval', 'authority_revision', 'canary_stage',
        'expiry', 'revocation', 'manifest_hash', 'signature',
    ];

    /** @param array<string, mixed> $request */
    public function admission(array $request): bool
    {
        $budget = $request['budget'] ?? null;
        $metadata = $request['request_metadata'] ?? null;
        $context = $request['evidence_context'] ?? null;

        return $this->exactKeys($request, self::ADMISSION_FIELDS)
            && ($request['schema_version'] ?? null) === 'seo.policy_admission_request.v1'
            && $this->identifier($request['mission_id'] ?? null)
            && $this->identifier($request['mission_type'] ?? null)
            && $this->identifier($request['requested_role_id'] ?? null)
            && in_array($request['caller_type'] ?? null, ['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'], true)
            && in_array($request['family'] ?? null, ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'], true)
            && in_array($request['locale'] ?? null, ['en', 'zh-CN'], true)
            && in_array($request['claim_risk'] ?? null, ['R1', 'R2', 'R3', 'R4'], true)
            && in_array($request['autonomy'] ?? null, ['L0', 'L1', 'L2', 'L3', 'L4'], true)
            && is_array($budget) && $this->exactKeys($budget, ['model_calls', 'tool_calls', 'execution_seconds', 'cost_amount', 'currency'])
            && $this->nonNegativeInt($budget['model_calls'] ?? null)
            && $this->nonNegativeInt($budget['tool_calls'] ?? null)
            && $this->nonNegativeInt($budget['execution_seconds'] ?? null)
            && (is_int($budget['cost_amount'] ?? null) || is_float($budget['cost_amount'] ?? null))
            && (float) $budget['cost_amount'] >= 0
            && ($budget['currency'] ?? null) === 'USD'
            && $this->nonNegativeInt($request['deadline_seconds'] ?? null)
            && $this->stringList($request['tool_scope'] ?? null)
            && $this->stringList($request['egress_scope'] ?? null)
            && is_array($metadata) && $this->exactKeys($metadata, ['source_label', 'correlation_hash'])
            && is_string($metadata['source_label'] ?? null) && strlen($metadata['source_label']) <= 64
            && preg_match('/^[a-f0-9]{64}$/', (string) ($metadata['correlation_hash'] ?? '')) === 1
            && is_array($context) && $this->evidenceContext($context);
    }

    /** @param array<string, mixed> $request */
    public function execution(array $request): bool
    {
        $scope = $request['action_scope'] ?? null;

        return $this->exactKeys($request, ['schema_version', 'caller_type', 'admission_request', 'manifest', 'action_scope'])
            && ($request['schema_version'] ?? null) === 'seo.policy_execution_request.v1'
            && in_array($request['caller_type'] ?? null, ['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'], true)
            && is_array($request['admission_request'] ?? null)
            && is_array($request['manifest'] ?? null)
            && is_array($scope)
            && $this->exactKeys($scope, ['family', 'locale', 'action', 'fields', 'url_count', 'claim_risk', 'blast_radius', 'shared_layer', 'rollback_ready', 'rollback_unit', 'authority_revision', 'measurement_state', 'canary_stage'])
            && is_string($scope['family'] ?? null)
            && is_string($scope['locale'] ?? null)
            && is_string($scope['action'] ?? null)
            && $this->stringList($scope['fields'] ?? null)
            && is_int($scope['url_count'] ?? null)
            && in_array($scope['claim_risk'] ?? null, ['R1', 'R2', 'R3', 'R4'], true)
            && in_array($scope['blast_radius'] ?? null, ['single_url', 'bounded_cohort', 'shared_layer'], true)
            && is_bool($scope['shared_layer'] ?? null)
            && is_bool($scope['rollback_ready'] ?? null)
            && is_string($scope['rollback_unit'] ?? null)
            && is_string($scope['authority_revision'] ?? null)
            && in_array($scope['measurement_state'] ?? null, ['READY', 'EVIDENCE_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE', 'MEASUREMENT_HOLD'], true)
            && is_string($scope['canary_stage'] ?? null);
    }

    /** @param array<string, mixed> $manifest */
    public function manifest(array $manifest): bool
    {
        return $this->exactKeys($manifest, self::MANIFEST_FIELDS)
            && ($manifest['schema_version'] ?? null) === 'seo.action_scoped_manifest.v1'
            && ($manifest['manifest_version'] ?? null) === '1.0.0'
            && $this->identifier($manifest['manifest_id'] ?? null)
            && $this->identifier($manifest['role_id'] ?? null)
            && $this->identifier($manifest['mission_type'] ?? null)
            && $this->identifier($manifest['capability_id'] ?? null)
            && in_array($manifest['target_environment'] ?? null, ['testing', 'staging', 'production'], true)
            && is_string($manifest['family'] ?? null) && ! str_contains($manifest['family'], '*')
            && is_string($manifest['locale'] ?? null) && ! str_contains($manifest['locale'], '*')
            && is_string($manifest['action'] ?? null) && ! str_contains($manifest['action'], '*')
            && $this->stringList($manifest['allowed_fields'] ?? null)
            && $this->stringList($manifest['forbidden_fields'] ?? null)
            && ! in_array('*', (array) ($manifest['allowed_fields'] ?? []), true)
            && ! in_array('*', (array) ($manifest['forbidden_fields'] ?? []), true)
            && in_array($manifest['autonomy'] ?? null, ['L0', 'L1', 'L2', 'L3', 'L4'], true)
            && is_int($manifest['max_urls'] ?? null) && $manifest['max_urls'] > 0
            && is_bool($manifest['shared_layer_allowed'] ?? null)
            && $this->exactObject($manifest['policy_registry_ref'] ?? null, ['id', 'version', 'hash'])
            && $this->exactObject($manifest['evidence_threshold'] ?? null, ['minimum_bundle_count', 'required_status'])
            && is_int($manifest['evidence_threshold']['minimum_bundle_count'] ?? null)
            && $manifest['evidence_threshold']['minimum_bundle_count'] > 0
            && ($manifest['evidence_threshold']['required_status'] ?? null) === 'READY'
            && in_array($manifest['rollback_unit'] ?? null, ['exact_url', 'exact_family_locale_revision'], true)
            && $this->exactObject($manifest['approval'] ?? null, ['surface_id', 'review_state', 'production_execution_separate'])
            && in_array($manifest['approval']['review_state'] ?? null, ['approved', 'pending', 'rejected', 'unknown'], true)
            && ($manifest['approval']['production_execution_separate'] ?? null) === true
            && is_string($manifest['authority_revision'] ?? null) && $manifest['authority_revision'] !== ''
            && is_string($manifest['canary_stage'] ?? null) && $manifest['canary_stage'] !== ''
            && $this->exactObject($manifest['expiry'] ?? null, ['not_before', 'expires_at'])
            && $this->exactObject($manifest['revocation'] ?? null, ['registry_id', 'registry_version'])
            && $this->exactObject($manifest['signature'] ?? null, ['algorithm', 'key_id', 'value'])
            && ($manifest['signature']['algorithm'] ?? null) === 'Ed25519'
            && preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['manifest_hash'] ?? '')) === 1;
    }

    /** @param array<string, mixed> $context */
    private function evidenceContext(array $context): bool
    {
        return $this->exactKeys($context, self::CONTEXT_FIELDS)
            && ($context['schema_version'] ?? null) === 'seo.evidence_context.v1'
            && is_int($context['context_version'] ?? null) && $context['context_version'] >= 1
            && preg_match('/^[a-f0-9]{64}$/', (string) ($context['context_id'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/', (string) ($context['context_hash'] ?? '')) === 1
            && is_string($context['built_at'] ?? null)
            && is_string($context['expires_at'] ?? null)
            && is_array($context['bundle_refs'] ?? null)
            && is_array($context['source_capability_states'] ?? null)
            && is_array($context['evidence_summary'] ?? null)
            && is_array($context['payload'] ?? null)
            && in_array($context['status'] ?? null, ['READY', 'EVIDENCE_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE', 'MEASUREMENT_HOLD'], true)
            && ($context['execution_allowed'] ?? null) === false
            && ($context['model_invocation'] ?? null) === false
            && ($context['tool_invocation'] ?? null) === false
            && ($context['write_permissions'] ?? null) === []
            && ($context['tool_allowlist'] ?? null) === []
            && ($context['egress_allowlist'] ?? null) === [];
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $value) === 1;
    }

    private function nonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && count($value) === count(array_unique($value, SORT_STRING))
            && array_filter($value, static fn (mixed $item): bool => ! is_string($item) || $item === '') === [];
    }

    /** @param list<string> $keys */
    private function exactObject(mixed $value, array $keys): bool
    {
        return is_array($value) && $this->exactKeys($value, $keys);
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }
}
