<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Bundle;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoAgentEvidence\Retention\SeoEvidenceRetentionPolicyRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SeoEvidenceBundleFactory
{
    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly SeoPrivateRouteNegativeSet $negativeSet,
        private readonly SeoEvidenceRetentionPolicyRegistry $retention,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $scan = $this->scanner->scan($input, SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS);
        if ($scan['private_data_present']) {
            throw new InvalidArgumentException('SEO_EVIDENCE_PRIVATE_DATA');
        }

        $capturedAt = CarbonImmutable::parse((string) ($input['captured_at'] ?? 'now'))->utc();
        $payload = $input['payload'] ?? [];
        $sourceRef = $this->safeRef($input['source_ref'] ?? null);
        if ($this->negativeSet->classify($sourceRef)['private']) {
            throw new InvalidArgumentException('SEO_EVIDENCE_PRIVATE_DATA');
        }
        if ($this->injection->scan($payload)['result'] !== 'pass') {
            throw new InvalidArgumentException('SEO_EVIDENCE_INJECTION_BLOCKED');
        }
        $retentionClass = (string) ($input['retention_class'] ?? '');
        if (! $this->retention->isPersistable($retentionClass)) {
            throw new InvalidArgumentException('SEO_EVIDENCE_RETENTION_CLASS');
        }

        $bundle = [
            'schema_version' => 'seo.evidence_bundle.v1',
            'bundle_id' => $this->boundedId($input['bundle_id'] ?? null),
            'bundle_version' => (int) ($input['bundle_version'] ?? 1),
            'mission_id' => $this->boundedId($input['mission_id'] ?? null),
            'source_type' => $this->boundedId($input['source_type'] ?? null),
            'source_ref' => $sourceRef,
            'authority_type' => $this->boundedId($input['authority_type'] ?? null),
            'captured_at' => $capturedAt->format('Y-m-d\TH:i:s\Z'),
            'content_hash' => $this->hasher->hash($payload),
            'evidence_state' => (string) ($input['evidence_state'] ?? 'unknown'),
            'freshness_state' => (string) ($input['freshness_state'] ?? 'unknown'),
            'source_capability_state' => (string) ($input['source_capability_state'] ?? 'held'),
            'retention_class' => $retentionClass,
            'retention_policy_version' => SeoEvidenceRetentionPolicyRegistry::VERSION,
            'retention_policy_hash' => $this->retention->hash(),
            'expires_at' => $this->retention->expiresAt($retentionClass, $capturedAt),
            'page_family' => $this->boundedId($input['page_family'] ?? null),
            'locale' => (string) ($input['locale'] ?? ''),
            'authority_revision' => $this->safeRef($input['authority_revision'] ?? null),
            'private_data_present' => false,
            'redaction_summary' => ['scanner_version' => $scan['scanner_version'], 'redacted_count' => 0],
            'injection_scan_result' => 'pass',
            'source_license_class' => (string) ($input['source_license_class'] ?? 'first_party'),
            'data_usage_purpose' => $this->boundedId($input['data_usage_purpose'] ?? null),
            'egress_decision' => (string) ($input['egress_decision'] ?? 'not_required'),
            'lineage_refs' => array_values((array) ($input['lineage_refs'] ?? [])),
            'payload' => $payload,
        ];
        if ($bundle['bundle_version'] < 1) {
            throw new InvalidArgumentException('SEO_EVIDENCE_VERSION');
        }
        $bundle['bundle_hash'] = $this->hasher->hash($bundle);

        return $bundle;
    }

    private function boundedId(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $value) !== 1) {
            throw new InvalidArgumentException('SEO_EVIDENCE_ID');
        }

        return $value;
    }

    private function safeRef(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 256 || str_contains($value, '?') || str_contains($value, '#')) {
            throw new InvalidArgumentException('SEO_EVIDENCE_REF');
        }

        return $value;
    }
}
