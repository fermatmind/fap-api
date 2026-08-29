<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Context;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Carbon\CarbonImmutable;

final class SeoEvidenceContextBuilder
{
    private const FROZEN_ROLE_REGISTRY_HASH = 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791';

    /** @var array<string, list<string>> */
    private const ROLE_FIELDS = [
        'seo.orchestrator' => ['status', 'summary', 'hold_reason', 'counts'],
        'seo.expert.technical_search_authority' => ['authority_hash', 'runtime_hash', 'cache_hash', 'url_truth_revision', 'detector_code', 'public_structure_state'],
        'seo.expert.search_analytics_measurement' => ['query_hmac', 'query_hmac_key_version', 'normalization_version', 'window', 'clicks', 'impressions', 'ctr_ppm', 'average_position_milli', 'freshness'],
        'seo.expert.content_entity_quality' => ['public_entity_hash', 'claim_digest', 'source_category', 'bounded_snippets', 'duplication_state', 'claim_check_state'],
        'seo.expert.competitor_research' => ['structured_facts', 'bounded_snippets', 'source_hash', 'license_class', 'captured_at'],
        'seo.expert.public_content_stability' => ['runtime_hash', 'public_projection_hash', 'material_fingerprint', 'status_counts', 'revision_hash'],
        'seo.expert.commercial_funnel_cro' => ['public_visits', 'public_cta_events', 'test_starts', 'window', 'freshness'],
        'seo.independent_reviewer' => ['bundle_hash', 'context_hash', 'policy_verdict', 'negative_guarantees', 'receipt_hash', 'hold_reason'],
    ];

    /** @var array<string, list<string>> */
    private const ROLE_SOURCE_TYPES = [
        'seo.orchestrator' => ['dependency_snapshot', 'expert_summary', 'policy_receipt'],
        'seo.expert.technical_search_authority' => ['runtime_observation', 'url_truth_projection', 'detector_result'],
        'seo.expert.search_analytics_measurement' => ['gsc_aggregate'],
        'seo.expert.content_entity_quality' => ['public_content_evidence'],
        'seo.expert.competitor_research' => ['external_gateway'],
        'seo.expert.public_content_stability' => ['runtime_observation', 'lifecycle_projection'],
        'seo.expert.commercial_funnel_cro' => ['public_funnel_aggregate'],
        'seo.independent_reviewer' => ['dependency_snapshot', 'policy_receipt', 'deletion_receipt'],
    ];

    public function __construct(
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoRoleCapabilityRegistry $roles,
    ) {}

    public function scopeDecision(string $missionType, string $roleId, string $pageFamily, string $locale): string
    {
        $registry = $this->roles->registry();
        $role = collect((array) ($registry['roles'] ?? []))->firstWhere('role_id', $roleId);
        if (($registry['registry_status'] ?? null) !== 'frozen'
            || ($registry['registry_hash'] ?? null) !== self::FROZEN_ROLE_REGISTRY_HASH
            || ! is_array($role)
            || ! in_array($missionType, (array) ($role['allowed_missions'] ?? []), true)
            || ! in_array($pageFamily, (array) ($role['page_family_scope'] ?? []), true)
            || ! in_array($locale, (array) ($role['locale_scope'] ?? []), true)) {
            return 'EVIDENCE_HOLD';
        }

        return $roleId === 'career.content_agent' ? 'SOURCE_CAPABILITY_UNAVAILABLE' : 'READY';
    }

    /** @param list<array<string, mixed>> $bundles @return array<string, mixed> */
    public function build(string $missionId, string $missionType, string $roleId, string $pageFamily, string $locale, array $bundles): array
    {
        $now = CarbonImmutable::now('UTC');
        $status = 'READY';
        $payload = [];
        $refs = [];
        $capabilities = [];
        $revisions = [];
        $hashes = array_column($bundles, 'bundle_hash');
        $scopeStatus = $this->scopeDecision($missionType, $roleId, $pageFamily, $locale);
        $metadataScan = $this->scanner->scan([
            'mission_reference' => $missionId,
            'role_reference' => $roleId,
            'page_family_reference' => $pageFamily,
            'locale_reference' => $locale,
        ]);
        if ($scopeStatus !== 'READY' || $metadataScan['private_data_present']) {
            $status = $scopeStatus === 'SOURCE_CAPABILITY_UNAVAILABLE' && ! $metadataScan['private_data_present']
                ? 'SOURCE_CAPABILITY_UNAVAILABLE'
                : 'EVIDENCE_HOLD';
        }
        if ($bundles === [] && $status === 'READY') {
            $status = 'EVIDENCE_HOLD';
        }
        if (! (bool) config('seo_agent_evidence.context_build_enabled', false) || (! isset(self::ROLE_FIELDS[$roleId]) && $roleId !== 'career.content_agent')) {
            $status = $scopeStatus === 'SOURCE_CAPABILITY_UNAVAILABLE' ? 'SOURCE_CAPABILITY_UNAVAILABLE' : 'EVIDENCE_HOLD';
        }
        foreach ($bundles as $bundle) {
            $verification = $this->verifier->verify($bundle);
            if (! $verification['valid'] || ($bundle['mission_id'] ?? null) !== $missionId
                || ($bundle['page_family'] ?? null) !== $pageFamily || ($bundle['locale'] ?? null) !== $locale
                || array_diff((array) ($bundle['lineage_refs'] ?? []), $hashes) !== []
                || ! in_array((string) ($bundle['source_type'] ?? ''), self::ROLE_SOURCE_TYPES[$roleId] ?? [], true)
                || $this->containsForbiddenField($bundle['payload'] ?? null)) {
                $status = 'EVIDENCE_HOLD';
            }
            if ($this->scanner->scan($bundle['payload'] ?? null)['private_data_present']) {
                $status = 'EVIDENCE_HOLD';
            }
            $revisionKey = (string) ($bundle['authority_type'] ?? 'unknown');
            $revisions[$revisionKey][(string) ($bundle['authority_revision'] ?? '')] = true;
            $capability = (string) ($bundle['source_capability_state'] ?? 'unavailable');
            $capabilities[] = $capability;
            if ($status !== 'EVIDENCE_HOLD' && in_array($capability, ['unavailable', 'held'], true)) {
                $status = 'SOURCE_CAPABILITY_UNAVAILABLE';
            }
            if ($status === 'READY' && in_array((string) ($bundle['freshness_state'] ?? ''), ['stale', 'expired', 'unknown'], true)) {
                $status = 'MEASUREMENT_HOLD';
            }
            foreach ((array) ($bundle['payload'] ?? []) as $field => $value) {
                if (in_array((string) $field, self::ROLE_FIELDS[$roleId] ?? [], true)) {
                    $payload[(string) $field] = $value;
                }
            }
            $refs[] = [
                'bundle_id' => $bundle['bundle_id'] ?? null,
                'bundle_version' => $bundle['bundle_version'] ?? null,
                'bundle_hash' => $bundle['bundle_hash'] ?? null,
            ];
        }
        foreach ($revisions as $values) {
            if (count(array_filter(array_keys($values), 'strlen')) > 1) {
                $status = 'EVIDENCE_HOLD';
            }
        }
        if ($this->scanner->scan($payload)['private_data_present']) {
            $status = 'EVIDENCE_HOLD';
            $payload = [];
        }
        if ($status === 'EVIDENCE_HOLD') {
            $payload = [];
        }

        $safeMissionId = $metadataScan['private_data_present'] ? 'mission:held' : $missionId;

        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => hash('sha256', implode('|', [$safeMissionId, $roleId, $pageFamily, $locale, $now->format('c')])),
            'context_version' => 1,
            'mission_id' => $safeMissionId,
            'mission_type' => $missionType,
            'role_id' => $roleId,
            'page_family' => $pageFamily,
            'locale' => $locale,
            'built_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
            'bundle_refs' => $refs,
            'source_capability_states' => array_values(array_unique($capabilities)),
            'evidence_summary' => ['bundle_count' => count($bundles), 'private_data_present' => false],
            'payload' => $payload,
            'status' => $status,
            'execution_allowed' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'write_permissions' => [],
            'tool_allowlist' => [],
            'egress_allowlist' => [],
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
    }

    private function containsForbiddenField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $forbidden = ['raw_query', 'query', 'query_display_masked', 'masked_query', 'source_body', 'full_body', 'private_id', 'attempt_id', 'result_id', 'report_id', 'order_id', 'payment_id', 'user_id', 'account_id'];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                return true;
            }
            if ($this->containsForbiddenField($child)) {
                return true;
            }
        }

        return false;
    }
}
