<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SeoSchedulerReceiptReadService
{
    /** @return array<string,mixed> */
    public function read(): array
    {
        $gsc = $this->latestGscScheduledReceipt();
        $funnel = $this->latestFunnelScheduledReceipt();
        $healthy = ($gsc['status'] ?? null) === 'success'
            && ($funnel['status'] ?? null) === 'success'
            && ($gsc['receipt_complete'] ?? false) === true
            && ($funnel['receipt_complete'] ?? false) === true
            && ($gsc['age_hours'] ?? 999) <= 48
            && ($funnel['age_hours'] ?? 999) <= 48;

        return [
            'state' => $healthy ? 'production_healthy_observing' : 'measurement_hold',
            'source' => 'seo_intel.scheduler_receipts',
            'observed_at' => now('UTC')->toAtomString(),
            'unavailable_reason' => $healthy ? null : 'scheduled_receipt_missing_failed_or_stale',
            'gsc' => $gsc,
            'public_funnel' => $funnel,
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'slo_handoff' => 'SEO-PLATFORM-12',
        ];
    }

    /** @return array<string,mixed> */
    private function latestGscScheduledReceipt(): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        $schema = Schema::connection($connection);
        if (! \App\Support\SchemaBaseline::tableExists('seo_gsc_sync_runs', $schema->getConnection()->getName()) || ! \App\Support\SchemaBaseline::columnExists('seo_gsc_sync_runs', 'receipt_json', $schema->getConnection()->getName())) {
            return ['status' => 'missing'];
        }

        $row = DB::connection($connection)->table('seo_gsc_sync_runs')
            ->where('trigger_mode', 'scheduled')
            ->orderByDesc('started_at')
            ->first(['status', 'started_at', 'finished_at', 'receipt_json']);
        if ($row === null) {
            return ['status' => 'missing'];
        }

        $receipt = json_decode((string) ($row->receipt_json ?? ''), true);
        $completedAt = $row->finished_at ?? $row->started_at;

        return [
            'status' => (string) $row->status,
            'trigger_mode' => 'scheduled',
            'completed_at' => $completedAt,
            'age_hours' => now('UTC')->diffInHours($completedAt, true),
            'receipt_complete' => is_array($receipt) && $this->gscReceiptComplete($receipt),
            'receipt' => is_array($receipt) ? $receipt : null,
        ];
    }

    /** @return array<string,mixed> */
    private function latestFunnelScheduledReceipt(): array
    {
        if (! SchemaBaseline::hasTable('analytics_seo_conversion_refresh_runs')) {
            return ['status' => 'missing'];
        }

        $row = DB::table('analytics_seo_conversion_refresh_runs')
            ->where('trigger_mode', 'scheduled')
            ->where('org_scope_count', 0)
            ->orderByDesc('completed_at')
            ->first(['status', 'completed_at', 'receipt_json']);
        if ($row === null) {
            return ['status' => 'missing'];
        }

        $receipt = json_decode((string) $row->receipt_json, true);

        return [
            'status' => (string) $row->status,
            'trigger_mode' => 'scheduled',
            'completed_at' => $row->completed_at,
            'age_hours' => now('UTC')->diffInHours($row->completed_at, true),
            'receipt_complete' => is_array($receipt) && $this->funnelReceiptComplete($receipt),
            'receipt' => is_array($receipt) ? $receipt : null,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function gscReceiptComplete(array $receipt): bool
    {
        $required = [
            'application_sha', 'workflow_sha', 'active_production_sha', 'property_hash',
            'window_days', 'search_types', 'reporting_timezone', 'pages_fetched', 'rows_seen',
            'rows_upserted', 'duplicate_natural_keys', 'mapped_rows', 'unmapped_rows',
            'data_max_date', 'data_lag_days', 'quality_gate', 'restricted_egress',
        ];

        return collect($required)->every(static fn (string $key): bool => array_key_exists($key, $receipt))
            && ($receipt['trigger_mode'] ?? null) === 'scheduled'
            && ($receipt['read_only_gsc'] ?? false) === true
            && ($receipt['search_submission_allowed'] ?? true) === false;
    }

    /** @param array<string,mixed> $receipt */
    private function funnelReceiptComplete(array $receipt): bool
    {
        $required = [
            'application_sha', 'workflow_sha', 'active_production_sha', 'from', 'to',
            'reporting_timezone', 'storage_timezone', 'org_scope_mode', 'org_scope_count',
            'attempted_rows', 'skipped_rows', 'deleted_rows', 'upserted_rows', 'readback_receipt',
        ];

        return collect($required)->every(static fn (string $key): bool => array_key_exists($key, $receipt))
            && ($receipt['trigger_mode'] ?? null) === 'scheduled'
            && data_get($receipt, 'readback_receipt.status') === 'pass'
            && ($receipt['raw_query_exposed'] ?? true) === false
            && ($receipt['raw_session_or_business_identifiers_exposed'] ?? true) === false
            && ($receipt['private_paths_allowed'] ?? true) === false
            && ($receipt['search_submission_allowed'] ?? true) === false;
    }
}
