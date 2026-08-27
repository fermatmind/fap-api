<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoWeeklyDecisionCloseoutService
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_closeout.v1';

    public function __construct(
        private readonly SeoWeeklyDecisionSelector $selector,
        private readonly string $connection = 'seo_intel',
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(string $expectedSha, ?CarbonImmutable $now = null): array
    {
        $expectedSha = strtolower(trim($expectedSha));
        if (preg_match('/\A[a-f0-9]{40}\z/', $expectedSha) !== 1) {
            return $this->unproven('invalid_expected_sha');
        }

        try {
            if (! Schema::connection($this->connection)->hasTable('seo_weekly_decision_receipts')) {
                return $this->unproven('receipt_store_unavailable');
            }
            $selection = $this->selector->snapshot($now);
            if ($selection['state'] === 'unavailable') {
                return $this->unproven('decision_snapshot_unavailable');
            }
            $row = DB::connection($this->connection)->table('seo_weekly_decision_receipts')
                ->where('iso_week', $selection['iso_week'])
                ->orderByDesc('scheduled_for')
                ->first();
            if ($row === null) {
                return $this->unproven('natural_scheduled_receipt_pending');
            }
            $receipt = json_decode((string) $row->receipt_json, true);
            if (! is_array($receipt)
                || ! hash_equals((string) $row->receipt_hash, hash('sha256', (string) $row->receipt_json))
                || ($receipt['trigger'] ?? null) !== 'scheduled'
                || ($receipt['manual_receipts_excluded'] ?? null) !== true
                || ! hash_equals($expectedSha, (string) ($receipt['release_sha'] ?? ''))
                || ! hash_equals((string) $selection['selection_revision'], (string) ($receipt['selection_revision'] ?? ''))
                || (int) ($receipt['decision_count'] ?? -1) !== (int) $selection['count']
                || (int) $selection['count'] < 0
                || (int) $selection['count'] > SeoWeeklyDecisionSelector::MAX_COUNT) {
                return $this->unproven('receipt_or_snapshot_mismatch');
            }

            return [
                'schema_version' => self::CONTRACT_VERSION,
                'state' => 'production_proven',
                'release_sha' => $expectedSha,
                'iso_week' => $selection['iso_week'],
                'selection_revision' => $selection['selection_revision'],
                'decision_count' => $selection['count'],
                'receipt_hash' => (string) $row->receipt_hash,
                'natural_scheduler_proven' => true,
                'idempotent_selection_proven' => true,
                'manual_receipts_excluded' => true,
                'workbench_range_valid' => true,
                'read_only' => true,
                'l3_enabled' => false,
                'l4_enabled' => false,
                'search_submission_allowed' => false,
            ];
        } catch (Throwable) {
            return $this->unproven('closeout_read_failed');
        }
    }

    /** @return array<string, mixed> */
    private function unproven(string $reason): array
    {
        return [
            'schema_version' => self::CONTRACT_VERSION,
            'state' => 'production_unproven',
            'reason' => $reason,
            'decision_count' => null,
            'natural_scheduler_proven' => false,
            'manual_receipts_excluded' => true,
            'read_only' => true,
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
    }
}
