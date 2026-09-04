<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoWeeklyDecisionCloseoutService
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_closeout.v2';

    private const MAX_EVIDENCE_AGE_SECONDS = 8 * 24 * 60 * 60;

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
            $schema = Schema::connection($this->connection);
            if (! $schema->hasTable('seo_weekly_decision_capability_receipts')
                || ! $schema->hasTable('seo_weekly_decision_receipts')) {
                return $this->unproven('receipt_store_unavailable');
            }
            $selection = $this->selector->snapshot($now);
            if ($selection['state'] === 'unavailable') {
                return $this->unproven('decision_snapshot_unavailable');
            }
            $capabilityRevision = SeoWeeklyDecisionReceiptService::capabilityRevision();
            $row = DB::connection($this->connection)->table('seo_weekly_decision_capability_receipts')
                ->where('capability_revision', $capabilityRevision)
                ->orderByDesc('scheduled_for')
                ->first();
            if ($row === null) {
                return $this->unproven('natural_capability_receipt_pending');
            }
            $capability = SeoWeeklyDecisionReceiptValidator::decodeAndVerify($row, 'capability');
            $receipt = $capability['payload'];
            if (! is_array($receipt)) {
                return $this->unproven('receipt_or_snapshot_mismatch');
            }
            $selectionRow = DB::connection($this->connection)->table('seo_weekly_decision_receipts')
                ->where('selection_revision', (string) ($receipt['selection_revision'] ?? ''))
                ->first();
            $validation = SeoWeeklyDecisionReceiptValidator::validatePair(
                $row,
                $selectionRow,
                $capabilityRevision,
            );
            if (! $validation['valid'] || $validation['scheduled_for'] === null) {
                return $this->unproven('receipt_or_snapshot_mismatch');
            }
            $scheduledFor = $validation['scheduled_for'];
            $evaluatedAt = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
            $evidenceAgeSeconds = $evaluatedAt->getTimestamp() - $scheduledFor->getTimestamp();
            if ($evidenceAgeSeconds < 0 || $evidenceAgeSeconds > self::MAX_EVIDENCE_AGE_SECONDS) {
                return $this->unproven('receipt_or_snapshot_mismatch');
            }

            return [
                'schema_version' => self::CONTRACT_VERSION,
                'state' => 'production_proven',
                'release_sha' => $expectedSha,
                'evidence_release_sha' => (string) $receipt['release_sha'],
                'capability_version' => SeoWeeklyDecisionReceiptService::CAPABILITY_VERSION,
                'capability_revision' => $capabilityRevision,
                'iso_week' => (string) $receipt['iso_week'],
                'selection_revision' => (string) $receipt['selection_revision'],
                'decision_count' => (int) $receipt['decision_count'],
                'receipt_hash' => (string) $row->receipt_hash,
                'receipt_hash_algorithm' => SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM,
                'evidence_age_seconds' => $evidenceAgeSeconds,
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
