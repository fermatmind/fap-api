<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ReportArtifactSlot;
use App\Models\ReportArtifactVersion;
use App\Models\ReportSnapshot;
use App\Models\AttemptReceipt;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class ArtifactLedgerBackfillService
{
    private const SCHEMA = 'artifact_ledger_backfill.v1';

    public function __construct(
        private readonly ArtifactLedgerClassifier $classifier,
        private readonly BlobCatalogService $blobCatalogService,
        private readonly AttemptReceiptBackfillService $receipts,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function buildPlan(array $filters = []): array
    {
        $candidates = $this->collectCandidates($filters);

        return [
            'schema' => self::SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'filters' => $this->normalizedFilters($filters),
            'candidate_count' => count($candidates),
            'candidate_kind_counts' => $this->countBy($candidates, 'artifact_kind'),
            'classification_counts' => $this->countBy($candidates, 'bucket'),
            'slot_counts' => $this->countBy($candidates, 'slot_code'),
            'source_counts' => $this->countBy($candidates, 'source_kind'),
            'alias_or_legacy_path_count' => $this->countByKey($candidates, 'legacy_alias'),
            'manual_or_test_owned_count' => $this->countByKey($candidates, 'manual_or_test_owned'),
            'nohash_count' => $this->countNoHash($candidates),
            'file_backfillable_count' => $this->countByKey($candidates, 'has_file'),
            'db_backfillable_count' => $this->countByKey($candidates, 'has_db_row'),
            'snapshot_backfillable_count' => $this->countBy($candidates, 'source_kind', 'snapshot'),
            'blob_backfillable_count' => $this->countByKey($candidates, 'has_file'),
            'slot_backfillable_count' => count($candidates),
            'version_backfillable_count' => count($candidates),
            'attempt_receipts_backfillable_count' => count($candidates),
            'warnings' => $this->buildWarnings($candidates),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function executeBackfill(array $filters = []): array
    {
        $candidates = $this->collectCandidates($filters);
        $slotsUpserted = 0;
        $versionsInserted = 0;
        $versionsReused = 0;
        $blobsUpserted = 0;
        $locationsUpserted = 0;
        $receiptsInserted = 0;
        $receiptsReused = 0;

        foreach ($candidates as $candidate) {
            $result = $this->persistCandidate($candidate);
            $slotsUpserted += (int) ($result['slot_upserted'] ?? 0);
            $versionsInserted += (int) ($result['version_inserted'] ?? 0);
            $versionsReused += (int) ($result['version_reused'] ?? 0);
            $blobsUpserted += (int) ($result['blob_upserted'] ?? 0);
            $locationsUpserted += (int) ($result['location_upserted'] ?? 0);
            $receiptsInserted += (int) ($result['receipt_inserted'] ?? 0);
            $receiptsReused += (int) ($result['receipt_reused'] ?? 0);
        }

        return [
            'schema' => self::SCHEMA,
            'mode' => 'execute',
            'status' => 'executed',
            'generated_at' => now()->toIso8601String(),
            'filters' => $this->normalizedFilters($filters),
            'candidate_count' => count($candidates),
            'candidate_kind_counts' => $this->countBy($candidates, 'artifact_kind'),
            'classification_counts' => $this->countBy($candidates, 'bucket'),
            'slot_counts' => $this->countBy($candidates, 'slot_code'),
            'source_counts' => $this->countBy($candidates, 'source_kind'),
            'alias_or_legacy_path_count' => $this->countByKey($candidates, 'legacy_alias'),
            'manual_or_test_owned_count' => $this->countByKey($candidates, 'manual_or_test_owned'),
            'nohash_count' => $this->countNoHash($candidates),
            'slots_upserted' => $slotsUpserted,
            'versions_inserted' => $versionsInserted,
            'versions_reused' => $versionsReused,
            'blobs_upserted' => $blobsUpserted,
            'blob_locations_upserted' => $locationsUpserted,
            'attempt_receipts_inserted' => $receiptsInserted,
            'attempt_receipts_reused' => $receiptsReused,
            'warnings' => $this->buildWarnings($candidates),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function collectCandidates(array $filters): array
    {
        $normalized = $this->normalizedFilters($filters);
        $attemptIdFilter = $normalized['attempt_id'];
        $limit = $normalized['limit'];
        $pathRoot = $this->normalizePathRoot($normalized['path_root']);

        $snapshotIndex = $this->collectSnapshots($attemptIdFilter);
        $candidates = [];
        $dedupe = [];

        foreach ($this->collectFileCandidates($pathRoot, $snapshotIndex, $attemptIdFilter) as $candidate) {
            $key = (string) ($candidate['dedupe_key'] ?? '');
            if ($key !== '') {
                $dedupe[$key] = true;
            }

            $candidates[] = $candidate;
        }

        foreach ($snapshotIndex as $attemptId => $snapshot) {
            foreach ($this->snapshotCandidatesForAttempt($snapshot, $attemptId) as $candidate) {
                $key = (string) ($candidate['dedupe_key'] ?? '');
                if ($key !== '' && isset($dedupe[$key])) {
                    continue;
                }

                if ($key !== '') {
                    $dedupe[$key] = true;
                }

                $candidates[] = $candidate;
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            return strcmp(
                implode('|', [
                    (string) ($left['attempt_id'] ?? ''),
                    (string) ($left['slot_code'] ?? ''),
                    (string) ($left['source_kind'] ?? ''),
                    (string) ($left['source_ref'] ?? ''),
                ]),
                implode('|', [
                    (string) ($right['attempt_id'] ?? ''),
                    (string) ($right['slot_code'] ?? ''),
                    (string) ($right['source_kind'] ?? ''),
                    (string) ($right['source_ref'] ?? ''),
                ])
            );
        });

        if ($limit !== null) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        return array_values($candidates);
    }

    /**
     * @param  array<string,mixed>  $snapshotIndex
     * @return list<array<string,mixed>>
     */
    private function collectFileCandidates(string $pathRoot, array $snapshotIndex, ?string $attemptIdFilter): array
    {
        $candidates = [];
        $reportsRoot = $this->joinPath($pathRoot, 'reports');
        $pdfRoot = $this->joinPath($pathRoot, 'pdf');

        if (is_dir($reportsRoot)) {
            foreach (File::allFiles($reportsRoot) as $file) {
                $relativePath = $this->relativePathWithinRoot($file->getPathname(), $pathRoot);
                if ($relativePath === null || ! str_ends_with($relativePath, '/report.json')) {
                    continue;
                }

                $context = $this->parseReportJsonContext($relativePath);
                if ($context === null) {
                    continue;
                }

                if ($attemptIdFilter !== null && $context['attempt_id'] !== $attemptIdFilter) {
                    continue;
                }

                $snapshot = $snapshotIndex[$context['attempt_id']] ?? null;
                $candidates[] = $this->buildCandidate([
                    'artifact_kind' => 'report_json',
                    'slot_code' => 'report_json_full',
                    'variant' => 'full',
                    'source_kind' => 'file',
                    'source_ref' => 'file:'.$relativePath,
                    'source_root' => $pathRoot,
                    'source_path' => $this->absolutePath($file->getPathname()),
                    'relative_path' => $relativePath,
                    'attempt_id' => $context['attempt_id'],
                    'scale_code' => $context['scale_code'],
                    'manifest_hash' => null,
                    'has_file' => true,
                    'has_db_row' => $snapshot !== null,
                    'file_absolute_path' => $file->getPathname(),
                    'snapshot' => $snapshot,
                ]);
            }
        }

        if (is_dir($pdfRoot)) {
            foreach (File::allFiles($pdfRoot) as $file) {
                $relativePath = $this->relativePathWithinRoot($file->getPathname(), $pathRoot);
                if ($relativePath === null || ! preg_match('#^pdf/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#', $relativePath, $matches)) {
                    continue;
                }

                $scaleCode = (string) $matches[1];
                $attemptId = (string) $matches[2];
                $manifestHash = (string) $matches[3];
                $variant = (string) $matches[4];
                if ($attemptIdFilter !== null && $attemptId !== $attemptIdFilter) {
                    continue;
                }

                $snapshot = $snapshotIndex[$attemptId] ?? null;
                $candidates[] = $this->buildCandidate([
                    'artifact_kind' => 'report_pdf',
                    'slot_code' => 'report_pdf_'.$variant,
                    'variant' => $variant,
                    'source_kind' => 'file',
                    'source_ref' => 'file:'.$relativePath,
                    'source_root' => $pathRoot,
                    'source_path' => $this->absolutePath($file->getPathname()),
                    'relative_path' => $relativePath,
                    'attempt_id' => $attemptId,
                    'scale_code' => $scaleCode,
                    'manifest_hash' => $manifestHash,
                    'has_file' => true,
                    'has_db_row' => $snapshot !== null,
                    'file_absolute_path' => $file->getPathname(),
                    'snapshot' => $snapshot,
                ]);
            }
        }

        return $candidates;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectSnapshots(?string $attemptIdFilter): array
    {
        if (! Schema::hasTable('report_snapshots')) {
            return [];
        }

        $query = DB::table('report_snapshots')->orderBy('attempt_id');
        if ($attemptIdFilter !== null) {
            $query->where('attempt_id', $attemptIdFilter);
        }

        $rows = $query->get();
        $index = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $index[$attemptId] = [
                'row' => $row,
                'payload' => $this->snapshotPayloads($row),
            ];
        }

        return $index;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function snapshotCandidatesForAttempt(array $snapshot, string $attemptId): array
    {
        $row = $snapshot['row'] ?? null;
        if (! is_object($row)) {
            return [];
        }

        $payloads = is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
        $scaleCode = trim((string) ($row->scale_code_v2 ?? $row->scale_code ?? ''));
        if ($scaleCode === '') {
            $scaleCode = 'UNKNOWN';
        }

        $candidates = [];
        foreach ([
            'report_json_free' => $payloads['report_free_json'] ?? null,
            'report_json_full' => $payloads['report_full_json'] ?? ($payloads['report_json'] ?? null),
        ] as $slotCode => $payload) {
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            $candidates[] = $this->buildCandidate([
                'artifact_kind' => 'report_json',
                'slot_code' => $slotCode,
                'variant' => str_ends_with($slotCode, '_free') ? 'free' : 'full',
                'source_kind' => 'snapshot',
                'source_ref' => 'report_snapshots#'.$attemptId.'#'.$slotCode,
                'source_root' => storage_path('app/private/artifacts'),
                'source_path' => null,
                'relative_path' => null,
                'attempt_id' => $attemptId,
                'scale_code' => $scaleCode,
                'manifest_hash' => null,
                'has_file' => false,
                'has_db_row' => true,
                'snapshot' => $snapshot,
                'snapshot_payload' => $payload,
            ]);
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function buildCandidate(array $candidate): array
    {
        $classify = $this->classifier->classify($candidate);

        return array_merge($candidate, [
            'bucket' => $classify['bucket'] ?? 'db_only',
            'reasons' => $classify['reasons'] ?? [],
            'legacy_alias' => (bool) ($classify['legacy_alias'] ?? false),
            'legacy_path' => (bool) ($classify['legacy_path'] ?? false),
            'manual_or_test_owned' => (bool) ($classify['manual_or_test_owned'] ?? false),
            'manifest_hash' => $candidate['manifest_hash'] ?? ($classify['manifest_hash'] ?? null),
            'source_root' => $candidate['source_root'] ?? null,
            'snapshot_attempt_id' => $this->snapshotAttemptId($candidate['snapshot'] ?? null),
            'dedupe_key' => $this->dedupeKey($candidate),
        ]);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function persistCandidate(array $candidate): array
    {
        if (! $this->isTablesReady()) {
            return [
                'slot_upserted' => 0,
                'version_inserted' => 0,
                'version_reused' => 0,
                'blob_upserted' => 0,
                'location_upserted' => 0,
                'receipt_inserted' => 0,
                'receipt_reused' => 0,
            ];
        }

        $attemptId = trim((string) ($candidate['attempt_id'] ?? ''));
        $slotCode = trim((string) ($candidate['slot_code'] ?? ''));
        if ($attemptId === '' || $slotCode === '') {
            return [
                'slot_upserted' => 0,
                'version_inserted' => 0,
                'version_reused' => 0,
                'blob_upserted' => 0,
                'location_upserted' => 0,
                'receipt_inserted' => 0,
                'receipt_reused' => 0,
            ];
        }

        return DB::transaction(function () use ($candidate, $attemptId, $slotCode): array {
            $now = now();
            $sourceKind = trim((string) ($candidate['source_kind'] ?? 'snapshot'));
            $artifactKind = trim((string) ($candidate['artifact_kind'] ?? 'report_json'));
            $variant = trim((string) ($candidate['variant'] ?? 'full'));
            $slot = ReportArtifactSlot::query()->firstOrCreate(
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

            $contentBytes = $this->contentBytesForCandidate($candidate);
            $contentHash = hash('sha256', $contentBytes);
            $byteSize = strlen($contentBytes);
            $blob = null;
            $blobUpserted = 0;
            $locationUpserted = 0;

            if (($candidate['has_file'] ?? false) === true && is_string($candidate['file_absolute_path'] ?? null)) {
                $blob = $this->upsertBlobForFile($candidate, $contentBytes, $contentHash, $byteSize, $now);
                $blobUpserted = 1;
                $locationUpserted = 1;
            }

            $receiptPayload = [
                'artifact_kind' => $artifactKind,
                'slot_code' => $slotCode,
                'variant' => $variant,
                'source_kind' => $sourceKind,
                'source_path' => $candidate['source_path'] ?? null,
                'relative_path' => $candidate['relative_path'] ?? null,
                'manifest_hash' => $candidate['manifest_hash'] ?? null,
                'content_hash' => $contentHash,
                'byte_size' => $byteSize,
                'report_snapshot_id' => $this->snapshotRowId($candidate['snapshot'] ?? null),
                'bucket' => $candidate['bucket'] ?? null,
                'reasons' => $candidate['reasons'] ?? [],
            ];
            $receiptMeta = [
                'source_system' => 'artifact_ledger_backfill',
                'source_ref' => (string) ($candidate['source_ref'] ?? $candidate['source_path'] ?? ('report_snapshots#'.$attemptId.'#'.$slotCode)),
                'actor_type' => 'system',
                'actor_id' => 'artifact_ledger_backfill',
                'idempotency_key' => hash('sha256', implode('|', [
                    $attemptId,
                    $artifactKind === 'report_pdf' ? 'report_pdf_materialized' : 'report_json_materialized',
                    'artifact_ledger_backfill',
                    (string) ($candidate['source_ref'] ?? $candidate['source_path'] ?? ''),
                    $contentHash,
                ])),
                'occurred_at' => $now,
                'recorded_at' => $now,
            ];

            $hadReceipt = AttemptReceipt::query()
                ->where('attempt_id', $attemptId)
                ->where('idempotency_key', $receiptMeta['idempotency_key'])
                ->exists();

            $receipt = $this->receipts->recordHistoricalReceipt(
                $attemptId,
                $artifactKind === 'report_pdf' ? 'report_pdf_materialized' : 'report_json_materialized',
                $receiptPayload,
                $receiptMeta
            );

            $latest = ReportArtifactVersion::query()
                ->where('artifact_slot_id', (int) $slot->id)
                ->orderByDesc('version_no')
                ->first();

            $versionPayload = [
                'artifact_slot_id' => (int) $slot->id,
                'source_type' => $sourceKind,
                'report_snapshot_id' => $this->snapshotRowId($candidate['snapshot'] ?? null),
                'storage_blob_id' => $blob?->hash,
                'created_from_receipt_id' => $receipt?->id,
                'supersedes_version_id' => $latest?->id,
                'manifest_hash' => $candidate['manifest_hash'] ?? null,
                'dir_version' => $this->snapshotField($candidate['snapshot'] ?? null, 'dir_version'),
                'scoring_spec_version' => $this->snapshotField($candidate['snapshot'] ?? null, 'scoring_spec_version'),
                'report_engine_version' => $this->snapshotField($candidate['snapshot'] ?? null, 'report_engine_version'),
                'content_hash' => $contentHash,
                'byte_size' => $byteSize,
                'metadata_json' => array_filter([
                    'bucket' => $candidate['bucket'] ?? null,
                    'reasons' => $candidate['reasons'] ?? [],
                    'source_kind' => $sourceKind,
                    'artifact_kind' => $artifactKind,
                    'slot_code' => $slotCode,
                    'variant' => $variant,
                    'source_path' => $candidate['source_path'] ?? null,
                    'relative_path' => $candidate['relative_path'] ?? null,
                    'manifest_hash' => $candidate['manifest_hash'] ?? null,
                    'report_snapshot_id' => $this->snapshotRowId($candidate['snapshot'] ?? null),
                    'has_file' => (bool) ($candidate['has_file'] ?? false),
                    'has_db_row' => (bool) ($candidate['has_db_row'] ?? false),
                    'legacy_alias' => (bool) ($candidate['legacy_alias'] ?? false),
                    'legacy_path' => (bool) ($candidate['legacy_path'] ?? false),
                    'manual_or_test_owned' => (bool) ($candidate['manual_or_test_owned'] ?? false),
                ], static fn (mixed $value): bool => $value !== null),
            ];

            $sameVersion = $latest instanceof ReportArtifactVersion
                && (string) ($latest->source_type ?? '') === $sourceKind
                && (string) ($latest->content_hash ?? '') === $contentHash
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
                    'access_state' => 'locked',
                    'integrity_state' => 'verified',
                    'last_materialized_at' => $now,
                    'last_verified_at' => $now,
                ])->save();

                return [
                    'slot_upserted' => 1,
                    'version_inserted' => 0,
                    'version_reused' => 1,
                    'blob_upserted' => $blobUpserted,
                    'location_upserted' => $locationUpserted,
                    'receipt_inserted' => $hadReceipt ? 0 : 1,
                    'receipt_reused' => $hadReceipt ? 1 : 0,
                ];
            }

            $version = ReportArtifactVersion::query()->create([
                'artifact_slot_id' => (int) $slot->id,
                'version_no' => ((int) ($latest?->version_no ?? 0)) + 1,
                'source_type' => $sourceKind,
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
                'access_state' => 'locked',
                'integrity_state' => 'verified',
                'last_materialized_at' => $now,
                'last_verified_at' => $now,
            ])->save();

            return [
                'slot_upserted' => 1,
                'version_inserted' => 1,
                'version_reused' => 0,
                'blob_upserted' => $blobUpserted,
                'location_upserted' => $locationUpserted,
                'receipt_inserted' => $hadReceipt ? 0 : 1,
                'receipt_reused' => $hadReceipt ? 1 : 0,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function upsertBlobForFile(array $candidate, string $contentBytes, string $contentHash, int $byteSize, mixed $verifiedAt): ?object
    {
        if (! SchemaBaseline::hasTable('storage_blobs') || ! SchemaBaseline::hasTable('storage_blob_locations')) {
            return null;
        }

        $absolutePath = (string) ($candidate['file_absolute_path'] ?? '');
        if ($absolutePath === '' || ! is_file($absolutePath)) {
            return null;
        }

        $blob = $this->blobCatalogService->upsertBlob([
            'hash' => $contentHash,
            'disk' => 'local',
            'storage_path' => $this->blobCatalogService->storagePathForHash($contentHash),
            'size_bytes' => $byteSize,
            'content_type' => ($candidate['artifact_kind'] ?? '') === 'report_pdf' ? 'application/pdf' : 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
            'first_seen_at' => $verifiedAt,
            'last_verified_at' => $verifiedAt,
        ]);

        $this->blobCatalogService->upsertBlobLocation([
            'blob_hash' => $blob->hash,
            'disk' => 'local',
            'storage_path' => (string) ($candidate['source_path'] ?? $candidate['relative_path'] ?? $absolutePath),
            'location_kind' => 'canonical_file',
            'size_bytes' => $byteSize,
            'checksum' => $contentHash,
            'etag' => $contentHash,
            'storage_class' => 'local',
            'verified_at' => $verifiedAt,
            'meta_json' => array_filter([
                'bucket' => $candidate['bucket'] ?? null,
                'reasons' => $candidate['reasons'] ?? [],
                'source_kind' => $candidate['source_kind'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);

        return $blob;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function contentBytesForCandidate(array $candidate): string
    {
        if (($candidate['has_file'] ?? false) === true && is_string($candidate['file_absolute_path'] ?? null)) {
            $path = (string) $candidate['file_absolute_path'];
            if (is_file($path)) {
                $contents = File::get($path);
                if (is_string($contents)) {
                    return $contents;
                }
            }
        }

        $snapshotPayload = $candidate['snapshot_payload'] ?? null;
        if (is_array($snapshotPayload)) {
            $encoded = json_encode($snapshotPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        $snapshot = $candidate['snapshot'] ?? null;
        if (is_array($snapshot)) {
            $row = $snapshot['row'] ?? null;
            $payload = is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
            $encoded = json_encode([
                'attempt_id' => is_object($row) ? (string) ($row->attempt_id ?? '') : '',
                'scale_code' => is_object($row) ? (string) ($row->scale_code ?? $row->scale_code_v2 ?? '') : '',
                'dir_version' => is_object($row) ? (string) ($row->dir_version ?? '') : '',
                'scoring_spec_version' => is_object($row) ? (string) ($row->scoring_spec_version ?? '') : '',
                'report_engine_version' => is_object($row) ? (string) ($row->report_engine_version ?? '') : '',
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return '';
    }

    /**
     * @param  object|array<string,mixed>|null  $snapshot
     */
    private function snapshotRowId(object|array|null $snapshot): ?string
    {
        if (is_array($snapshot)) {
            $row = $snapshot['row'] ?? null;
            if (is_object($row)) {
                return (string) ($row->attempt_id ?? null);
            }
        }

        if (is_object($snapshot)) {
            return (string) ($snapshot->attempt_id ?? null);
        }

        return null;
    }

    /**
     * @param  object|array<string,mixed>|null  $snapshot
     */
    private function snapshotField(object|array|null $snapshot, string $field): ?string
    {
        $row = $this->snapshotRow($snapshot);
        if ($row === null) {
            return null;
        }

        $value = $row->{$field} ?? null;

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * @param  object|array<string,mixed>|null  $snapshot
     */
    private function snapshotAttemptId(object|array|null $snapshot): ?string
    {
        $row = $this->snapshotRow($snapshot);
        if ($row === null) {
            return null;
        }

        return trim((string) ($row->attempt_id ?? '')) !== '' ? trim((string) ($row->attempt_id ?? '')) : null;
    }

    /**
     * @param  object|array<string,mixed>|null  $snapshot
     */
    private function snapshotRow(object|array|null $snapshot): ?object
    {
        if (is_array($snapshot) && is_object($snapshot['row'] ?? null)) {
            return $snapshot['row'];
        }

        if (is_object($snapshot)) {
            return $snapshot;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function dedupeKey(array $candidate): string
    {
        return implode('|', [
            (string) ($candidate['attempt_id'] ?? ''),
            (string) ($candidate['slot_code'] ?? ''),
        ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function normalizedFilters(array $filters): array
    {
        return [
            'attempt_id' => $this->normalizeNullableText($filters['attempt_id'] ?? null),
            'path_root' => $this->normalizePathRoot($filters['path_root'] ?? null),
            'limit' => isset($filters['limit']) ? max(1, (int) $filters['limit']) : null,
        ];
    }

    private function normalizePathRoot(mixed $value): string
    {
        $path = trim((string) ($value ?? ''));
        if ($path === '') {
            return storage_path('app/private/artifacts');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return storage_path(ltrim($path, '/\\'));
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function joinPath(string $root, string $path): string
    {
        return rtrim($root, '/').'/'.ltrim($path, '/');
    }

    private function absolutePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function relativePathWithinRoot(string $absolutePath, string $root): ?string
    {
        $absolute = str_replace('\\', '/', $absolutePath);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $prefix = $root.'/';

        if (! str_starts_with($absolute, $prefix)) {
            return null;
        }

        return ltrim(substr($absolute, strlen($prefix)), '/');
    }

    /**
     * @return array{attempt_id:string,scale_code:string}|null
     */
    private function parseReportJsonContext(string $relativePath): ?array
    {
        if (preg_match('#^reports/([^/]+)/([^/]+)/report\.json$#', $relativePath, $matches) !== 1) {
            return null;
        }

        return [
            'scale_code' => (string) $matches[1],
            'attempt_id' => (string) $matches[2],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotPayloads(object $row): array
    {
        return [
            'report_json' => $this->decodeJsonObject($row->report_json ?? null),
            'report_free_json' => $this->decodeJsonObject($row->report_free_json ?? null),
            'report_full_json' => $this->decodeJsonObject($row->report_full_json ?? null),
        ];
    }

    private function decodeJsonObject(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function countBy(array $items, string $key, ?string $value = null): array|int
    {
        if ($value !== null) {
            $count = 0;
            foreach ($items as $item) {
                if ((string) ($item[$key] ?? '') === $value) {
                    $count++;
                }
            }

            return $count;
        }

        $counts = [];
        foreach ($items as $item) {
            $current = trim((string) ($item[$key] ?? ''));
            if ($current === '') {
                $current = 'unknown';
            }

            $counts[$current] = (int) ($counts[$current] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function countByKey(array $items, string $key): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (($item[$key] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function countNoHash(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (strtolower(trim((string) ($item['manifest_hash'] ?? ''))) === 'nohash') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function buildWarnings(array $items): array
    {
        $warnings = [];

        foreach ($items as $item) {
            if (($item['manual_or_test_owned'] ?? false) === true) {
                $warnings['manual_or_test_owned'] = 'manual_or_test_owned artifacts are excluded from auto-normalization';
            }
            if (($item['legacy_alias'] ?? false) === true) {
                $warnings['alias_or_legacy_path'] = 'BIG5/BIG5_OCEAN alias and legacy report paths are preserved as evidence';
            }
            if (strtolower(trim((string) ($item['manifest_hash'] ?? ''))) === 'nohash') {
                $warnings['nohash'] = 'nohash PDF manifests are preserved as evidence';
            }
        }

        return array_values($warnings);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isTablesReady(): bool
    {
        return SchemaBaseline::hasTable('report_artifact_slots')
            && SchemaBaseline::hasTable('report_artifact_versions')
            && SchemaBaseline::hasTable('attempt_receipts');
    }
}
