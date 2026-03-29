<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ReportArtifactSlot;
use App\Models\ReportArtifactVersion;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class ReportArtifactCatalogWriter
{
    private const REPORT_JSON_SLOT = 'report_json_full';

    public function __construct(
        private readonly AttemptReceiptRecorder $receipts,
        private readonly BlobCatalogService $blobCatalogService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{receipt:?array<string,mixed>,slot:?array<string,mixed>,version:?array<string,mixed>}|null
     */
    public function recordReportJsonMaterialized(
        string $attemptId,
        string $scaleCode,
        string $storagePath,
        string $jsonBytes,
        array $payload = []
    ): ?array {
        return $this->recordMaterializedArtifact(
            attemptId: $attemptId,
            scaleCode: $scaleCode,
            slotCode: self::REPORT_JSON_SLOT,
            sourceType: 'report_json',
            storagePath: $storagePath,
            contentBytes: $jsonBytes,
            payload: $payload
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{receipt:?array<string,mixed>,slot:?array<string,mixed>,version:?array<string,mixed>}|null
     */
    public function recordReportPdfMaterialized(
        string $attemptId,
        string $scaleCode,
        string $variant,
        string $manifestHash,
        string $storagePath,
        string $pdfBytes,
        array $payload = []
    ): ?array {
        $slotCode = 'report_pdf_'.(strtolower(trim($variant)) === 'full' ? 'full' : 'free');

        return $this->recordMaterializedArtifact(
            attemptId: $attemptId,
            scaleCode: $scaleCode,
            slotCode: $slotCode,
            sourceType: 'report_pdf',
            storagePath: $storagePath,
            contentBytes: $pdfBytes,
            payload: $payload + [
                'manifest_hash' => $manifestHash,
                'variant' => strtolower(trim($variant)) === 'full' ? 'full' : 'free',
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{receipt:?array<string,mixed>,slot:?array<string,mixed>,version:?array<string,mixed>}|null
     */
    private function recordMaterializedArtifact(
        string $attemptId,
        string $scaleCode,
        string $slotCode,
        string $sourceType,
        string $storagePath,
        string $contentBytes,
        array $payload
    ): ?array {
        $attemptId = trim($attemptId);
        $scaleCode = strtoupper(trim($scaleCode));
        $slotCode = trim($slotCode);
        $sourceType = trim($sourceType);
        if ($attemptId === '' || $slotCode === '' || $sourceType === '' || ! $this->isEnabled()) {
            return null;
        }

        if (! SchemaBaseline::hasTable('report_artifact_slots') || ! SchemaBaseline::hasTable('report_artifact_versions')) {
            return null;
        }

        $contentHash = hash('sha256', $contentBytes);
        $byteSize = strlen($contentBytes);
        $now = now();
        $snapshotMeta = $this->loadSnapshotMeta($attemptId);
        $blob = $this->blobCatalogService->findByHash($contentHash);

        return DB::transaction(function () use (
            $attemptId,
            $scaleCode,
            $slotCode,
            $sourceType,
            $storagePath,
            $contentHash,
            $byteSize,
            $payload,
            $snapshotMeta,
            $blob,
            $now
        ): array {
            $receipt = $this->receipts->record(
                $attemptId,
                $sourceType === 'report_pdf' ? 'report_pdf_materialized' : 'report_json_materialized',
                [
                    'scale_code' => $scaleCode,
                    'slot_code' => $slotCode,
                    'source_type' => $sourceType,
                    'storage_path' => $storagePath,
                    'content_hash' => $contentHash,
                    'byte_size' => $byteSize,
                    'manifest_hash' => $payload['manifest_hash'] ?? null,
                    'variant' => $payload['variant'] ?? null,
                    'report_snapshot_id' => $snapshotMeta['report_snapshot_id'] ?? null,
                ],
                [
                    'source_system' => 'artifact_store',
                    'source_ref' => $storagePath,
                    'actor_type' => 'system',
                    'actor_id' => 'artifact_store',
                    'idempotency_key' => hash('sha256', implode('|', [
                        $attemptId,
                        $slotCode,
                        $sourceType,
                        $storagePath,
                        $contentHash,
                    ])),
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                ]
            );

            $slot = ReportArtifactSlot::query()
                ->firstOrCreate(
                    [
                        'attempt_id' => $attemptId,
                        'slot_code' => $slotCode,
                    ],
                    [
                        'required_by_product' => false,
                        'render_state' => 'materialized',
                        'delivery_state' => 'available',
                        'access_state' => 'locked',
                        'integrity_state' => 'verified',
                        'last_materialized_at' => $now,
                        'last_verified_at' => $now,
                    ]
                );

            $latest = ReportArtifactVersion::query()
                ->where('artifact_slot_id', (int) $slot->id)
                ->orderByDesc('version_no')
                ->first();

            $versionPayload = [
                'artifact_slot_id' => (int) $slot->id,
                'source_type' => $sourceType,
                'report_snapshot_id' => $snapshotMeta['report_snapshot_id'] ?? null,
                'storage_blob_id' => $blob?->hash,
                'created_from_receipt_id' => $receipt?->id,
                'supersedes_version_id' => $latest?->id,
                'manifest_hash' => $snapshotMeta['manifest_hash'] ?? ($payload['manifest_hash'] ?? null),
                'dir_version' => $snapshotMeta['dir_version'] ?? ($payload['dir_version'] ?? null),
                'scoring_spec_version' => $snapshotMeta['scoring_spec_version'] ?? ($payload['scoring_spec_version'] ?? null),
                'report_engine_version' => $snapshotMeta['report_engine_version'] ?? ($payload['report_engine_version'] ?? null),
                'content_hash' => $contentHash,
                'byte_size' => $byteSize,
                'metadata_json' => array_merge($payload, [
                    'scale_code' => $scaleCode,
                    'slot_code' => $slotCode,
                    'source_type' => $sourceType,
                    'storage_path' => $storagePath,
                    'content_hash' => $contentHash,
                    'byte_size' => $byteSize,
                    'report_snapshot_id' => $snapshotMeta['report_snapshot_id'] ?? null,
                ]),
            ];

            $sameVersion = $latest instanceof ReportArtifactVersion
                && (string) ($latest->content_hash ?? '') === $contentHash
                && (string) ($latest->source_type ?? '') === $sourceType
                && (string) ($latest->storage_blob_id ?? '') === (string) ($blob?->hash ?? '')
                && (string) ($latest->manifest_hash ?? '') === (string) ($versionPayload['manifest_hash'] ?? '')
                && (string) ($latest->dir_version ?? '') === (string) ($versionPayload['dir_version'] ?? '')
                && (string) ($latest->scoring_spec_version ?? '') === (string) ($versionPayload['scoring_spec_version'] ?? '')
                && (string) ($latest->report_engine_version ?? '') === (string) ($versionPayload['report_engine_version'] ?? '');

            if ($sameVersion) {
                $slot->forceFill([
                    'current_version_id' => (int) $latest->id,
                    'render_state' => 'materialized',
                    'delivery_state' => 'available',
                    'integrity_state' => 'verified',
                    'last_materialized_at' => $now,
                    'last_verified_at' => $now,
                ])->save();

                return [
                    'receipt' => $receipt?->toArray(),
                    'slot' => $slot->fresh()?->toArray() ?? $slot->toArray(),
                    'version' => $latest->toArray(),
                ];
            }

            $version = ReportArtifactVersion::query()->create([
                'artifact_slot_id' => (int) $slot->id,
                'version_no' => ((int) ($latest?->version_no ?? 0)) + 1,
                'source_type' => $sourceType,
                'report_snapshot_id' => $versionPayload['report_snapshot_id'],
                'storage_blob_id' => $versionPayload['storage_blob_id'],
                'created_from_receipt_id' => $versionPayload['created_from_receipt_id'],
                'supersedes_version_id' => $versionPayload['supersedes_version_id'],
                'manifest_hash' => $versionPayload['manifest_hash'],
                'dir_version' => $versionPayload['dir_version'],
                'scoring_spec_version' => $versionPayload['scoring_spec_version'],
                'report_engine_version' => $versionPayload['report_engine_version'],
                'content_hash' => $contentHash,
                'byte_size' => $byteSize,
                'metadata_json' => $versionPayload['metadata_json'],
            ]);

            $slot->forceFill([
                'current_version_id' => (int) $version->id,
                'render_state' => 'materialized',
                'delivery_state' => 'available',
                'integrity_state' => 'verified',
                'last_materialized_at' => $now,
                'last_verified_at' => $now,
            ])->save();

            return [
                'receipt' => $receipt?->toArray(),
                'slot' => $slot->fresh()?->toArray() ?? $slot->toArray(),
                'version' => $version->fresh()?->toArray() ?? $version->toArray(),
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSnapshotMeta(string $attemptId): array
    {
        if (! SchemaBaseline::hasTable('report_snapshots')) {
            return [];
        }

        $row = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->first();
        if (! $row) {
            return [];
        }

        return [
            'report_snapshot_id' => (string) ($row->attempt_id ?? $attemptId),
            'manifest_hash' => $this->trimText($row->manifest_hash ?? null),
            'dir_version' => $this->trimText($row->dir_version ?? null),
            'scoring_spec_version' => $this->trimText($row->scoring_spec_version ?? null),
            'report_engine_version' => $this->trimText($row->report_engine_version ?? null),
        ];
    }

    private function trimText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.receipt_ledger_dual_write_enabled', false)
            && (bool) config('storage_rollout.artifact_slot_version_dual_write_enabled', false);
    }
}
