<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyGscCoreRuntimeEvaluator;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailySecurityDriftEvaluator;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyUrlTruthEvaluator;

/** Read-only capability proof. Never submits a Mission or authorizes scheduling. */
final readonly class Platform12SourceCheck
{
    public const SOURCES = [
        Platform12DailyMissionSet::IDS[0] => ['gsc_scheduled_receipt', 'scheduled_runtime_probe', 'public_api_health'],
        Platform12DailyMissionSet::IDS[1] => ['url_truth_reconciliation', 'issue_cluster', 'd1_observation', 'scheduled_runtime_probe', 'sitemap_observation'],
        Platform12DailyMissionSet::IDS[2] => ['private_route_negative_set', 'evidence_expiry', 'registry_version_vector', 'stored_evidence_safety', 'council_tool_audit'],
    ];

    public function __construct(private Platform12ProductionEvidenceReader $reader, private SeoRegistryHasher $hasher) {}

    public function check(string $id): array
    {
        if (! isset(self::SOURCES[$id])) {
            throw new \InvalidArgumentException('DAILY_MISSION_UNKNOWN');
        }
        $started = hrtime(true);
        $vector = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
        $evidence = $this->reader->capture($id);
        $evaluation = match ($id) {
            Platform12DailyMissionSet::IDS[0] => app(Platform12DailyGscCoreRuntimeEvaluator::class)->evaluate($evidence['input']),
            Platform12DailyMissionSet::IDS[1] => app(Platform12DailyUrlTruthEvaluator::class)->evaluate($evidence['input']),
            Platform12DailyMissionSet::IDS[2] => app(Platform12DailySecurityDriftEvaluator::class)->evaluate($evidence['input']),
        };
        $gaps = $evidence['source_gaps'];
        foreach (array_diff(self::SOURCES[$id], array_column($evidence['sources'], 'id')) as $missing) {
            $gaps[] = $missing;
        }
        if ($evaluation['state'] === 'INPUT_HOLD') {
            $gaps[] = 'source_schema_invalid';
        }
        if ($id === Platform12DailyMissionSet::IDS[1]) {
            foreach (['authority', 'url_truth'] as $source) {
                if (data_get($evidence, 'input.'.$source.'.availability') !== 'AVAILABLE') {
                    $gaps[] = $source.'_unavailable';
                }
            }
        }
        if ($id === Platform12DailyMissionSet::IDS[2]) {
            if (in_array('UNAVAILABLE', $evidence['input']['drift'] ?? ['UNAVAILABLE'], true)) {
                $gaps[] = 'registry_version_vector_unavailable';
            }
            foreach (['query_security.hmac_state', 'query_security.key_version_state', 'posture.retention_state', 'injection.prompt_state'] as $field) {
                if (in_array(data_get($evidence, 'input.'.$field), [null, 'UNAVAILABLE', 'UNKNOWN'], true)) {
                    $gaps[] = str_replace('.', '_', $field).'_unavailable';
                }
            }
        }
        if ($vector !== app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector']) {
            $gaps[] = 'source_version_drift';
        }
        $path = (string) config('seo_council.release_revision_path');
        $sha = is_file($path) ? trim((string) file_get_contents($path)) : '';
        $real = app()->environment(['production', 'staging']) && preg_match('/^[a-f0-9]{40}$/D', $sha) === 1;
        $report = [
            'schema_version' => 'seo.a08_source_check.v1', 'repository' => 'fermatmind/fap-api',
            'environment' => app()->environment(), 'sha' => $sha, 'mission_id' => $id,
            'source_wiring_status' => $gaps === [] ? 'VERIFIED' : 'HOLD',
            'observed_verdict' => $evaluation['state'], 'real_runtime' => $real,
            'captured_at' => $evidence['captured_at'], 'expires_at' => $evidence['expires_at'],
            'source_checks' => $this->classify($id, $evidence, $evaluation, $gaps),
            'sources' => $evidence['sources'], 'source_gaps' => array_values(array_unique($gaps)),
            'input_digest' => $this->hasher->hash($evidence), 'version_vector' => $vector,
            'observation' => $evaluation, 'elapsed_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'mission_submitted' => false, 'notification_sent' => false, 'business_write_enabled' => false,
        ];
        $report['receipt_digest'] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $report;
    }

    private function classify(string $mission, array $evidence, array $evaluation, array $gaps): array
    {
        $input = $evidence['input'];
        $result = [];
        foreach (self::SOURCES[$mission] as $id) {
            $source = collect($evidence['sources'])->firstWhere('id', $id);
            $count = match ($id) {
                'gsc_scheduled_receipt' => data_get($evaluation, 'gsc.row_count'),
                'issue_cluster' => data_get($input, 'clustering.issue_count'),
                'd1_observation' => data_get($input, 'd1_observation.candidate_count'),
                'evidence_expiry' => data_get($input, 'evidence_freshness.total_count'),
                'council_tool_audit' => data_get($input, 'tools.requested_count'),
                default => null,
            };
            $abnormal = match ($id) {
                'gsc_scheduled_receipt' => ! in_array(data_get($evaluation, 'gsc.capability_state'), ['AVAILABLE', 'VALID_ZERO'], true)
                    || data_get($evaluation, 'gsc.data_quality_state') !== 'READY',
                'scheduled_runtime_probe' => isset($input['runtime'])
                    ? data_get($input, 'runtime.core_runtime_state') !== 'AVAILABLE'
                    : (isset($input['runtime_observation']) && data_get($input, 'runtime_observation.availability') !== 'AVAILABLE'),
                'public_api_health' => data_get($input, 'runtime.public_api_state') !== 'AVAILABLE',
                'url_truth_reconciliation' => data_get($input, 'authority.current_public_count') !== data_get($input, 'url_truth.current_url_truth_count')
                    || data_get($input, 'url_truth.wrong_canonical_count', 0) > 0 || data_get($input, 'url_truth.false_noindex_count', 0) > 0,
                'issue_cluster' => data_get($input, 'clustering.issue_count') !== data_get($input, 'clustering.clustered_issue_count'),
                'd1_observation' => data_get($input, 'd1_observation.candidate_count') !== data_get($input, 'd1_observation.observed_count'),
                'private_route_negative_set' => data_get($input, 'private_routes.tested_count') !== data_get($input, 'private_routes.rejected_count'),
                'evidence_expiry' => data_get($input, 'evidence_freshness.expired_count', 0) > 0,
                'registry_version_vector' => array_diff($input['drift'] ?? [], ['MATCH']) !== [],
                'stored_evidence_safety' => data_get($input, 'query_security.hmac_state') !== 'VALID'
                    || data_get($input, 'query_security.key_version_state') !== 'CURRENT'
                    || data_get($input, 'query_security.pii_state') !== 'ABSENT'
                    || data_get($input, 'posture.retention_state') !== 'COMPLIANT',
                'council_tool_audit' => $count > 0,
                default => false,
            };
            $missing = $source === null || in_array($id, $gaps, true)
                || ($id === 'stored_evidence_safety' && count(array_filter($gaps, static fn ($gap) => str_starts_with($gap, 'query_security_'))) > 0);
            $result[] = ['id' => $id, 'classification' => $missing ? 'MISSING_PERMISSION_OR_MAPPING'
                : ($abnormal ? 'CONNECTED_ABNORMAL_OR_DELAYED' : ($count === 0 ? 'VALID_ZERO_OR_NOT_APPLICABLE' : 'CONNECTED_NORMAL')),
                'read_at' => $source['read_at'] ?? null, 'observed_at' => $source['observed_at'] ?? null];
        }

        return $result;
    }
}
