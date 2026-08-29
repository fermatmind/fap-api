<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Bundle;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoAgentEvidence\Retention\SeoEvidenceRetentionPolicyRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class SeoEvidenceBundleVerifier
{
    private const FIELDS = ['schema_version', 'bundle_id', 'bundle_version', 'mission_id', 'source_type', 'source_ref', 'authority_type', 'captured_at', 'content_hash', 'evidence_state', 'freshness_state', 'source_capability_state', 'retention_class', 'retention_policy_version', 'retention_policy_hash', 'expires_at', 'page_family', 'locale', 'authority_revision', 'private_data_present', 'redaction_summary', 'injection_scan_result', 'source_license_class', 'data_usage_purpose', 'egress_decision', 'lineage_refs', 'payload', 'bundle_hash'];

    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly SeoPrivateRouteNegativeSet $negativeSet,
        private readonly SeoEvidenceRetentionPolicyRegistry $retention,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $bundle @return array{valid:bool,code:string} */
    public function verify(array $bundle): array
    {
        $keys = array_keys($bundle);
        sort($keys, SORT_STRING);
        $expected = self::FIELDS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return ['valid' => false, 'code' => 'SCHEMA_FIELDS_INVALID'];
        }
        if (($bundle['schema_version'] ?? null) !== 'seo.evidence_bundle.v1'
            || ! is_int($bundle['bundle_version'])
            || ! $this->id($bundle['bundle_id'])
            || ! $this->id($bundle['mission_id'])
            || ! $this->id($bundle['source_type'])
            || ! $this->id($bundle['authority_type'])
            || ! $this->id($bundle['page_family'])
            || ! $this->id($bundle['data_usage_purpose'])
            || ! is_array($bundle['payload'])
            || ! is_array($bundle['redaction_summary'])
            || ! is_string($bundle['source_ref'])
            || strlen($bundle['source_ref']) > 256
            || str_contains($bundle['source_ref'], '#')
            || ! is_string($bundle['authority_revision'])
            || strlen($bundle['authority_revision']) > 256
            || ! in_array($bundle['locale'], ['zh-CN', 'en'], true)
            || ! in_array($bundle['evidence_state'], ['verified', 'observed', 'inferred', 'unknown', 'blocked'], true)
            || ! in_array($bundle['freshness_state'], ['fresh', 'stale', 'expired', 'unknown'], true)
            || ! in_array($bundle['source_capability_state'], ['available', 'degraded', 'unavailable', 'held'], true)
            || ! in_array($bundle['source_license_class'], ['first_party', 'licensed', 'public_fact_permitted'], true)
            || ! in_array($bundle['egress_decision'], ['not_required', 'allowed_by_gateway', 'denied', 'held'], true)
            || ($bundle['source_type'] === 'external_gateway' && $bundle['egress_decision'] !== 'allowed_by_gateway')) {
            return ['valid' => false, 'code' => 'SCHEMA_VALUE_INVALID'];
        }
        if (! is_string($bundle['bundle_hash']) || ! hash_equals($this->hasher->hashWithout($bundle, 'bundle_hash'), $bundle['bundle_hash'])) {
            return ['valid' => false, 'code' => 'BUNDLE_HASH_INVALID'];
        }
        if (! is_string($bundle['content_hash']) || ! hash_equals($this->hasher->hash($bundle['payload']), $bundle['content_hash'])) {
            return ['valid' => false, 'code' => 'CONTENT_HASH_INVALID'];
        }
        if ($bundle['private_data_present'] !== false || $this->scanner->scan($bundle['payload'])['private_data_present']) {
            return ['valid' => false, 'code' => 'PRIVATE_DATA_PRESENT'];
        }
        if ($bundle['injection_scan_result'] !== 'pass' || $this->injection->scan($bundle['payload'])['result'] !== 'pass') {
            return ['valid' => false, 'code' => 'INJECTION_BLOCKED'];
        }
        try {
            $policyBindingInvalid = (int) $bundle['bundle_version'] < 1
                || $this->negativeSet->classify((string) $bundle['source_ref'])['private']
                || str_contains((string) $bundle['source_ref'], '?')
                || $bundle['retention_policy_version'] !== SeoEvidenceRetentionPolicyRegistry::VERSION
                || ! hash_equals($this->retention->hash(), (string) $bundle['retention_policy_hash'])
                || $this->retention->expiresAt((string) $bundle['retention_class'], CarbonImmutable::parse((string) $bundle['captured_at'])) !== $bundle['expires_at'];
        } catch (Throwable) {
            $policyBindingInvalid = true;
        }
        if ($policyBindingInvalid) {
            return ['valid' => false, 'code' => 'POLICY_BINDING_INVALID'];
        }
        $lineage = (array) $bundle['lineage_refs'];
        if (count($lineage) !== count(array_unique($lineage))
            || array_filter($lineage, static fn (mixed $hash): bool => ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) !== []) {
            return ['valid' => false, 'code' => 'LINEAGE_INVALID'];
        }

        return ['valid' => true, 'code' => 'PASS'];
    }

    private function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $value) === 1;
    }
}
