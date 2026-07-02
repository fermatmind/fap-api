<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ReportArtifactVersion;
use App\Models\StorageBlob;
use App\Models\StorageBlobLocation;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ArtifactPurgeService
{
    private const PDF_VARIANTS = ['free', 'full'];

    public function __construct(
        private readonly ArtifactStore $artifactStore,
        private readonly ArtifactLifecycleFrontDoor $frontDoor,
        private readonly LegalHoldService $legalHoldService,
        private readonly RetentionPolicyResolver $retentionPolicyResolver,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function purgeAttemptArtifacts(object $attempt, ?object $result = null, array $context = []): array
    {
        $attemptId = trim((string) ($attempt->id ?? ''));
        $orgId = (int) ($attempt->org_id ?? 0);
        $scaleCode = trim((string) ($attempt->scale_code ?? (string) ($context['scale_code'] ?? '')));
        if ($attemptId === '' || $scaleCode === '') {
            return [
                'ok' => false,
                'error' => 'ARTIFACT_PURGE_CONTEXT_INVALID',
            ];
        }

        $this->retentionPolicyResolver->ensureAttemptBinding($attemptId, 'dsar_artifact_purge');

        $blockedReason = $this->legalHoldService->blockedReasonCodeForAttempt($attemptId);
        if ($blockedReason !== null) {
            return [
                'ok' => false,
                'error' => 'LEGAL_HOLD_ACTIVE',
                'blocked_reason_code' => $blockedReason,
                'artifact_residual_audit' => $this->residualAudit($attempt, $result, [
                    'remote_state' => 'remote_purge_not_attempted_due_to_hold',
                ]),
            ];
        }

        $descriptor = is_array($context['artifact_descriptor'] ?? null)
            ? $context['artifact_descriptor']
            : $this->describeAttemptArtifacts($attempt, $result);
        $requestPayload = [
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'reason' => (string) ($context['reason'] ?? 'user_request'),
            'request_id' => (string) ($context['request_id'] ?? ''),
            'descriptor' => $descriptor,
        ];

        $resultPayload = $this->frontDoor->execute(
            'dsar_purge_report_artifacts',
            [
                'schema' => 'artifact_purge_request.v1',
                'mode' => 'execute',
                'summary' => [
                    'candidate_count' => count($descriptor['canonical_paths']) + count($descriptor['catalog_location_ids']),
                ],
                'candidates' => [
                    [
                        'attempt_id' => $attemptId,
                        'kind' => 'report_artifact_domain',
                        'source_path' => $descriptor['canonical_paths']['report'] ?? null,
                    ],
                ],
                '_meta' => [
                    'plan_path' => 'dsar:'.$attemptId,
                ],
                'request_payload' => $requestPayload,
            ],
            function () use ($descriptor, $attemptId): array {
                return $this->executePurge($descriptor, $attemptId);
            }
        );

        return [
            'ok' => true,
            'job_status' => $resultPayload['status'] ?? 'executed',
            'counts' => is_array($resultPayload['summary'] ?? null) ? $resultPayload['summary'] : [],
            'artifact_residual_audit' => $this->residualAudit($attempt, $result, [
                'remote_state' => (string) data_get($resultPayload, 'remote_state', 'remote_state_unknown'),
                'purge_result' => $resultPayload,
            ]),
            'purge_result' => $resultPayload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function describeAttemptArtifacts(object $attempt, ?object $result = null): array
    {
        $attemptId = trim((string) ($attempt->id ?? ''));
        $scaleCode = trim((string) ($attempt->scale_code ?? ''));
        $manifestHash = $this->resolveManifestHash($attempt, $result);

        $canonicalPaths = [
            'report' => $this->artifactStore->reportCanonicalPath($scaleCode, $attemptId),
        ] + $this->pdfCanonicalPathsForPurge($scaleCode, $attemptId, $manifestHash, $attempt);

        $blobHashes = [];
        $locationIds = [];

        if (SchemaBaseline::hasTable('report_artifact_versions')) {
            $hashes = ReportArtifactVersion::query()
                ->whereIn('artifact_slot_id', function ($query) use ($attemptId): void {
                    $query->from('report_artifact_slots')
                        ->select('id')
                        ->where('attempt_id', $attemptId);
                })
                ->whereNotNull('storage_blob_id')
                ->pluck('storage_blob_id');
            foreach ($hashes as $hash) {
                $hash = trim((string) $hash);
                if ($hash !== '') {
                    $blobHashes[$hash] = true;
                }
            }
        }

        if (SchemaBaseline::hasTable('storage_blob_locations')) {
            $locationRows = StorageBlobLocation::query()
                ->whereIn('storage_path', array_values(array_unique($canonicalPaths)))
                ->get(['id', 'blob_hash', 'disk', 'storage_path', 'location_kind', 'meta_json']);

            foreach ($locationRows as $locationRow) {
                $locationIds[] = (int) $locationRow->id;
                $hash = trim((string) ($locationRow->blob_hash ?? ''));
                if ($hash !== '') {
                    $blobHashes[$hash] = true;
                }
            }
        }

        return [
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'manifest_hash' => $manifestHash,
            'canonical_paths' => $canonicalPaths,
            'catalog_blob_hashes' => array_values(array_keys($blobHashes)),
            'catalog_location_ids' => $locationIds,
        ];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @return array<string,mixed>
     */
    private function executePurge(array $descriptor, string $attemptId): array
    {
        $canonicalPaths = is_array($descriptor['canonical_paths'] ?? null) ? $descriptor['canonical_paths'] : [];
        $blobHashes = array_values(array_filter((array) ($descriptor['catalog_blob_hashes'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        $locationIds = array_values(array_filter(
            array_map(static fn (mixed $value): int => (int) $value, (array) ($descriptor['catalog_location_ids'] ?? [])),
            static fn (int $value): bool => $value > 0
        ));

        $results = [];
        $summary = [
            'canonical_deleted_count' => 0,
            'canonical_not_found_count' => 0,
            'catalog_location_rows_deleted' => 0,
            'catalog_blob_rows_deleted' => 0,
            'remote_deleted_count' => 0,
            'remote_not_found_count' => 0,
            'remote_failed_count' => 0,
        ];

        foreach ($canonicalPaths as $kind => $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }

            $exists = Storage::disk('local')->exists($path);
            if ($exists) {
                Storage::disk('local')->delete($path);
                $summary['canonical_deleted_count']++;
                $results[] = [
                    'attempt_id' => $attemptId,
                    'kind' => (string) $kind,
                    'source_path' => $path,
                    'status' => 'purged',
                ];
            } else {
                $summary['canonical_not_found_count']++;
                $results[] = [
                    'attempt_id' => $attemptId,
                    'kind' => (string) $kind,
                    'source_path' => $path,
                    'status' => 'not_found',
                ];
            }
        }

        $remoteState = 'remote_state_unknown';
        if (SchemaBaseline::hasTable('storage_blob_locations')) {
            $locationsQuery = StorageBlobLocation::query();
            if ($locationIds !== []) {
                $locationsQuery->whereIn('id', $locationIds);
            } else {
                $locationsQuery->whereRaw('1 = 0');
            }

            $locations = $locationsQuery->get();

            foreach ($locations as $location) {
                $disk = trim((string) ($location->disk ?? ''));
                $storagePath = trim((string) ($location->storage_path ?? ''));
                if (! $this->isPurgeableStoragePath($storagePath)) {
                    continue;
                }

                $status = 'not_found';
                if ($disk === 'local') {
                    $status = 'catalog_row_only';
                } elseif ($disk !== '' && config('filesystems.disks.'.$disk) !== null) {
                    try {
                        $exists = Storage::disk($disk)->exists($storagePath);
                        if ($exists) {
                            Storage::disk($disk)->delete($storagePath);
                            $status = 'purged';
                        }
                    } catch (\Throwable $e) {
                        $status = 'failed';
                        Log::warning('ARTIFACT_REMOTE_PURGE_FAILED', [
                            'disk' => $disk,
                            'storage_path' => $storagePath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $status = 'unsupported';
                }

                if ($disk !== 'local') {
                    if ($status === 'purged') {
                        $summary['remote_deleted_count']++;
                        $remoteState = 'remote_purged';
                    } elseif ($status === 'not_found') {
                        $summary['remote_not_found_count']++;
                        if ($remoteState === 'remote_state_unknown') {
                            $remoteState = 'remote_not_found';
                        }
                    } elseif ($status === 'failed') {
                        $summary['remote_failed_count']++;
                        $remoteState = 'remote_purge_partial_failure';
                    } elseif ($status === 'unsupported' && $remoteState === 'remote_state_unknown') {
                        $remoteState = 'remote_purge_unsupported';
                    }
                }

                $location->delete();
                $summary['catalog_location_rows_deleted']++;
                $results[] = [
                    'attempt_id' => $attemptId,
                    'kind' => 'catalog_location',
                    'source_path' => $storagePath,
                    'target_disk' => $disk,
                    'status' => $status,
                ];
            }
        }

        if ($blobHashes !== [] && SchemaBaseline::hasTable('storage_blobs')) {
            foreach ($blobHashes as $blobHash) {
                $remainingLocations = SchemaBaseline::hasTable('storage_blob_locations')
                    ? StorageBlobLocation::query()->where('blob_hash', $blobHash)->count()
                    : 0;
                if ($remainingLocations > 0) {
                    continue;
                }

                $summary['catalog_blob_rows_deleted'] += StorageBlob::query()
                    ->where('hash', $blobHash)
                    ->delete();
            }
        }

        return [
            'schema' => 'artifact_purge_run.v1',
            'mode' => 'execute',
            'status' => $summary['remote_failed_count'] > 0 ? 'partial_failure' : 'executed',
            'summary' => $summary,
            'results' => $results,
            'remote_state' => $remoteState,
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function residualAudit(object $attempt, ?object $result = null, array $extra = []): array
    {
        $attemptId = trim((string) ($attempt->id ?? ''));
        $scaleCode = trim((string) ($attempt->scale_code ?? ''));
        $reportPath = $this->artifactStore->reportCanonicalPath($scaleCode, $attemptId);
        $reportExists = $this->artifactStore->exists($reportPath);
        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $pdfPaths = $this->pdfAuditPaths(
            $this->pdfCanonicalPathsForPurge($scaleCode, $attemptId, $manifestHash, $attempt)
        );
        $pdfExists = false;
        foreach ($pdfPaths as $pdfPath) {
            if ($this->artifactStore->exists($pdfPath)) {
                $pdfExists = true;
                break;
            }
        }

        $state = match (true) {
            $reportExists && $pdfExists => 'residual_report_json_and_pdf_found',
            $reportExists => 'residual_report_json_found',
            $pdfExists => 'residual_pdf_found',
            default => 'no_residual_found',
        };

        return [
            'state' => $state,
            'remote_state' => (string) ($extra['remote_state'] ?? 'remote_state_unknown'),
            'attempt_id' => $attemptId,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'scale_code' => $scaleCode,
            'report' => [
                'path' => $reportPath,
                'exists' => $reportExists,
            ],
            'pdf' => [
                'manifest_hash' => $manifestHash,
                'paths' => $pdfPaths,
                'exists' => $pdfExists,
                'variants_checked' => array_values(array_keys($pdfPaths)),
            ],
            'purge_result' => $extra['purge_result'] ?? null,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function pdfCanonicalPathsForPurge(
        string $scaleCode,
        string $attemptId,
        string $manifestHash,
        object $attempt
    ): array {
        $paths = [];
        foreach (self::PDF_VARIANTS as $variant) {
            $paths['pdf_'.$variant] = $this->artifactStore->pdfCanonicalPath($scaleCode, $attemptId, $manifestHash, $variant);
        }

        if (strtoupper(trim($scaleCode)) !== 'MBTI') {
            return $paths;
        }

        $surfaceManifestHash = ReportPdfDocumentService::mbtiReportPdfSurfaceManifestHash($manifestHash);
        foreach (self::PDF_VARIANTS as $variant) {
            $paths['pdf_surface_'.$variant] = $this->artifactStore->pdfCanonicalPath('MBTI', $attemptId, $surfaceManifestHash, $variant);
        }

        $locale = strtolower(trim((string) ($attempt->locale ?? '')));
        foreach (['locked', 'unlocked'] as $entitlement) {
            foreach (self::PDF_VARIANTS as $variant) {
                $resultPageManifestHash = ReportPdfDocumentService::mbtiResultPageExportManifestHash(
                    $manifestHash,
                    $locale,
                    $entitlement,
                    $variant
                );
                $paths['result_page_pdf_'.$entitlement.'_'.$variant] = $this->artifactStore->pdfCanonicalPath(
                    'MBTI',
                    $attemptId,
                    $resultPageManifestHash,
                    $variant
                );
            }
        }

        return $paths;
    }

    /**
     * @param  array<string,string>  $canonicalPaths
     * @return array<string,string>
     */
    private function pdfAuditPaths(array $canonicalPaths): array
    {
        $auditPaths = [];
        foreach ($canonicalPaths as $kind => $path) {
            $auditKind = match ($kind) {
                'pdf_free' => 'free',
                'pdf_full' => 'full',
                default => $kind,
            };
            $auditPaths[$auditKind] = $path;
        }

        return $auditPaths;
    }

    private function resolveManifestHash(object $attempt, ?object $result = null): string
    {
        $summary = $this->decodeArray($attempt->answers_summary_json ?? null);
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $hash = trim((string) ($meta['pack_release_manifest_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $resultPayload = $this->decodeArray($result?->result_json ?? null);
        $hash = trim((string) (
            data_get($resultPayload, 'version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'normed_json.version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'content_manifest_hash')
            ?? ''
        ));

        return $hash !== '' ? $hash : 'nohash';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isPurgeableStoragePath(string $storagePath): bool
    {
        return str_starts_with($storagePath, 'artifacts/')
            || str_starts_with($storagePath, 'report_artifacts_archive/')
            || str_starts_with($storagePath, 'reports/')
            || str_starts_with($storagePath, 'private/reports/');
    }
}
