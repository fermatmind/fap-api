<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\Runtime\ProductionCalibrationCloseoutService;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoTechnicalHealthReadService extends AbstractSeoDashboardReadService
{
    /** @return array<string,mixed> */
    public function read(): array
    {
        $window = $this->window();
        $clusters = $this->clusters();
        $latestReceipt = data_get($window, 'receipts.0');
        $crawlerHits = is_array($latestReceipt) && is_numeric(data_get($latestReceipt, 'crawler_source_receipt.hit_count'))
            ? (int) data_get($latestReceipt, 'crawler_source_receipt.hit_count')
            : null;
        $slotCount = is_numeric($window['slot_count'] ?? null) ? (int) $window['slot_count'] : null;
        $closeout = (new ProductionCalibrationCloseoutService)->evaluate($window);

        return [
            'schema_version' => 'seo-platform-07-technical-health.v1',
            'state' => $closeout['state'],
            'status_labels' => [
                'zh-CN' => ['production_unproven' => '生产尚未证明', 'production_proven' => '生产已证明', 'open' => '未恢复', 'recovered' => '已恢复'],
                'en' => ['production_unproven' => 'Production unproven', 'production_proven' => 'Production proven', 'open' => 'Open', 'recovered' => 'Recovered'],
            ],
            'metrics' => [
                'scheduled_slot_count' => $slotCount,
                'crawler_hit_count' => $crawlerHits,
                'open_cluster_count' => count(array_filter($clusters, static fn (array $cluster): bool => $cluster['status'] === 'open')),
                'incident_rate' => null,
            ],
            'scheduler_window' => $window,
            'production_closeout' => $closeout,
            'contract_projection_hash' => $closeout['contract_projection_hash'],
            'clusters' => $clusters,
            'trend' => array_map(static fn (array $receipt): array => [
                'scheduled_for' => $receipt['scheduled_for'] ?? null,
                'status' => $receipt['status'] ?? null,
                'crawler_hit_count' => data_get($receipt, 'crawler_source_receipt.hit_count'),
                'calibration_state' => data_get($receipt, 'production_calibration.state'),
            ], (array) ($window['receipts'] ?? [])),
            'evidence_summary' => [
                'scheduled_receipt_count' => $slotCount,
                'crawler_source_receipt_available' => is_array($latestReceipt),
                'cluster_evidence_count' => array_sum(array_column($clusters, 'evidence_count')),
                'production_closeout_direct_evidence_complete' => $closeout['direct_evidence_complete'],
                'raw_url_exposed' => false,
                'query_exposed' => false,
                'user_agent_exposed' => false,
                'response_body_exposed' => false,
                'raw_topology_exposed' => false,
            ],
            'boundaries' => [
                'read_only' => true,
                'production_proof_may_be_inferred' => false,
                'write_authorization_granted' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function window(): array
    {
        try {
            return (new ScheduledRuntimeProbeReceiptService($this->connectionName))->readWindow();
        } catch (Throwable) {
            return ['state' => 'MEASUREMENT_HOLD', 'slot_count' => null, 'receipts' => []];
        }
    }

    /** @return list<array<string,mixed>> */
    private function clusters(): array
    {
        $schema = Schema::connection($this->connection()->getName());
        if (! $schema->hasTable('seo_issue_queue')
            || ! $schema->hasColumn('seo_issue_queue', 'cluster_uid')
            || ! $schema->hasColumn('seo_issue_queue', 'detector_id')) {
            return [];
        }

        return $this->table('seo_issue_queue')
            ->whereNotNull('cluster_uid')
            ->selectRaw('cluster_uid, detector_id, severity, page_entity_type, locale, status, MIN(detected_at) AS first_observed_at, MAX(last_evidence_at) AS last_observed_at, SUM(affected_url_count) AS affected_count, COUNT(*) AS evidence_count')
            ->groupBy(['cluster_uid', 'detector_id', 'severity', 'page_entity_type', 'locale', 'status'])
            ->orderByDesc('last_observed_at')
            ->limit(50)
            ->get()
            ->map(static fn (object $row): array => [
                'cluster_uid' => (string) $row->cluster_uid,
                'detector' => (string) $row->detector_id,
                'severity' => (string) $row->severity,
                'page_family' => (string) $row->page_entity_type,
                'locale' => (string) $row->locale,
                'status' => (string) $row->status,
                'affected_count' => (int) $row->affected_count,
                'evidence_count' => (int) $row->evidence_count,
                'first_observed_at' => $row->first_observed_at === null ? null : (string) $row->first_observed_at,
                'last_observed_at' => $row->last_observed_at === null ? null : (string) $row->last_observed_at,
            ])
            ->all();
    }
}
