<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;

final class CompetitiveEvidenceBoundaryGuard
{
    /** @var list<string> */
    private const FORBIDDEN_FIELDS = [
        'body', 'body_copy', 'competitor_copy', 'competitor_snippet', 'html', 'paragraph',
        'prose', 'quote', 'raw_html', 'sentence', 'snippet', 'text', 'title',
    ];

    /** @var list<string> */
    private const SAFE_HASH_FIELDS = [
        'canonical_hash', 'claim_hash', 'evidence_hash', 'finding_hash', 'handoff_hash',
        'label_hash', 'module_hash', 'output_hash', 'pattern_hash', 'policy_hash',
        'projection_hash', 'public_url_hash', 'relation_hash', 'response_hash',
        'structure_fingerprint', 'url_hash',
    ];

    public function __construct(
        private readonly CompetitiveEvidenceContractRegistry $contracts,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoPrivateDataScanner $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $projection */
    public function projection(array $projection): bool
    {
        $version = (string) ($projection['version'] ?? '');

        return in_array($version, ['seo.competitive_page_projection.v1', 'seo.competitive_page_projection.v2'], true)
            && $this->exactSchemaKeys($projection, $version)
            && in_array($projection['source_class'] ?? null, ['fermatmind_public', 'competitor_public'], true)
            && ($projection['redaction']['raw_html_retained'] ?? null) === false
            && ($projection['redaction']['competitor_snippets_retained'] ?? null) === false
            && ($projection['redaction']['private_data_present'] ?? null) === false
            && ($projection['redaction']['injection_scan_result'] ?? null) === 'pass'
            && $this->safePayload($projection)
            && $this->sealed($projection, 'projection_hash');
    }

    /** @param array<string, mixed> $projection */
    public function projectionFailureCode(array $projection): string
    {
        $version = (string) ($projection['version'] ?? '');
        if (! in_array($version, ['seo.competitive_page_projection.v1', 'seo.competitive_page_projection.v2'], true)
            || ! $this->exactSchemaKeys($projection, $version)) {
            return 'COMPETITIVE_PAYLOAD_SCHEMA_INVALID';
        }

        return $this->safePayloadCode($projection);
    }

    /** @param array<string, mixed> $finding */
    public function finding(array $finding): bool
    {
        return $this->exactSchemaKeys($finding, 'seo.competitive_evidence_finding.v1')
            && ($finding['version'] ?? null) === 'seo.competitive_evidence_finding.v1'
            && ($finding['role_id'] ?? null) === 'seo.expert.competitor_research'
            && ($finding['mode_id'] ?? null) === 'competitive_evidence'
            && ($finding['execution_allowed'] ?? null) === false
            && ($finding['outreach_actions'] ?? null) === 0
            && ($finding['digital_pr_scope'] ?? null) === 'deferred_p2_manual'
            && $this->findingReferencesAreHashes($finding)
            && $this->safePayload($finding)
            && $this->sealed($finding, 'finding_hash');
    }

    /** @param array<string, mixed> $handoff */
    public function handoff(array $handoff): bool
    {
        return $this->exactSchemaKeys($handoff, 'seo.competitive_11i_handoff.v1')
            && ($handoff['version'] ?? null) === 'seo.competitive_11i_handoff.v1'
            && in_array($handoff['page_necessity'] ?? null, ['necessary', 'conditional', 'not_supported', 'unknown'], true)
            && is_int($handoff['template_similarity'] ?? null)
            && $handoff['template_similarity'] >= 0
            && $handoff['template_similarity'] <= 10000
            && in_array($handoff['translation_only'] ?? null, ['yes', 'no', 'unknown'], true)
            && in_array($handoff['source_freshness'] ?? null, ['fresh', 'stale', 'expired', 'unknown', 'conflict'], true)
            && ($handoff['execution_allowed'] ?? null) === false
            && ($handoff['outreach_actions'] ?? null) === 0
            && ($handoff['digital_pr_scope'] ?? null) === 'deferred_p2_manual'
            && $this->safePayload($handoff)
            && $this->sealed($handoff, 'handoff_hash');
    }

    /** @param array<string, mixed> $output */
    public function output(array $output): bool
    {
        $finding = (array) (($output['findings'] ?? [])[0] ?? []);
        $handoff = (array) ($output['11i_handoff'] ?? []);

        return $this->exactSchemaKeys($output, 'seo.competitive_evidence_output.v1')
            && ($output['version'] ?? null) === 'seo.competitive_evidence_output.v1'
            && in_array($output['status'] ?? null, ['READY', 'HOLD'], true)
            && count((array) ($output['findings'] ?? [])) === 1
            && $this->finding($finding)
            && $this->handoff($handoff)
            && ($output['model_calls'] ?? null) === 0
            && ($output['tool_calls'] ?? null) === 0
            && ($output['external_calls'] ?? null) === 0
            && ($output['cms_writes'] ?? null) === 0
            && ($output['url_truth_writes'] ?? null) === 0
            && ($output['search_writes'] ?? null) === 0
            && ($output['business_writes'] ?? null) === 0
            && ($output['execution_allowed'] ?? null) === false
            && ($output['outreach_actions'] ?? null) === 0
            && ($output['digital_pr_scope'] ?? null) === 'deferred_p2_manual'
            && $this->safePayload($output)
            && $this->sealed($output, 'output_hash');
    }

    /** @param array<string, mixed> $value */
    private function safePayload(array $value): bool
    {
        return $this->safePayloadCode($value) === 'PASS';
    }

    /** @param array<string, mixed> $value */
    private function safePayloadCode(array $value): string
    {
        if (! $this->hashFieldsAreValid($value)
            || $this->containsForbiddenField($value)
            || $this->containsRawUrlOrMarkup($value)) {
            return 'COMPETITIVE_PAYLOAD_CONTENT_INVALID';
        }
        if (($this->privacy->scan($this->privacyPayload($value))['decision'] ?? null) !== 'pass') {
            return 'PRIVATE_DATA_PRESENT';
        }
        if (($this->injection->scan($this->injectionPayload($value))['result'] ?? null) !== 'pass') {
            return 'INJECTION_BLOCKED';
        }

        return 'PASS';
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function privacyPayload(array $value): array
    {
        foreach ($value as $key => $child) {
            if (in_array($key, ['private_data_present', 'injection_scan_result', 'captured_at', 'expires_at', 'module_order_bp', 'evidence_refs', 'source_ref'], true)
                || (in_array($key, self::SAFE_HASH_FIELDS, true) && $this->hash($child))) {
                unset($value[$key]);

                continue;
            }
            if (is_array($child)) {
                $value[$key] = $this->privacyPayload($child);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function hashFieldsAreValid(array $value): bool
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && (str_ends_with($key, '_hash') || $key === 'structure_fingerprint')) {
                if ($key === 'canonical_hash' && $child === null) {
                    continue;
                }
                if (! in_array($key, self::SAFE_HASH_FIELDS, true) || ! $this->hash($child)) {
                    return false;
                }
            }
            if (is_array($child) && ! $this->hashFieldsAreValid($child)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function injectionPayload(array $value): array
    {
        foreach ($value as $key => $child) {
            if (in_array($key, ['execution_allowed', 'injection_scan_result', 'policy_hash'], true)) {
                unset($value[$key]);

                continue;
            }
            if (is_array($child)) {
                $value[$key] = $this->injectionPayload($child);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function containsForbiddenField(array $value): bool
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(mb_strtolower($key, 'UTF-8'), self::FORBIDDEN_FIELDS, true)) {
                return true;
            }
            if (is_array($child) && $this->containsForbiddenField($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    private function containsRawUrlOrMarkup(array $value): bool
    {
        foreach ($value as $child) {
            if (is_array($child) && $this->containsRawUrlOrMarkup($child)) {
                return true;
            }
            if (is_string($child)
                && (preg_match('#https?://#i', $child) === 1 || preg_match('/<[^>]+>/', $child) === 1)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    private function sealed(array $value, string $hashField): bool
    {
        return is_string($value[$hashField] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $value[$hashField]) === 1
            && hash_equals($this->hasher->hashWithout($value, $hashField), (string) $value[$hashField]);
    }

    /** @param array<string, mixed> $finding */
    private function findingReferencesAreHashes(array $finding): bool
    {
        $refs = $finding['evidence_refs'] ?? null;
        if (! is_array($refs) || ! array_is_list($refs) || count($refs) < 3 || count($refs) > 32
            || count($refs) !== count(array_unique($refs))) {
            return false;
        }
        foreach ($refs as $ref) {
            if (! $this->hash($ref)) {
                return false;
            }
        }
        foreach ((array) ($finding['competitor_claims'] ?? []) as $claim) {
            if (! is_array($claim) || ! $this->hash($claim['source_ref'] ?? null) || ! $this->hash($claim['claim_hash'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param array<string, mixed> $value */
    private function exactSchemaKeys(array $value, string $schemaId): bool
    {
        $schema = $this->contracts->schema($schemaId);
        if (($schema['additionalProperties'] ?? null) !== false || ! is_array($schema['required'] ?? null)) {
            return false;
        }
        $actual = array_keys($value);
        $required = $schema['required'];
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);

        return $actual === $required;
    }
}
