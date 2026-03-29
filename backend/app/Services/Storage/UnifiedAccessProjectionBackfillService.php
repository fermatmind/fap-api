<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class UnifiedAccessProjectionBackfillService
{
    private const SCHEMA = 'unified_access_projection_backfill.v1';

    public function __construct(
        private readonly AttemptReceiptBackfillService $receipts,
        private readonly ReportPdfDocumentService $pdfService,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function buildPlan(array $filters = []): array
    {
        $attemptIds = $this->collectAttemptIds($filters);

        return [
            'schema' => self::SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'filters' => $this->normalizedFilters($filters),
            'attempt_count' => count($attemptIds),
            'access_ready_count' => $this->countAccessReady($attemptIds),
            'report_ready_count' => $this->countReportReady($attemptIds),
            'pdf_ready_count' => $this->countPdfReady($attemptIds),
            'grant_count' => $this->countRows('benefit_grants', $attemptIds),
            'order_count' => $this->countRows('orders', $attemptIds, 'target_attempt_id'),
            'payment_event_count' => $this->countPaymentEvents($attemptIds),
            'share_count' => $this->countRows('shares', $attemptIds),
            'report_snapshot_count' => $this->countRows('report_snapshots', $attemptIds),
            'slot_count' => $this->countSlots($attemptIds),
            'projection_count' => $this->countRows('unified_access_projections', $attemptIds),
            'attempt_receipts_backfillable_count' => count($attemptIds),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function executeBackfill(array $filters = []): array
    {
        $attemptIds = $this->collectAttemptIds($filters);
        $inserted = 0;
        $reused = 0;

        foreach ($attemptIds as $attemptId) {
            $result = $this->backfillAttempt($attemptId);
            $inserted += (int) ($result['inserted'] ?? 0);
            $reused += (int) ($result['reused'] ?? 0);
        }

        return [
            'schema' => self::SCHEMA,
            'mode' => 'execute',
            'status' => 'executed',
            'generated_at' => now()->toIso8601String(),
            'filters' => $this->normalizedFilters($filters),
            'attempt_count' => count($attemptIds),
            'access_ready_count' => $this->countAccessReady($attemptIds),
            'report_ready_count' => $this->countReportReady($attemptIds),
            'pdf_ready_count' => $this->countPdfReady($attemptIds),
            'grant_count' => $this->countRows('benefit_grants', $attemptIds),
            'order_count' => $this->countRows('orders', $attemptIds, 'target_attempt_id'),
            'payment_event_count' => $this->countPaymentEvents($attemptIds),
            'share_count' => $this->countRows('shares', $attemptIds),
            'report_snapshot_count' => $this->countRows('report_snapshots', $attemptIds),
            'slot_count' => $this->countSlots($attemptIds),
            'projection_count' => $this->countRows('unified_access_projections', $attemptIds),
            'attempt_receipts_inserted' => $inserted,
            'attempt_receipts_reused' => $reused,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return list<string>
     */
    private function collectAttemptIds(array $filters): array
    {
        $normalized = $this->normalizedFilters($filters);
        $attemptId = $normalized['attempt_id'];
        $limit = $normalized['limit'];

        $attemptIds = [];
        foreach ([
            $this->attemptIdsFromReportSnapshots($attemptId),
            $this->attemptIdsFromBenefitGrants($attemptId),
            $this->attemptIdsFromOrders($attemptId),
            $this->attemptIdsFromPaymentEvents($attemptId),
            $this->attemptIdsFromShares($attemptId),
        ] as $chunk) {
            foreach ($chunk as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate === '') {
                    continue;
                }

                $attemptIds[$candidate] = true;
            }
        }

        $attemptIds = array_keys($attemptIds);
        sort($attemptIds, SORT_STRING);

        if ($limit !== null) {
            $attemptIds = array_slice($attemptIds, 0, $limit);
        }

        return $attemptIds;
    }

    /**
     * @return list<string>
     */
    private function attemptIdsFromReportSnapshots(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('report_snapshots')) {
            return [];
        }

        $query = DB::table('report_snapshots')->select('attempt_id')->orderBy('attempt_id');
        if ($attemptIdFilter !== null) {
            $query->where('attempt_id', $attemptIdFilter);
        }

        return $query->pluck('attempt_id')->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    /**
     * @return list<string>
     */
    private function attemptIdsFromBenefitGrants(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('benefit_grants')) {
            return [];
        }

        $query = DB::table('benefit_grants')->select('attempt_id')->orderBy('attempt_id');
        if ($attemptIdFilter !== null) {
            $query->where('attempt_id', $attemptIdFilter);
        }

        return $query->pluck('attempt_id')->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    /**
     * @return list<string>
     */
    private function attemptIdsFromOrders(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $query = DB::table('orders')->select('target_attempt_id')->orderBy('target_attempt_id');
        if ($attemptIdFilter !== null) {
            $query->where('target_attempt_id', $attemptIdFilter);
        }

        return $query->pluck('target_attempt_id')->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    /**
     * @return list<string>
     */
    private function attemptIdsFromPaymentEvents(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('payment_events') || ! Schema::hasTable('orders')) {
            return [];
        }

        $query = DB::table('payment_events')
            ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
            ->select('orders.target_attempt_id as attempt_id')
            ->orderBy('orders.target_attempt_id');

        if ($attemptIdFilter !== null) {
            $query->where('orders.target_attempt_id', $attemptIdFilter);
        }

        return $query->pluck('attempt_id')->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    /**
     * @return list<string>
     */
    private function attemptIdsFromShares(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('shares')) {
            return [];
        }

        $query = DB::table('shares')->select('attempt_id')->orderBy('attempt_id');
        if ($attemptIdFilter !== null) {
            $query->where('attempt_id', $attemptIdFilter);
        }

        return $query->pluck('attempt_id')->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function backfillAttempt(string $attemptId): array
    {
        if (! SchemaBaseline::hasTable('unified_access_projections')) {
            return ['inserted' => 0, 'reused' => 0];
        }

        $hasGrant = $this->hasActiveGrant($attemptId);
        $hasSnapshot = $this->hasReportSnapshot($attemptId);
        $hasReportSlot = $this->hasReportSlot($attemptId, ['report_json_free', 'report_json_full']);
        $hasPdfSlot = $this->hasReportSlot($attemptId, ['report_pdf_free', 'report_pdf_full']);
        $hasPdfArtifact = $hasPdfSlot || $this->hasPdfArtifactEvidence($attemptId);
        $hasOrder = $this->hasOrderEvidence($attemptId);
        $hasPayment = $this->hasPaymentEvidence($attemptId);
        $hasShare = $this->hasShareEvidence($attemptId);

        $accessState = $hasGrant ? 'ready' : (($hasOrder || $hasPayment || $hasShare) ? 'recovery_available' : 'locked');
        $reportState = ($hasSnapshot || $hasReportSlot) ? 'ready' : 'missing';
        $pdfState = $hasPdfArtifact ? 'ready' : 'missing';
        $reasonCode = $hasGrant
            ? 'benefit_grant_active'
            : ($hasPdfArtifact
                ? 'pdf_artifact_available'
                : ($hasSnapshot
                    ? 'report_snapshot_available'
                    : (($hasOrder || $hasPayment || $hasShare) ? 'payment_or_share_evidence' : 'no_access_evidence')));

        $actionsJson = [
            'report' => $reportState === 'ready',
            'pdf' => $pdfState === 'ready',
            'share' => $hasShare,
            'payment' => $hasPayment,
            'unlock' => $hasGrant,
        ];

        $payloadJson = [
            'attempt_id' => $attemptId,
            'has_active_grant' => $hasGrant,
            'has_report_snapshot' => $hasSnapshot,
            'has_report_slot' => $hasReportSlot,
            'has_pdf_slot' => $hasPdfSlot,
            'has_pdf_artifact' => $hasPdfArtifact,
            'has_order' => $hasOrder,
            'has_payment' => $hasPayment,
            'has_share' => $hasShare,
        ];

        $existing = UnifiedAccessProjection::query()->where('attempt_id', $attemptId)->first();
        $now = now();

        if ($existing instanceof UnifiedAccessProjection
            && (string) ($existing->access_state ?? '') === $accessState
            && (string) ($existing->report_state ?? '') === $reportState
            && (string) ($existing->pdf_state ?? '') === $pdfState
            && (string) ($existing->reason_code ?? '') === $reasonCode
            && (array) ($existing->actions_json ?? []) === $actionsJson
            && (array) ($existing->payload_json ?? []) === $payloadJson
        ) {
            $this->receipts->recordHistoricalReceipt(
                $attemptId,
                'access_projection_refreshed',
                [
                    'access_state' => $existing->access_state,
                    'report_state' => $existing->report_state,
                    'pdf_state' => $existing->pdf_state,
                    'reason_code' => $existing->reason_code,
                    'projection_version' => (int) $existing->projection_version,
                    'actions_json' => $existing->actions_json,
                    'payload_json' => $existing->payload_json,
                    'backfill' => true,
                ],
                [
                    'source_system' => 'unified_access_projection_backfill',
                    'source_ref' => 'projection#'.$attemptId,
                    'actor_type' => 'system',
                    'actor_id' => 'unified_access_projection_backfill',
                    'idempotency_key' => hash('sha256', implode('|', [
                        $attemptId,
                        $existing->access_state,
                        $existing->report_state,
                        $existing->pdf_state,
                        $existing->reason_code ?? '',
                    ])),
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                ]
            );

            return ['inserted' => 0, 'reused' => 1];
        }

        UnifiedAccessProjection::query()->updateOrCreate(
            ['attempt_id' => $attemptId],
            [
                'access_state' => $accessState,
                'report_state' => $reportState,
                'pdf_state' => $pdfState,
                'reason_code' => $reasonCode,
                'projection_version' => 1,
                'actions_json' => $actionsJson,
                'payload_json' => $payloadJson,
                'produced_at' => $existing?->produced_at ?? $now,
                'refreshed_at' => $now,
            ]
        );

        $this->receipts->recordHistoricalReceipt(
            $attemptId,
            'access_projection_refreshed',
            [
                'access_state' => $accessState,
                'report_state' => $reportState,
                'pdf_state' => $pdfState,
                'reason_code' => $reasonCode,
                'projection_version' => 1,
                'actions_json' => $actionsJson,
                'payload_json' => $payloadJson,
                'backfill' => true,
            ],
            [
                'source_system' => 'unified_access_projection_backfill',
                'source_ref' => 'projection#'.$attemptId,
                'actor_type' => 'system',
                'actor_id' => 'unified_access_projection_backfill',
                'idempotency_key' => hash('sha256', implode('|', [
                    $attemptId,
                    $accessState,
                    $reportState,
                    $pdfState,
                    $reasonCode,
                ])),
                'occurred_at' => $now,
                'recorded_at' => $now,
            ]
        );

        return ['inserted' => $existing instanceof UnifiedAccessProjection ? 0 : 1, 'reused' => 0];
    }

    private function hasActiveGrant(string $attemptId): bool
    {
        return Schema::hasTable('benefit_grants')
            && DB::table('benefit_grants')
                ->where('attempt_id', $attemptId)
                ->where('status', 'active')
                ->exists();
    }

    private function hasReportSnapshot(string $attemptId): bool
    {
        return Schema::hasTable('report_snapshots')
            && DB::table('report_snapshots')
                ->where('attempt_id', $attemptId)
                ->exists();
    }

    /**
     * @param  list<string>  $slotCodes
     */
    private function hasReportSlot(string $attemptId, array $slotCodes): bool
    {
        if (! Schema::hasTable('report_artifact_slots')) {
            return false;
        }

        return DB::table('report_artifact_slots')
            ->where('attempt_id', $attemptId)
            ->whereIn('slot_code', $slotCodes)
            ->exists();
    }

    private function hasOrderEvidence(string $attemptId): bool
    {
        return Schema::hasTable('orders')
            && DB::table('orders')
                ->where('target_attempt_id', $attemptId)
                ->exists();
    }

    private function hasPdfArtifactEvidence(string $attemptId): bool
    {
        if ($this->hasReportSlot($attemptId, ['report_pdf_free', 'report_pdf_full'])) {
            return true;
        }

        if (! Schema::hasTable('attempts') || ! Schema::hasTable('results')) {
            return false;
        }

        $attempt = Attempt::query()->where('id', $attemptId)->first();
        if (! $attempt instanceof Attempt) {
            return false;
        }

        $result = Result::query()->where('attempt_id', $attemptId)->first();
        foreach (['free', 'full'] as $variant) {
            try {
                $path = $this->pdfService->resolveArtifactPath($attempt, $variant, $result);
            } catch (\Throwable) {
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                return true;
            }
        }

        return false;
    }

    private function hasPaymentEvidence(string $attemptId): bool
    {
        if (! Schema::hasTable('payment_events') || ! Schema::hasTable('orders')) {
            return false;
        }

        return DB::table('payment_events')
            ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
            ->where('orders.target_attempt_id', $attemptId)
            ->exists();
    }

    private function hasShareEvidence(string $attemptId): bool
    {
        return Schema::hasTable('shares')
            && DB::table('shares')
                ->where('attempt_id', $attemptId)
                ->exists();
    }

    /**
     * @return list<string>
     */
    private function normalizedFilters(array $filters): array
    {
        return [
            'attempt_id' => $this->normalizeNullableText($filters['attempt_id'] ?? null),
            'limit' => isset($filters['limit']) ? max(1, (int) $filters['limit']) : null,
        ];
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countAccessReady(array $attemptIds): int
    {
        if (! Schema::hasTable('benefit_grants')) {
            return 0;
        }

        return DB::table('benefit_grants')
            ->where('status', 'active')
            ->whereIn('attempt_id', $attemptIds)
            ->distinct('attempt_id')
            ->count('attempt_id');
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countReportReady(array $attemptIds): int
    {
        if (! Schema::hasTable('report_snapshots')) {
            return 0;
        }

        return DB::table('report_snapshots')
            ->whereIn('attempt_id', $attemptIds)
            ->count();
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countPdfReady(array $attemptIds): int
    {
        if ($attemptIds === []) {
            return 0;
        }

        $count = 0;
        foreach ($attemptIds as $attemptId) {
            if ($this->hasPdfArtifactEvidence($attemptId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countSlots(array $attemptIds): int
    {
        if (! Schema::hasTable('report_artifact_slots')) {
            return 0;
        }

        return DB::table('report_artifact_slots')
            ->whereIn('attempt_id', $attemptIds)
            ->count();
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countRows(string $table, array $attemptIds, string $column = 'attempt_id'): int
    {
        if (! Schema::hasTable($table) || $attemptIds === []) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $attemptIds)->count();
    }

    /**
     * @param  list<string>  $attemptIds
     */
    private function countPaymentEvents(array $attemptIds): int
    {
        if (! Schema::hasTable('payment_events') || ! Schema::hasTable('orders') || $attemptIds === []) {
            return 0;
        }

        return (int) DB::table('payment_events')
            ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
            ->whereIn('orders.target_attempt_id', $attemptIds)
            ->count();
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
