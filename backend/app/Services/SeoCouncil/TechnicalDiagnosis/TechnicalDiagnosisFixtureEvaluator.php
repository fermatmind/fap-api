<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use RuntimeException;

final class TechnicalDiagnosisFixtureEvaluator
{
    /** @var list<string> */
    private const ISSUE_CODES = [
        'verified_false_404', 'verified_false_noindex', 'stale_noindex_hypothesis',
        'verified_empty_shell', 'partial_render_failure', 'api_payload_gap',
        'visible_schema_parity_hold', 'verified_canonical_drift', 'hreflang_missing',
        'hreflang_non_reciprocal', 'cache_revision_drift', 'deployment_revision_drift',
        'sitemap_drift', 'llms_feed_drift', 'private_feed_leak',
        'shared_api_root_cause', 'independent_url_root_causes',
    ];

    public function __construct(
        private readonly TechnicalDiagnosisEngine $engine,
        private readonly TechnicalDiagnosisContractValidator $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $corpus = json_decode((string) file_get_contents(resource_path('seo-agent/council/technical-diagnosis/fixtures/seo.technical_diagnosis_fixtures.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($corpus) || ! is_array($corpus['fixtures'] ?? null)) {
            throw new RuntimeException('Technical Diagnosis fixture corpus is invalid.');
        }
        $metrics = [
            'fixture_total' => count($corpus['fixtures']), 'true_positive' => 0, 'true_negative' => 0,
            'false_positive' => 0, 'false_negative' => 0, 'unsupported_p0_p1_count' => 0,
            'authority_invention_count' => 0, 'private_url_leak_count' => 0,
            'policy_bypass_count' => 0, 'requested_role_expansion_bypass_count' => 0,
            'write_attempt_count' => 0, 'shared_root_misclassification_count' => 0,
            'evidence_state_misclassification_count' => 0, 'hypothesis_fact_confusion_count' => 0,
        ];
        $results = [];
        foreach ($corpus['fixtures'] as $fixture) {
            if (! is_array($fixture)) {
                throw new RuntimeException('Technical Diagnosis fixture must be an object.');
            }
            $request = $this->request($fixture);
            $context = $this->context($request, $fixture);
            $output = $this->engine->diagnose($request, $context);
            if (! $this->contracts->output($output)) {
                throw new RuntimeException('Technical Diagnosis fixture produced an invalid output.');
            }
            $finding = is_array($output['findings'][0] ?? null) ? $output['findings'][0] : null;
            $actualOutcome = (string) ($finding['detector_code'] ?? $output['hold_reason'] ?? 'unknown');
            $actualIssue = in_array($actualOutcome, self::ISSUE_CODES, true);
            $expectedIssue = (bool) $fixture['expected_issue'];
            $metrics[$expectedIssue ? ($actualIssue ? 'true_positive' : 'false_negative') : ($actualIssue ? 'false_positive' : 'true_negative')]++;
            $actualState = (string) ($finding['evidence_state'] ?? 'blocked');
            $metrics['evidence_state_misclassification_count'] += (int) ($actualState !== $fixture['expected_state']);
            $metrics['policy_bypass_count'] += (int) ((bool) $fixture['expected_hold'] && ($output['status'] ?? null) !== 'HOLD');
            $metrics['policy_bypass_count'] += (int) ($actualOutcome !== $fixture['expected_outcome']);
            $metrics['requested_role_expansion_bypass_count'] += (int) (($fixture['security_kind'] ?? null) === 'requested_role' && ($output['status'] ?? null) !== 'HOLD');
            if (is_array($finding) && in_array($finding['severity'] ?? null, ['P0', 'P1'], true)) {
                $metrics['unsupported_p0_p1_count'] += (int) (($finding['evidence_state'] ?? null) !== 'verified_fact'
                    || ($finding['confidence'] ?? null) !== 'high');
            }
            if (($fixture['security_kind'] ?? null) === 'authority_invention') {
                $metrics['authority_invention_count'] += (int) ($actualOutcome !== 'authority_invention_hold');
            }
            if (($fixture['security_kind'] ?? null) === 'private_url') {
                $encoded = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $metrics['private_url_leak_count'] += (int) (str_contains($encoded, 'private-id') || str_contains($encoded, (string) $fixture['public_ref']));
            }
            if (($fixture['security_kind'] ?? null) === 'write') {
                $metrics['write_attempt_count'] += (int) (data_get($output, 'recommended_path.kind') === 'repair_candidate'
                    || ($output['write_permission'] ?? null) !== false);
            }
            if (array_key_exists('expected_shared', $fixture)) {
                $metrics['shared_root_misclassification_count'] += (int) ((bool) data_get($finding, 'shared_layer_impact', false) !== (bool) $fixture['expected_shared']);
            }
            if (($fixture['security_kind'] ?? null) === 'hypothesis') {
                $metrics['hypothesis_fact_confusion_count'] += (int) ($output['verified_facts'] !== [] || $output['root_cause_hypotheses'] === []);
            }
            $results[] = [
                'fixture_id' => $fixture['id'], 'expected_outcome' => $fixture['expected_outcome'],
                'actual_outcome' => $actualOutcome, 'passed' => $actualOutcome === $fixture['expected_outcome'],
                'expected_state' => $fixture['expected_state'], 'actual_state' => $actualState,
                'output_hash' => $output['output_hash'],
            ];
        }
        $precisionDenominator = $metrics['true_positive'] + $metrics['false_positive'];
        $recallDenominator = $metrics['true_positive'] + $metrics['false_negative'];
        $metrics['detection_precision'] = ['numerator' => $metrics['true_positive'], 'denominator' => $precisionDenominator];
        $metrics['detection_recall'] = ['numerator' => $metrics['true_positive'], 'denominator' => $recallDenominator];

        return [
            'fixture_set_id' => $corpus['fixture_set_id'],
            'fixture_set_version' => $corpus['fixture_set_version'],
            'fixture_set_hash' => $this->hasher->hash($corpus),
            'metrics' => $metrics,
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function request(array $fixture): array
    {
        $hash = hash('sha256', 'fixture-bundle:'.$fixture['id']);
        $request = [
            'diagnosis_id' => 'diagnosis:fixture:'.$fixture['id'],
            'diagnosis_version' => 1,
            'mission_id' => 'mission:fixture:'.$fixture['id'],
            'run_id' => 'run:fixture:'.$fixture['id'],
            'role_id' => 'seo.expert.technical_search_authority',
            'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests',
            'locale' => 'en',
            'evidence_bundle_refs' => [['bundle_id' => 'bundle:fixture:'.$fixture['id'], 'bundle_version' => 1, 'bundle_hash' => $hash]],
            'dependency_snapshot_ref' => ['snapshot_hash' => str_repeat('d', 64)],
            'detector_registry_ref' => ['version' => 'fixture', 'hash' => str_repeat('e', 64)],
            'url_truth_revision' => 'url-truth:fixture:v1',
            'runtime_revision' => 'runtime:fixture:v1',
            'deployment_revision' => str_repeat('a', 40),
            'authority_revision' => 'authority:fixture:v1',
            'requested_scope' => [
                'sanitized_public_refs' => [(string) ($fixture['public_ref'] ?? 'https://example.test/en/tests/public-page')],
            ],
            'requested_at' => '2026-08-30T00:00:00Z',
            'execution_allowed' => false,
            'allow_delegation' => false,
        ];
        foreach ((array) ($fixture['request_overrides'] ?? []) as $key => $value) {
            $request[(string) $key] = $value;
        }

        return $this->contracts->sealRequest($request);
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $fixture @return array<string, mixed> */
    private function context(array $request, array $fixture): array
    {
        $payload = [
            'detector_code' => $fixture['detector'],
            'observations' => $fixture['observations'],
            'source_count' => 3,
            'repeat_observation' => true,
            'revision_consistent' => true,
            'affected_url_count' => (int) ($fixture['affected_url_count'] ?? data_get($fixture, 'observations.affected_url_count', 1)),
            'affected_family_count' => (int) ($fixture['affected_family_count'] ?? data_get($fixture, 'observations.affected_family_count', 1)),
        ];
        if (isset($fixture['shared_component'])) {
            $payload['shared_component'] = $fixture['shared_component'];
        }
        $status = (string) ($fixture['context_status'] ?? 'READY');
        $context = [
            'context_id' => hash('sha256', 'context:'.$fixture['id']),
            'context_version' => 'seo.technical_diagnosis_evidence_context.v1',
            'request_hash' => $request['request_hash'],
            'bundle_refs' => [['bundle_id' => 'bundle:fixture:'.$fixture['id'], 'bundle_version' => 1, 'bundle_hash' => hash('sha256', 'fixture-bundle:'.$fixture['id'])]],
            'payload' => $payload,
            'lineage_refs' => [],
            'redaction_summary' => ['redacted_field_count' => 0, 'redacted_fields' => []],
            'status' => $status,
            'diagnosis_allowed' => $status === 'READY',
            'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
    }
}
