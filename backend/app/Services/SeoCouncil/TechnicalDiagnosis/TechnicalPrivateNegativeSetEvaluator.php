<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class TechnicalPrivateNegativeSetEvaluator
{
    /** @var list<string> */
    private const PROBES = [
        '/en/result/x', '/en/results/x', '/en/attempt/x', '/en/attempts/x',
        '/en/report/x', '/en/reports/x', '/en/history/x', '/en/order/x',
        '/en/orders/x', '/en/checkout/x', '/en/payment/x', '/en/payments/x',
        '/en/account/x', '/en/accounts/x', '/en/auth/x', '/en/invite/x',
        '/en/invites/x', '/en/recovery/x', '/en/token/x', '/en/tokens/x',
        '/en/share/x', '/en/shares/x', '/en/private/share/x',
        '/en/tests/x/%72%65%73%75%6c%74/y', '/EN/RESULT/X',
        '/en/tests/x/history/y', '/en/tests/x?token=y', '/en/tests/x?attempt_id=y',
        '/en/tests/x?private_share=y', '/en/tests/x?authorization=y',
    ];

    public function __construct(
        private readonly TechnicalDiagnosisEvidenceContextBuilder $contexts,
        private readonly TechnicalDiagnosisEngine $engine,
        private readonly TechnicalDiagnosisContractValidator $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, int> */
    public function evaluate(): array
    {
        $metrics = [
            'probe_total' => count(self::PROBES), 'private_url_context_count' => 0,
            'private_url_recommendation_count' => 0, 'private_url_owner_candidate_count' => 0,
            'private_url_feed_eligibility_count' => 0, 'private_data_leak_count' => 0,
            'runtime_authority_escalation_count' => 0, 'hold_discovery_bypass_count' => 0,
            'evasion_bypass_count' => 0,
        ];
        foreach (self::PROBES as $index => $probe) {
            $publicRef = 'https://example.test'.$probe;
            $allowed = $this->contexts->sanitizePublicReferences([$publicRef]);
            $metrics['private_url_context_count'] += count($allowed);
            $metrics['private_url_feed_eligibility_count'] += count($allowed);
            $request = $this->request($index, $publicRef);
            $context = $this->context($request, $index);
            $output = $this->engine->diagnose($request, $context);
            $metrics['private_url_recommendation_count'] += (int) (data_get($output, 'recommended_path.kind') === 'repair_candidate');
            $metrics['private_url_owner_candidate_count'] += (int) (data_get($output, 'recommended_path.owner') !== 'none');
            $metrics['runtime_authority_escalation_count'] += (int) (($output['status'] ?? null) !== 'HOLD');
            $metrics['hold_discovery_bypass_count'] += (int) (($output['discovery_decision'] ?? null) !== 'HOLD_DISCOVERY');
            $encoded = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $metrics['private_data_leak_count'] += (int) (str_contains($encoded, $probe) || str_contains($encoded, $publicRef));
            $metrics['evasion_bypass_count'] += (int) ($allowed !== []);
        }

        return $metrics;
    }

    /** @return array<string, mixed> */
    private function request(int $index, string $publicRef): array
    {
        $request = [
            'diagnosis_id' => 'diagnosis:private-probe:'.$index, 'diagnosis_version' => 1,
            'mission_id' => 'mission:private-probe:'.$index, 'run_id' => 'run:private-probe:'.$index,
            'role_id' => 'seo.expert.technical_search_authority', 'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_bundle_refs' => [['bundle_id' => 'bundle:private-probe:'.$index, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', 'private-probe:'.$index)]],
            'dependency_snapshot_ref' => ['snapshot_hash' => str_repeat('d', 64)],
            'detector_registry_ref' => ['version' => 'fixture', 'hash' => str_repeat('e', 64)],
            'url_truth_revision' => 'url-truth:fixture:v1', 'runtime_revision' => 'runtime:fixture:v1',
            'deployment_revision' => str_repeat('a', 40), 'authority_revision' => 'authority:fixture:v1',
            'requested_scope' => ['sanitized_public_refs' => [$publicRef]],
            'requested_at' => '2026-08-30T00:00:00Z', 'execution_allowed' => false, 'allow_delegation' => false,
        ];

        return $this->contracts->sealRequest($request);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function context(array $request, int $index): array
    {
        $context = [
            'context_id' => hash('sha256', 'private-context:'.$index),
            'context_version' => 'seo.technical_diagnosis_evidence_context.v1',
            'request_hash' => $request['request_hash'],
            'bundle_refs' => [['bundle_id' => 'bundle:private-probe:'.$index, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', 'private-probe:'.$index)]],
            'payload' => ['detector_code' => 'false_404', 'observations' => ['authority_public' => false, 'runtime_status' => 200], 'source_count' => 2, 'repeat_observation' => true, 'revision_consistent' => true, 'affected_url_count' => 1, 'affected_family_count' => 1],
            'lineage_refs' => [], 'redaction_summary' => ['redacted_field_count' => 0, 'redacted_fields' => []],
            'status' => 'READY', 'diagnosis_allowed' => true, 'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
    }
}
