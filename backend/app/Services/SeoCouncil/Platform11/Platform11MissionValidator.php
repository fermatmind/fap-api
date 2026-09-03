<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use InvalidArgumentException;

final class Platform11MissionValidator
{
    private const FIELDS = [
        'schema_version', 'mission_id', 'idempotency_key', 'mission_type', 'family', 'locale',
        'review_domain', 'requested_role', 'evidence_bundle_refs', 'autonomy', 'budget',
        'tool_scope', 'egress_scope', 'mode_input',
    ];

    public function __construct(
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly Platform11ContractRegistry $contracts,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input): array
    {
        $this->exactKeys($input, self::FIELDS, 'MISSION_V2_FIELDS_INVALID');
        if (($input['schema_version'] ?? null) !== 'seo.mission_request.v2') {
            throw new InvalidArgumentException('MISSION_V2_SCHEMA_INVALID');
        }
        if ($this->privacy->containsPrivateData($input) || $this->containsForbiddenKey($input)) {
            throw new InvalidArgumentException('PRIVATE_OR_RAW_QUERY_DATA_DENIED');
        }
        foreach (['mission_id', 'idempotency_key'] as $field) {
            if (! is_string($input[$field]) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $input[$field]) !== 1) {
                throw new InvalidArgumentException('MISSION_V2_IDENTIFIER_INVALID');
            }
        }
        if (! in_array($input['family'], ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'], true)
            || ! in_array($input['locale'], ['en', 'zh-CN'], true)) {
            throw new InvalidArgumentException('MISSION_V2_SCOPE_INVALID');
        }
        $missionType = $input['mission_type'];
        $domain = $input['review_domain'];
        if ($missionType === 'bounded_review') {
            $definition = is_string($domain) ? (Platform11ContractRegistry::DOMAINS[$domain] ?? null) : null;
            if (! is_array($definition) || $input['autonomy'] !== $definition['autonomy']) {
                throw new InvalidArgumentException('MISSION_V2_DOMAIN_AUTONOMY_DENIED');
            }
            $eligibleRole = $definition['role'];
        } elseif ($missionType === 'independent_registry_review' && $domain === null && $input['autonomy'] === 'L0') {
            $eligibleRole = 'seo.independent_reviewer';
        } else {
            throw new InvalidArgumentException('MISSION_V2_SCOPE_INVALID');
        }
        if ($input['requested_role'] !== null && $input['requested_role'] !== $eligibleRole) {
            throw new InvalidArgumentException('REQUESTED_ROLE_EXPANSION_DENIED');
        }
        if ($input['tool_scope'] !== [] || $input['egress_scope'] !== []) {
            throw new InvalidArgumentException('MISSION_V2_SCOPE_EXPANSION_DENIED');
        }
        if ($input['budget'] !== [
            'model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0,
            'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0,
            'cost_amount' => 0, 'currency' => 'USD',
        ]) {
            throw new InvalidArgumentException('MISSION_V2_BUDGET_EXPANSION_DENIED');
        }
        $this->evidenceRefs($input['evidence_bundle_refs']);
        if (! is_array($input['mode_input'])) {
            throw new InvalidArgumentException('MISSION_V2_MODE_INPUT_INVALID');
        }
        if ($domain === 'intent_query_ownership') {
            $this->intentInput($input['mode_input'], (string) $input['locale']);
        } elseif ($domain === 'editorial_draft') {
            $this->editorialInput($input['mode_input'], (string) $input['locale']);
        } elseif ($domain === 'runtime_qa') {
            $this->runtimeQaInput($input['mode_input']);
        } elseif ($missionType === 'independent_registry_review') {
            $this->independentReviewInput($input['mode_input']);
        }

        return $input;
    }

    private function evidenceRefs(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 32) {
            throw new InvalidArgumentException('MISSION_V2_EVIDENCE_REFS_INVALID');
        }
        $seen = [];
        foreach ($value as $ref) {
            if (! is_array($ref)) {
                throw new InvalidArgumentException('MISSION_V2_EVIDENCE_REF_INVALID');
            }
            $this->exactKeys($ref, ['bundle_id', 'bundle_version', 'bundle_hash', 'evidence_type', 'status', 'authority_revision'], 'MISSION_V2_EVIDENCE_REF_FIELDS_INVALID');
            $identity = ($ref['bundle_id'] ?? '').':'.($ref['bundle_version'] ?? '');
            if (! is_string($ref['bundle_id'] ?? null)
                || ! is_int($ref['bundle_version'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($ref['bundle_hash'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($ref['authority_revision'] ?? '')) !== 1
                || ! in_array($ref['status'] ?? null, ['READY', 'EVIDENCE_HOLD', 'DEPENDENCY_HOLD'], true)
                || isset($seen[$identity])) {
                throw new InvalidArgumentException('MISSION_V2_EVIDENCE_REF_INVALID');
            }
            $seen[$identity] = true;
        }
    }

    /** @param array<string, mixed> $input */
    private function intentInput(array $input, string $locale): void
    {
        $this->exactKeys($input, ['query_hmac', 'query_cluster_id', 'intent_label', 'query_family_key', 'locale'], 'INTENT_INPUT_FIELDS_INVALID');
        if (preg_match('/^[a-f0-9]{64}$/D', (string) ($input['query_hmac'] ?? '')) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', (string) ($input['query_cluster_id'] ?? '')) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9 _.,:-]{0,95}$/u', (string) ($input['intent_label'] ?? '')) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', (string) ($input['query_family_key'] ?? '')) !== 1
            || ($input['locale'] ?? null) !== $locale) {
            throw new InvalidArgumentException('INTENT_INPUT_INVALID');
        }
    }

    /** @param array<string, mixed> $input */
    private function editorialInput(array $input, string $locale): void
    {
        $this->exactKeys($input, [
            'owner_candidate_hash', 'locale', 'title', 'seo_title', 'meta_description', 'refresh_brief',
            'direct_response', 'faq_or_modules', 'internal_link_candidates', 'source_claim_locale_map',
            'schema_candidate', 'material_change', 'page_necessity', 'information_gain',
            'template_overlap_score', 'duplicate_similarity', 'translation_only', 'locale_specific_value',
            'scaled_content_score', 'authority_revision',
        ], 'EDITORIAL_INPUT_FIELDS_INVALID');
        foreach (['owner_candidate_hash', 'authority_revision'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($input[$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('EDITORIAL_HASH_INVALID');
            }
        }
        if (($input['locale'] ?? null) !== $locale
            || ! is_bool($input['material_change'] ?? null)
            || ! is_bool($input['translation_only'] ?? null)
            || ! $this->score($input['template_overlap_score'] ?? null)
            || ! $this->score($input['duplicate_similarity'] ?? null)
            || ! $this->score($input['scaled_content_score'] ?? null)) {
            throw new InvalidArgumentException('EDITORIAL_INPUT_INVALID');
        }
        foreach (['title', 'seo_title', 'meta_description', 'refresh_brief', 'direct_response', 'page_necessity', 'information_gain', 'locale_specific_value'] as $field) {
            if (! is_string($input[$field] ?? null) || mb_strlen((string) $input[$field]) > 2000) {
                throw new InvalidArgumentException('EDITORIAL_TEXT_INVALID');
            }
        }
        if (! is_array($input['faq_or_modules']) || ! array_is_list($input['faq_or_modules'])
            || ! is_array($input['schema_candidate'])
            || ! is_array($input['internal_link_candidates']) || ! array_is_list($input['internal_link_candidates'])
            || ! is_array($input['source_claim_locale_map']) || ! array_is_list($input['source_claim_locale_map'])) {
            throw new InvalidArgumentException('EDITORIAL_COLLECTION_INVALID');
        }
        foreach ($input['internal_link_candidates'] as $link) {
            if (! is_array($link)) {
                throw new InvalidArgumentException('EDITORIAL_LINK_INVALID');
            }
            $this->exactKeys($link, ['target_hash', 'truth_status', 'visibility', 'indexability', 'redirect_only', 'locale'], 'EDITORIAL_LINK_FIELDS_INVALID');
        }
        foreach ($input['source_claim_locale_map'] as $claim) {
            if (! is_array($claim)) {
                throw new InvalidArgumentException('EDITORIAL_CLAIM_INVALID');
            }
            $this->exactKeys($claim, ['claim_id', 'source_ref', 'evidence_ref', 'locale', 'risk_level', 'freshness_state', 'statement_kind'], 'EDITORIAL_CLAIM_FIELDS_INVALID');
        }
    }

    private function score(mixed $value): bool
    {
        return (is_float($value) || is_int($value)) && $value >= 0 && $value <= 1;
    }

    /** @param array<string, mixed> $input */
    private function runtimeQaInput(array $input): void
    {
        $this->exactKeys($input, [
            'transport_http_status', 'expected_deployment_sha', 'observed_deployment_sha',
            'expected_authority_revision', 'authority_revision', 'expected_cms_readback_hash', 'cms_readback_hash',
            'expected_cache_source_hash', 'cache_source_hash', 'visible_content', 'canonical_parity', 'robots_parity',
            'schema_parity', 'feed_membership_parity', 'locale_parity', 'rollback_receipt_present',
            'experiment', 'action_type', 'preapproved', 'single_public_target', 'low_risk', 'reversible',
        ], 'RUNTIME_QA_INPUT_FIELDS_INVALID');
        foreach (['expected_deployment_sha', 'observed_deployment_sha'] as $field) {
            if (preg_match('/^[a-f0-9]{40}$/D', (string) ($input[$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('RUNTIME_QA_SHA_INVALID');
            }
        }
        foreach (['expected_authority_revision', 'authority_revision', 'expected_cms_readback_hash', 'cms_readback_hash', 'expected_cache_source_hash', 'cache_source_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($input[$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('RUNTIME_QA_HASH_INVALID');
            }
        }
        foreach (['visible_content', 'canonical_parity', 'robots_parity', 'schema_parity', 'feed_membership_parity', 'locale_parity', 'rollback_receipt_present', 'preapproved', 'single_public_target', 'low_risk', 'reversible'] as $field) {
            if (! is_bool($input[$field] ?? null)) {
                throw new InvalidArgumentException('RUNTIME_QA_BOOLEAN_INVALID');
            }
        }
        if (! is_int($input['transport_http_status'] ?? null)
            || $input['transport_http_status'] < 100 || $input['transport_http_status'] > 599
            || ! in_array($input['action_type'] ?? null, ['meta_description', 'body', 'multilingual', 'schema', 'shared_cache', 'canonical', 'noindex', 'redirect', 'delete', 'unpublish', 'retire', 'new_family', 'sensitive_claim'], true)
            || ! is_array($input['experiment'] ?? null)) {
            throw new InvalidArgumentException('RUNTIME_QA_INPUT_INVALID');
        }
        $this->exactKeys($input['experiment'], [
            'preregistered', 'exposure_scope_hash', 'window_start', 'window_end', 'measurement_valid', 'ledger_hash',
            'tracking_changed', 'google_update_window', 'seasonal_confounder', 'single_observation',
        ], 'RUNTIME_QA_EXPERIMENT_FIELDS_INVALID');
        foreach (['exposure_scope_hash', 'ledger_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($input['experiment'][$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('RUNTIME_QA_EXPERIMENT_HASH_INVALID');
            }
        }
        foreach (['preregistered', 'measurement_valid', 'tracking_changed', 'google_update_window', 'seasonal_confounder', 'single_observation'] as $field) {
            if (! is_bool($input['experiment'][$field] ?? null)) {
                throw new InvalidArgumentException('RUNTIME_QA_EXPERIMENT_BOOLEAN_INVALID');
            }
        }
        $start = strtotime((string) ($input['experiment']['window_start'] ?? ''));
        $end = strtotime((string) ($input['experiment']['window_end'] ?? ''));
        if ($start === false || $end === false || $start >= $end) {
            throw new InvalidArgumentException('RUNTIME_QA_EXPERIMENT_WINDOW_INVALID');
        }
    }

    /** @param array<string, mixed> $input */
    private function independentReviewInput(array $input): void
    {
        $this->exactKeys($input, [
            'generation_run_id', 'generation_context_id', 'frozen_manifest', 'candidate_artifact_hash',
            'policy_ref', 'registry_ref', 'binding_ref',
        ], 'INDEPENDENT_REVIEW_INPUT_FIELDS_INVALID');
        foreach (['generation_run_id', 'generation_context_id', 'candidate_artifact_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($input[$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('INDEPENDENT_REVIEW_HASH_INVALID');
            }
        }
        if (! is_array($input['frozen_manifest'] ?? null)) {
            throw new InvalidArgumentException('INDEPENDENT_REVIEW_MANIFEST_INVALID');
        }
        $manifest = $input['frozen_manifest'];
        $this->exactKeys($manifest, [
            'manifest_id', 'manifest_version', 'frozen', 'candidate_artifact_hash',
            'policy_review', 'experiment_review', 'safety_review', 'manifest_hash',
        ], 'INDEPENDENT_REVIEW_MANIFEST_FIELDS_INVALID');
        if (! is_string($manifest['manifest_id'] ?? null)
            || ! is_int($manifest['manifest_version'] ?? null)
            || ! is_bool($manifest['frozen'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['candidate_artifact_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['manifest_hash'] ?? '')) !== 1) {
            throw new InvalidArgumentException('INDEPENDENT_REVIEW_MANIFEST_INVALID');
        }
        foreach (['policy_review', 'experiment_review', 'safety_review'] as $field) {
            if (! in_array($manifest[$field] ?? null, ['PASS', 'HOLD', 'REJECT'], true)) {
                throw new InvalidArgumentException('INDEPENDENT_REVIEW_STATE_INVALID');
            }
        }
        foreach (['policy_ref', 'registry_ref', 'binding_ref'] as $field) {
            $ref = $input[$field] ?? null;
            if (! is_array($ref)) {
                throw new InvalidArgumentException('INDEPENDENT_REVIEW_REF_INVALID');
            }
            $this->exactKeys($ref, ['id', 'version', 'hash'], 'INDEPENDENT_REVIEW_REF_FIELDS_INVALID');
            if (! is_string($ref['id'] ?? null) || ! is_string($ref['version'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($ref['hash'] ?? '')) !== 1) {
                throw new InvalidArgumentException('INDEPENDENT_REVIEW_REF_INVALID');
            }
        }
    }

    private function containsForbiddenKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['query', 'raw_query', 'raw_url', 'url', 'attempt', 'result', 'report', 'history', 'order', 'payment', 'account', 'token', 'prompt', 'trace', 'hidden_reasoning'], true)
                || $this->containsForbiddenKey($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected, string $reason): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($reason);
        }
    }
}
