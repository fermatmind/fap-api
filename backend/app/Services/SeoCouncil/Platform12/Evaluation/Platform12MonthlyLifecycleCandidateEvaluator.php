<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12MonthlyLifecycleCandidateEvaluator
{
    private const ACTIONS = ['KEEP', 'REFRESH', 'CONSOLIDATE', 'RETIRE'];

    private const SCOPE_RISKS = ['new_family', 'canonical_change', 'noindex_change', 'shared_schema_change', 'shared_layer', 'sensitive_claim'];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $candidates = $this->candidates($evidence['candidates'] ?? null);
            $state = 'READY';
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $candidates = [];
            $state = 'HOLD';
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_monthly_lifecycle_candidates.v1',
            'mission_id' => 'seo.platform12.monthly_lifecycle_candidates',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'forbidden_automatic_actions' => ['RETIRE', 'DELETE', 'UNPUBLISH', 'REDIRECT'],
            'artifact_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
            'writes' => ['cms' => false, 'url_truth' => false, 'redirects' => false, 'indexability' => false],
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return list<array<string,mixed>> */
    private function candidates(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) > 100) {
            throw new InvalidArgumentException('LIFECYCLE_CANDIDATES_INVALID');
        }

        return array_map(function (mixed $candidate): array {
            if (! is_array($candidate)
                || ! is_string($candidate['candidate_ref'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $candidate['candidate_ref']) !== 1
                || ! in_array($candidate['action'] ?? null, self::ACTIONS, true)
                || ! in_array($candidate['locale'] ?? null, ['zh-CN', 'en'], true)
                || ! is_array($candidate['basis'] ?? null)
                || ! is_array($candidate['scope_risks'] ?? null)
                || ! is_array($candidate['evidence_gaps'] ?? null)
                || ! array_is_list($candidate['evidence_gaps'])
                || count($candidate['evidence_gaps']) > 20
                || ! is_string($candidate['abstain_reason'] ?? null)
                || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $candidate['abstain_reason']) !== 1) {
                throw new InvalidArgumentException('LIFECYCLE_CANDIDATE_INVALID');
            }
            $basis = $this->basis($candidate['basis'], $candidate['locale']);
            $scopeRisks = $this->scopeRisks($candidate['scope_risks']);
            foreach ($candidate['evidence_gaps'] as $gap) {
                if (! is_string($gap) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $gap) !== 1) {
                    throw new InvalidArgumentException('EVIDENCE_GAP_INVALID');
                }
            }
            if (($basis['traffic']['state'] === 'UNAVAILABLE' && ! in_array('traffic_unavailable', $candidate['evidence_gaps'], true))
                || ($basis['material_change']['state'] === 'UNKNOWN' && ! in_array('material_change_unknown', $candidate['evidence_gaps'], true))
                || ($basis['rollback']['ready'] === false && ! in_array('rollback_not_ready', $candidate['evidence_gaps'], true))) {
                throw new InvalidArgumentException('EVIDENCE_GAP_REQUIRED');
            }

            $humanHold = $candidate['action'] === 'RETIRE'
                || $scopeRisks['new_family']
                || $scopeRisks['canonical_change']
                || $scopeRisks['noindex_change']
                || $scopeRisks['shared_schema_change'];
            $evidenceHold = $candidate['evidence_gaps'] !== [] || $candidate['abstain_reason'] !== 'none';
            $reviewState = $humanHold ? 'HUMAN_HOLD' : ($evidenceHold ? 'EVIDENCE_HOLD' : 'CANDIDATE_READY');

            return [
                'candidate_ref' => $candidate['candidate_ref'],
                'action' => $candidate['action'],
                'locale' => $candidate['locale'],
                'basis' => $basis,
                'scope_risks' => $scopeRisks,
                'risk_classification' => [
                    'shared_layer' => $scopeRisks['shared_layer'] ? 'HIGH' : 'LOCAL',
                    'sensitive_claim' => $scopeRisks['sensitive_claim'] ? 'HIGH' : 'NORMAL',
                    'destructive' => $candidate['action'] === 'RETIRE' ? 'HIGH' : 'NONE',
                ],
                'evidence_gaps' => array_values(array_unique($candidate['evidence_gaps'])),
                'abstain_reason' => $candidate['abstain_reason'],
                'review_state' => $reviewState,
                'automatic_execution' => false,
            ];
        }, $source);
    }

    /** @return array<string,mixed> */
    private function basis(array $basis, string $locale): array
    {
        foreach (['material_change', 'traffic', 'authority', 'locale', 'rollback'] as $key) {
            if (! is_array($basis[$key] ?? null)) {
                throw new InvalidArgumentException('LIFECYCLE_BASIS_INVALID');
            }
        }
        if (! in_array($basis['material_change']['state'] ?? null, ['MATERIAL', 'NON_MATERIAL', 'UNKNOWN'], true)
            || ! in_array($basis['traffic']['state'] ?? null, ['AVAILABLE', 'UNAVAILABLE'], true)
            || ($basis['locale']['value'] ?? null) !== $locale
            || ! is_bool($basis['rollback']['ready'] ?? null)) {
            throw new InvalidArgumentException('LIFECYCLE_BASIS_INVALID');
        }
        foreach ([
            $basis['material_change']['evidence_ref'] ?? null,
            $basis['authority']['revision'] ?? null,
            $basis['authority']['evidence_ref'] ?? null,
            $basis['locale']['evidence_ref'] ?? null,
            $basis['rollback']['evidence_ref'] ?? null,
        ] as $reference) {
            if (! is_string($reference) || preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1) {
                throw new InvalidArgumentException('LIFECYCLE_BASIS_INVALID');
            }
        }
        $trafficRef = $basis['traffic']['evidence_ref'] ?? null;
        if (($basis['traffic']['state'] === 'AVAILABLE' && (! is_string($trafficRef) || preg_match('/^[a-f0-9]{64}$/D', $trafficRef) !== 1))
            || ($basis['traffic']['state'] === 'UNAVAILABLE' && $trafficRef !== null)) {
            throw new InvalidArgumentException('LIFECYCLE_BASIS_INVALID');
        }

        return [
            'material_change' => array_intersect_key($basis['material_change'], array_flip(['state', 'evidence_ref'])),
            'traffic' => array_intersect_key($basis['traffic'], array_flip(['state', 'evidence_ref'])),
            'authority' => array_intersect_key($basis['authority'], array_flip(['revision', 'evidence_ref'])),
            'locale' => array_intersect_key($basis['locale'], array_flip(['value', 'evidence_ref'])),
            'rollback' => array_intersect_key($basis['rollback'], array_flip(['ready', 'evidence_ref'])),
        ];
    }

    /** @return array<string,bool> */
    private function scopeRisks(array $source): array
    {
        $keys = array_keys($source);
        $expected = self::SCOPE_RISKS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('SCOPE_RISKS_INVALID');
        }
        foreach ($source as $value) {
            if (! is_bool($value)) {
                throw new InvalidArgumentException('SCOPE_RISKS_INVALID');
            }
        }

        return $source;
    }

    /** @param array<string,mixed> $evidence */
    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }
}
