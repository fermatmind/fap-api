<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\Ops\PublicContentDeliveryProbeService;
use App\Services\SEO\SitemapCache;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Privacy\SeoQueryHmac;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalPrivateNegativeSetEvaluator;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use App\Services\SeoIntel\UrlTruth\UrlTruthReconciliationRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12ProductionEvidenceReader implements Platform12EvidenceReader
{
    public function __construct(
        private SeoRegistryHasher $hasher,
        private ScheduledRuntimeProbeReceiptService $runtime,
        private UrlTruthReconciliationRuntimeService $truth,
        private TechnicalPrivateNegativeSetEvaluator $privateRoutes,
    ) {}

    public function capture(string $missionId): array
    {
        $restore = [];
        try {
            $names = [(string) config('seo_intel.connection', 'seo_intel')];
            if ($missionId === Platform12DailyMissionSet::IDS[1]) {
                $names[] = (string) config('database.default');
            }
            foreach (array_unique($names) as $name) {
                $connection = DB::connection($name);
                if ($connection->getDriverName() === 'mysql') {
                    $version = $connection->selectOne('SELECT VERSION() AS version')->version;
                    $variable = str_contains((string) $version, 'MariaDB') ? 'max_statement_time' : 'max_execution_time';
                    $old = $connection->selectOne('SELECT @@SESSION.'.$variable.' AS budget')->budget;
                    $connection->statement('SET SESSION '.$variable.' = '.($variable === 'max_statement_time' ? '10' : '10000'));
                    $restore[] = [$connection, $variable, (float) $old];
                } elseif ($connection->getDriverName() !== 'sqlite') {
                    throw new \RuntimeException('QUERY_TIMEOUT_CONTRACT_UNAVAILABLE');
                }
            }

            return $this->captureReadModels($missionId);
        } catch (Throwable) {
            return ['input' => ['evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z')],
                'sources' => [], 'source_gaps' => ['bounded_read_unavailable'],
                'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z')];
        } finally {
            foreach ($restore as [$connection, $variable, $old]) {
                try {
                    $connection->statement('SET SESSION '.$variable.' = '.$old);
                } catch (Throwable) {
                    $connection->disconnect();
                }
            }
        }
    }

    private function captureReadModels(string $missionId): array
    {
        if (! in_array($missionId, Platform12DailyMissionSet::IDS, true)) {
            throw new \InvalidArgumentException('DAILY_MISSION_UNKNOWN');
        }
        $at = CarbonImmutable::now('UTC');
        $input = ['evaluated_at' => $at->format('Y-m-d\TH:i:s\Z')];
        $sources = [];
        $gaps = [];
        $read = function (string $name, callable $loader) use (&$sources, &$gaps, $at): ?array {
            try {
                $data = $loader();
                if (! is_array($data)) {
                    throw new \RuntimeException('SOURCE_UNAVAILABLE');
                }
                $sources[] = ['id' => $name, 'hash' => $this->hasher->hash($data),
                    'read_at' => $at->format('Y-m-d\TH:i:s\Z'),
                    'observed_at' => $data['observed_at'] ?? data_get($data, 'receipts.0.completed_at')];

                return $data;
            } catch (Throwable $error) {
                $gaps[] = $name.(str_contains($error->getMessage(), '_STALE') ? '_stale' : '');

                return null;
            }
        };
        if ($missionId === Platform12DailyMissionSet::IDS[0]) {
            $gsc = $read('gsc_scheduled_receipt', fn (): array => $this->gsc($at));
            $input['gsc'] = $gsc === null ? null : array_diff_key($gsc, ['observed_at' => true, 'source_hash' => true]);
            $probe = $read('scheduled_runtime_probe', fn (): array => $this->runtimeWindow($at));
            $api = $read('public_api_health', fn (): array => $this->publicApi($at));
            $input['runtime'] = [
                'core_runtime_state' => ($probe['state'] ?? null) === 'complete' ? 'AVAILABLE' : 'UNAVAILABLE',
                'public_api_state' => ($api['healthy'] ?? false) ? 'AVAILABLE' : 'UNAVAILABLE',
                'readback_state' => ($api['readback'] ?? false) ? 'AVAILABLE' : 'UNAVAILABLE',
                'production_sha' => $this->releaseSha(),
                'readback_sha' => data_get($probe, 'receipts.0.production_calibration.deploy_revision'),
            ];
        } elseif ($missionId === Platform12DailyMissionSet::IDS[1]) {
            $truth = $read('url_truth_reconciliation', fn (): array => $this->truth->read(false));
            $authorityAvailable = data_get($truth, 'source_state.authority') === 'available';
            $truthAvailable = data_get($truth, 'source_state.url_truth') === 'available'
                && data_get($truth, 'source_state.entity_bindings') === 'available';
            $input['authority'] = $authorityAvailable ? ['availability' => 'AVAILABLE',
                'revision_hash' => $this->hasher->hash($truth),
                'current_public_count' => data_get($truth, 'counts.effective_public')] : ['availability' => 'UNAVAILABLE'];
            $input['url_truth'] = $truthAvailable ? ['availability' => 'AVAILABLE',
                'revision_hash' => $this->hasher->hash($truth),
                'current_url_truth_count' => data_get($truth, 'counts.url_truth_valid'),
                'wrong_canonical_count' => data_get($truth, 'difference_classification.canonical_host_or_path_error'),
                'false_noindex_count' => data_get($truth, 'difference_classification.private_or_noindex_included')] : ['availability' => 'UNAVAILABLE'];
            $input['clustering'] = $read('issue_cluster', fn (): array => $this->clusters());
            $input['d1_observation'] = $read('d1_observation', fn (): array => $this->d1($at));
            $probe = $read('scheduled_runtime_probe', fn (): array => $this->runtimeWindow($at));
            $input['runtime_observation'] = ($probe['state'] ?? null) === 'complete'
                ? ['availability' => 'AVAILABLE', 'observation_count' => $probe['slot_count']]
                : ['availability' => 'UNAVAILABLE'];
            $input['sitemap_observation'] = $read('sitemap_observation', fn (): array => $this->sitemap());
        } else {
            $negative = $read('private_route_negative_set', function () use ($at): array {
                $window = $this->runtimeWindow($at);
                $negative = data_get($window, 'receipts.0.production_calibration.private_negative_set');
                if (($window['fresh'] ?? false) !== true || ! is_array($negative)
                    || ($negative['checked'] ?? false) !== true
                    || ! is_int($negative['http_probe_count'] ?? null) || $negative['http_probe_count'] < 1
                    || ! is_int($negative['accepted_http_probe_count'] ?? null)
                    || ($negative['unobserved_count'] ?? null) !== 0) {
                    throw new \RuntimeException('LIVE_NEGATIVE_SET_UNAVAILABLE');
                }
                $guards = $this->privateRoutes->evaluate();
                $violations = array_sum(array_diff_key($guards, ['probe_total' => true]));

                return ['tested_count' => $negative['http_probe_count'],
                    'rejected_count' => $violations === 0 ? $negative['accepted_http_probe_count'] : 0,
                    'observed_at' => data_get($window, 'receipts.0.completed_at')];
            });
            if ($negative !== null) {
                $input['private_routes'] = array_diff_key($negative, ['observed_at' => true]);
            }
            $input['evidence_freshness'] = $read('evidence_expiry', function () use ($at): array {
                $query = $this->connection()->table('seo_evidence_bundles');
                $total = (clone $query)->count();
                $expired = (clone $query)->where('expires_at', '<=', $at->format('Y-m-d H:i:s'))->count();

                return ['total_count' => $total, 'fresh_count' => $total - $expired, 'expired_count' => $expired];
            });
            $input['drift'] = $read('registry_version_vector', function (): array {
                $expected = app(Platform12RuntimeControl::class)->frozenVersionVector();
                $current = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
                $drift = [];
                foreach (['role', 'binding', 'policy', 'tool', 'schema', 'prompt'] as $dimension) {
                    $drift[$dimension] = ! isset($expected[$dimension]) ? 'UNAVAILABLE'
                        : (hash_equals($expected[$dimension], $current[$dimension]) ? 'MATCH' : 'DRIFT');
                }

                return $drift;
            });
            $safety = $read('stored_evidence_safety', fn (): array => $this->evidenceSafety($at));
            if ($safety !== null) {
                foreach (['query_security', 'injection', 'posture'] as $component) {
                    $input[$component] = $safety[$component];
                }
            }
            $input['tools'] = $read('council_tool_audit', fn (): array => $this->toolAudit($at));
        }

        return ['input' => $input, 'sources' => $sources, 'source_gaps' => array_values(array_unique($gaps)),
            'captured_at' => $at->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $at->addMinutes(10)->format('Y-m-d\TH:i:s\Z')];
    }

    private function gsc(CarbonImmutable $now): array
    {
        // Read the latest scheduled attempt, including a failure; never hide it
        // by falling back to an older successful run.
        $row = $this->connection()->table('seo_gsc_sync_runs')->where('trigger_mode', 'scheduled')
            ->orderByDesc('started_at')->first(['status', 'finished_at', 'receipt_json']);
        if ($row === null || ! is_string($row->receipt_json) || strlen($row->receipt_json) > 262144) {
            throw new \RuntimeException('GSC_RECEIPT_UNAVAILABLE');
        }
        $receipt = json_decode($row->receipt_json, true, 32, JSON_THROW_ON_ERROR);
        $finished = CarbonImmutable::parse($row->finished_at, 'UTC');
        if (($receipt['schema_version'] ?? null) !== 'seo.gsc_refresh_receipt.v2'
            || $finished->gt($now) || $finished->lt($now->subHours(26))) {
            throw new \RuntimeException('GSC_RECEIPT_STALE');
        }

        return ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => $row->status,
            'observed_at' => $finished->format('Y-m-d\TH:i:s\Z'), 'source_hash' => $this->hasher->hash($receipt),
            'trigger_mode' => $receipt['trigger_mode'] ?? null,
            'mapping_state' => ($receipt['unmapped_rows'] ?? null) === 0 ? 'READY' : 'FAILED',
            'data_quality_state' => $row->status === 'success' && data_get($receipt, 'quality_gate.status') === 'pass' ? 'READY' : 'HOLD',
            'window_state' => $row->status === 'success' ? 'COMPLETE' : 'INCOMPLETE',
            'row_count' => $receipt['rows_seen'] ?? null, 'data_max_date' => $receipt['data_max_date'] ?? null,
        ];
    }

    private function clusters(): array
    {
        $query = $this->connection()->table('seo_issue_queue')->whereNotIn('status', ['resolved', 'closed', 'ignored']);
        $total = (clone $query)->count();
        $clustered = (clone $query)->whereNotNull('cluster_uid')->where('cluster_uid', '<>', '')->count();

        return ['availability' => 'AVAILABLE', 'issue_count' => $total, 'clustered_issue_count' => $clustered,
            'dedupe_candidate_count' => $total,
            'dedupe_unique_count' => (clone $query)->distinct()->count('issue_uid')];
    }

    private function runtimeWindow(CarbonImmutable $at): array
    {
        $window = $this->runtime->readWindow($at->toAtomString());
        foreach ($window['receipts'] ?? [] as $receipt) {
            if (! is_string($receipt['receipt_hash'] ?? null)
                || ! hash_equals(ScheduledRuntimeProbeReceiptService::contentHash(array_diff_key($receipt, ['receipt_hash' => true])), $receipt['receipt_hash'])
                || CarbonImmutable::parse($receipt['completed_at'])->gt($at)) {
                throw new \RuntimeException('RUNTIME_RECEIPT_INVALID');
            }
        }

        return $window;
    }

    private function publicApi(CarbonImmutable $at): array
    {
        // Read the existing fixed anonymous probe cache; never issue an HTTP probe.
        $service = app(PublicContentDeliveryProbeService::class);
        $result = $service->latest();
        if (count($result['items']) !== count($service->catalog()) || $result['items'] === []) {
            throw new \RuntimeException('PUBLIC_API_OBSERVATION_MISSING');
        }
        $readback = true;
        $observations = [];
        foreach ($result['items'] as $item) {
            $observed = CarbonImmutable::parse($item['observed_at']);
            if ($observed->gt($at) || $observed->lt($at->subMinutes(30))) {
                throw new \RuntimeException('PUBLIC_API_OBSERVATION_STALE');
            }
            $readback = $readback && data_get($item, 'readback.ok') === true;
            $observations[] = ['observed_at' => $observed->toAtomString(), 'hash' => $this->hasher->hash($item)];
        }

        return ['healthy' => $result['ok'], 'readback' => $readback, 'observations' => $observations,
            'observed_at' => min(array_column($observations, 'observed_at'))];
    }

    private function d1(CarbonImmutable $at): array
    {
        // D1 is a read-only observation derived from the existing immutable card
        // revision's first/last observations, not an action or experiment verdict.
        $rows = $this->connection()->table('seo_current_decision_cards AS c')
            ->join('seo_decision_cards AS d', 'c.decision_revision_id', '=', 'd.decision_revision_id')
            ->where('d.first_observed_at', '<=', $at->subDay()->format('Y-m-d H:i:s'))
            ->where('d.first_observed_at', '>', $at->subDays(2)->format('Y-m-d H:i:s'))
            ->limit(1001)->get(['d.first_observed_at', 'd.last_observed_at']);
        if ($rows->count() > 1000) {
            throw new \RuntimeException('D1_QUERY_BUDGET_HOLD');
        }
        $observed = 0;
        foreach ($rows as $row) {
            $last = CarbonImmutable::parse($row->last_observed_at, 'UTC');
            if ($last->gt($at)) {
                throw new \RuntimeException('D1_FUTURE_OBSERVATION');
            }
            $observed += (int) $last->gte(CarbonImmutable::parse($row->first_observed_at, 'UTC')->addDay());
        }

        return ['availability' => 'AVAILABLE', 'candidate_count' => $rows->count(), 'observed_count' => $observed];
    }

    private function sitemap(): array
    {
        $identity = Cache::get(SitemapCache::IDENTITY_CACHE_KEY);
        $cached = is_string($identity) ? app(SitemapCache::class)->get($identity) : null;
        $xml = $cached['xml'] ?? null;
        if (! is_string($xml) || strlen($xml) > 5242880 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \RuntimeException('SITEMAP_OBSERVATION_UNAVAILABLE');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($document === false || $document->getName() !== 'urlset') {
                throw new \RuntimeException('SITEMAP_OBSERVATION_INVALID');
            }
            $count = count($document->xpath('//*[local-name()="url"]') ?: []);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // This is a cached observation count, never the public authority denominator.
        return ['availability' => 'AVAILABLE', 'observation_count' => $count];
    }

    private function evidenceSafety(CarbonImmutable $at): array
    {
        // Scan only the minimized Evidence authority, not raw GSC or user tables.
        // A bounded complete active set is required; overflow is an explicit HOLD.
        $rows = $this->connection()->table('seo_evidence_bundles')
            ->where('expires_at', '>', $at->format('Y-m-d H:i:s'))->limit(201)
            ->get(['bundle_hash', 'bundle_json']);
        if ($rows->count() > 200) {
            throw new \RuntimeException('EVIDENCE_SCAN_BUDGET_HOLD');
        }
        $pii = false;
        $injection = false;
        $retention = true;
        $valid = true;
        foreach ($rows as $row) {
            if (! is_string($row->bundle_json) || strlen($row->bundle_json) > 131072) {
                throw new \RuntimeException('EVIDENCE_PAYLOAD_BUDGET_HOLD');
            }
            $bundle = json_decode($row->bundle_json, true, 64, JSON_THROW_ON_ERROR);
            $verdict = app(SeoEvidenceBundleVerifier::class)->verify($bundle);
            $valid = $valid && $verdict['valid'] && ($bundle['bundle_hash'] ?? null) === $row->bundle_hash;
            $pii = $pii || $verdict['code'] === 'PRIVATE_DATA_PRESENT';
            $injection = $injection || $verdict['code'] === 'INJECTION_BLOCKED';
            $retention = $retention && $verdict['code'] !== 'POLICY_BINDING_INVALID';
        }
        $hmac = app(SeoQueryHmac::class)->identify('council capability self check');
        $keyVersion = app(Platform12RuntimeControl::class)->frozenQueryKeyVersion();

        return [
            'scanned_count' => $rows->count(),
            'query_security' => ['hmac_state' => $hmac['status'] === 'available' ? 'VALID' : 'UNAVAILABLE',
                'key_version_state' => $keyVersion === null ? 'UNAVAILABLE'
                    : ($keyVersion === $hmac['query_hmac_key_version'] ? 'CURRENT' : 'DRIFT'),
                'pii_state' => $pii ? 'PRESENT' : ($valid ? 'ABSENT' : 'UNKNOWN')],
            'injection' => ['prompt_state' => $injection ? 'DETECTED' : ($valid ? 'PASS' : 'UNAVAILABLE'),
                'tool_metadata_state' => app(Platform12RuntimeControl::class)->businessGuardsClosed() ? 'PASS' : 'UNAVAILABLE'],
            'posture' => ['retention_state' => ! $retention ? 'VIOLATION' : ($valid ? 'COMPLIANT' : 'UNAVAILABLE'),
                'egress_state' => ! config('seo_agent_evidence.agent_external_egress', false)
                    && ! config('seo_council.tool_broker_enabled', false) ? 'COMPLIANT' : 'VIOLATION'],
        ];
    }

    private function toolAudit(CarbonImmutable $at): array
    {
        $rows = $this->connection()->table('seo_council_run_receipts')
            ->where('created_at', '>=', $at->subDay()->format('Y-m-d H:i:s'))
            ->limit(501)->get(['receipt_hash', 'receipt_json']);
        if ($rows->count() > 500) {
            throw new \RuntimeException('TOOL_AUDIT_BUDGET_HOLD');
        }
        $requested = 0;
        foreach ($rows as $row) {
            if (! is_string($row->receipt_json) || strlen($row->receipt_json) > 262144) {
                throw new \RuntimeException('TOOL_AUDIT_INVALID');
            }
            $receipt = json_decode($row->receipt_json, true, 64, JSON_THROW_ON_ERROR);
            $count = data_get($receipt, 'negative_guarantees.tool_calls');
            if (! is_int($count) || $count < 0
                || ! hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), $row->receipt_hash)) {
                throw new \RuntimeException('TOOL_AUDIT_INVALID');
            }
            $requested += $count;
        }

        // No tools are authorized in this runtime. A recorded call is a violation.
        return ['requested_count' => $requested, 'authorized_count' => 0];
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'));
    }

    private function releaseSha(): ?string
    {
        $file = dirname(base_path()).'/REVISION';
        $sha = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[a-f0-9]{40}$/D', $sha) === 1 ? $sha : null;
    }
}
