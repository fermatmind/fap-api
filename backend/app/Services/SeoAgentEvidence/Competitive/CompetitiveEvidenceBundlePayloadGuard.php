<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;

final class CompetitiveEvidenceBundlePayloadGuard
{
    /** @var list<string> */
    private const PAYLOAD_FIELDS = [
        'environment',
        'release_ref',
        'cohort_id',
        'source_policy_set_hash',
        'measurement_bundle_set_hash',
        'projections',
        'competitive_output',
        '11i_handoff',
        'dependency_ingestion',
    ];

    /** @var list<string> */
    private const DIAGNOSTIC_FIELDS = [
        'external_reads',
        'logical_requests',
        'transport_attempts',
        'retry_count',
    ];

    public function __construct(
        private readonly CompetitiveEvidenceBoundaryGuard $boundary,
        private readonly SeoPrivateDataScanner $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $payload @return array{valid:bool,code:string} */
    public function verify(array $payload): array
    {
        if (! $this->exactKeys($payload, self::PAYLOAD_FIELDS)
            || ! in_array($payload['environment'] ?? null, ['staging', 'production'], true)
            || preg_match('/^release_[a-p]{64}$/D', (string) ($payload['release_ref'] ?? '')) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', (string) ($payload['cohort_id'] ?? '')) !== 1
            || ! $this->hash($payload['source_policy_set_hash'] ?? null)
            || ! $this->hash($payload['measurement_bundle_set_hash'] ?? null)) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_SCHEMA_INVALID'];
        }

        $projections = $payload['projections'] ?? null;
        if (! is_array($projections) || ! array_is_list($projections) || count($projections) < 4) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_PROJECTIONS_INVALID'];
        }
        $sourceIds = [];
        $competitorCount = 0;
        $evidenceRefs = (array) data_get($payload, 'competitive_output.findings.0.evidence_refs', []);
        foreach ($projections as $projection) {
            if (! is_array($projection) || ! $this->boundary->projection($projection)) {
                if (is_array($projection)) {
                    $failure = $this->boundary->projectionFailureCode($projection);
                    if (in_array($failure, ['PRIVATE_DATA_PRESENT', 'INJECTION_BLOCKED'], true)) {
                        return ['valid' => false, 'code' => $failure];
                    }
                }

                return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_PROJECTIONS_INVALID'];
            }
            if (($projection['cohort_id'] ?? null) !== $payload['cohort_id']
                || ($projection['page_family'] ?? null) !== 'tests'
                || ! in_array($projection['projection_hash'] ?? null, $evidenceRefs, true)) {
                return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_PROJECTIONS_INVALID'];
            }
            $sourceIds[] = (string) $projection['source_id'];
            if (($projection['source_class'] ?? null) === 'competitor_public') {
                $competitorCount++;
            }
        }
        if (count($sourceIds) !== count(array_unique($sourceIds)) || $competitorCount < 2) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_PROJECTIONS_INVALID'];
        }

        $output = $payload['competitive_output'] ?? null;
        $handoff = $payload['11i_handoff'] ?? null;
        if (! is_array($output) || ! is_array($handoff)
            || ($output['status'] ?? null) !== 'READY'
            || ! $this->boundary->output($output)
            || ! $this->boundary->handoff($handoff)
            || ($output['11i_handoff'] ?? null) !== $handoff
            || ($handoff['source_count'] ?? null) !== $competitorCount) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_OUTPUT_INVALID'];
        }

        $diagnostics = $payload['dependency_ingestion'] ?? null;
        if (! is_array($diagnostics) || ! $this->exactKeys($diagnostics, self::DIAGNOSTIC_FIELDS)) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_DIAGNOSTICS_INVALID'];
        }
        foreach (self::DIAGNOSTIC_FIELDS as $field) {
            if (! is_int($diagnostics[$field]) || $diagnostics[$field] < 0 || $diagnostics[$field] > 32) {
                return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_DIAGNOSTICS_INVALID'];
            }
        }
        if ($diagnostics['external_reads'] !== $diagnostics['transport_attempts']
            || $diagnostics['logical_requests'] > $diagnostics['transport_attempts']
            || $diagnostics['retry_count'] > $diagnostics['transport_attempts']) {
            return ['valid' => false, 'code' => 'COMPETITIVE_PAYLOAD_DIAGNOSTICS_INVALID'];
        }

        $residual = [
            'environment' => $payload['environment'],
            'release_ref' => $payload['release_ref'],
            'cohort_id' => $payload['cohort_id'],
            'dependency_ingestion' => $diagnostics,
        ];
        if ($this->privacy->scan($residual)['private_data_present']) {
            return ['valid' => false, 'code' => 'PRIVATE_DATA_PRESENT'];
        }
        if ($this->injection->scan($residual)['result'] !== 'pass') {
            return ['valid' => false, 'code' => 'INJECTION_BLOCKED'];
        }

        return ['valid' => true, 'code' => 'PASS'];
    }

    /** @param array<string, mixed> $value */
    public function envelopeIsBound(array $value): bool
    {
        $payload = $value['payload'] ?? null;
        if (! is_array($payload)) {
            return false;
        }
        $environment = (string) ($payload['environment'] ?? '');
        $releaseRef = (string) ($payload['release_ref'] ?? '');
        $lineage = $value['lineage_refs'] ?? null;

        return ($value['authority_type'] ?? null) === 'competitive_structural_projection'
            && ($value['source_type'] ?? null) === 'external_gateway'
            && ($value['egress_decision'] ?? null) === 'allowed_by_gateway'
            && ($value['page_family'] ?? null) === 'tests'
            && ($value['locale'] ?? null) === 'en'
            && ($value['bundle_id'] ?? null) === 'competitive:'.$environment.':'.$releaseRef
            && ($value['mission_id'] ?? null) === 'competitive:ingestion:'.$releaseRef
            && $this->hash($value['source_ref'] ?? null)
            && $this->hash($value['authority_revision'] ?? null)
            && is_array($lineage)
            && array_is_list($lineage)
            && count($lineage) === 2
            && count(array_unique($lineage)) === 2
            && $this->hash($lineage[0] ?? null)
            && $this->hash($lineage[1] ?? null);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
