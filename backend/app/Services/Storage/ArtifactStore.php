<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ArtifactStore
{
    public function __construct(
        private readonly BlobCatalogService $blobCatalogService,
        private readonly ReportArtifactCatalogWriter $reportArtifactCatalogWriter,
        private readonly UnifiedAccessProjectionWriter $unifiedAccessProjectionWriter,
    ) {}

    public function reportCanonicalPath(string $scaleCode, string $attemptId): string
    {
        $scale = $this->sanitizeScaleCode($scaleCode);
        $attempt = $this->sanitizeAttemptId($attemptId);

        return "artifacts/reports/{$scale}/{$attempt}/report.json";
    }

    /**
     * @return list<string>
     */
    public function reportLegacyPaths(string $scaleCode, string $attemptId): array
    {
        $scale = $this->sanitizeScaleCode($scaleCode);
        $attempt = $this->sanitizeAttemptId($attemptId);

        return [
            "reports/{$attempt}/report.json",
            "private/reports/{$attempt}/report.json",
            "reports/{$scale}/{$attempt}/report.json",
            "private/reports/{$scale}/{$attempt}/report.json",
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function putReportJson(string $scaleCode, string $attemptId, array $payload): string
    {
        $path = $this->reportCanonicalPath($scaleCode, $attemptId);
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (! is_string($json)) {
            throw new \RuntimeException('REPORT_JSON_ENCODE_FAILED: '.json_last_error_msg());
        }

        Storage::disk('local')->put($path, $json);
        $this->catalogBlobMetadata(
            $path,
            $json,
            'application/json',
            'canonical_file',
            [
                'artifact_kind' => 'report_json',
                'scale_code' => $scaleCode,
                'attempt_id' => $attemptId,
            ]
        );
        $this->recordReportJsonSidecars($scaleCode, $attemptId, $path, $json, $payload);

        return $path;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getReportJson(string $scaleCode, string $attemptId): ?array
    {
        $paths = array_merge(
            [$this->reportCanonicalPath($scaleCode, $attemptId)],
            $this->reportLegacyPaths($scaleCode, $attemptId)
        );

        foreach ($paths as $path) {
            $raw = $this->get($path);
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function pdfCanonicalPath(string $scaleCode, string $attemptId, string $manifestHash, string $variant): string
    {
        $scale = $this->sanitizeScaleCode($scaleCode);
        $attempt = $this->sanitizeAttemptId($attemptId);
        $hash = $this->sanitizeManifestHash($manifestHash);
        $variant = $this->normalizeVariant($variant);

        return "artifacts/pdf/{$scale}/{$attempt}/{$hash}/report_{$variant}.pdf";
    }

    /**
     * @return list<string>
     */
    public function pdfLegacyPaths(string $scaleCode, string $attemptId, string $manifestHash, string $variant): array
    {
        $scale = $this->sanitizeScaleCode($scaleCode);
        $attempt = $this->sanitizeAttemptId($attemptId);
        $hash = $this->sanitizeManifestHash($manifestHash);
        $variant = $this->normalizeVariant($variant);

        return [
            "private/reports/{$scale}/{$attempt}/{$hash}/report_{$variant}.pdf",
            "private/reports/{$scale}/{$attempt}/report_{$variant}.pdf",
            "reports/{$scale}/{$attempt}/{$hash}/report_{$variant}.pdf",
            "reports/{$scale}/{$attempt}/report_{$variant}.pdf",
            "private/reports/big5/{$attempt}/report_{$variant}.pdf",
            "reports/big5/{$attempt}/report_{$variant}.pdf",
        ];
    }

    public function putPdf(string $path, string $bytes): void
    {
        Storage::disk('local')->put($path, $bytes);
        $context = $this->pdfContextFromPath($path);
        $this->catalogBlobMetadata(
            $path,
            $bytes,
            'application/pdf',
            'canonical_file',
            [
                'artifact_kind' => 'report_pdf',
                'scale_code' => $context['scale_code'] ?? null,
                'attempt_id' => $context['attempt_id'] ?? null,
                'manifest_hash' => $context['manifest_hash'] ?? null,
                'variant' => $context['variant'] ?? null,
            ]
        );

        if (is_array($context)) {
            $this->recordReportPdfSidecars($context, $path, $bytes);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function getFirstFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            $content = $this->get($path);
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return null;
    }

    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }

    public function put(string $path, string $content): void
    {
        Storage::disk('local')->put($path, $content);
    }

    public function get(string $path): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function catalogBlobMetadata(
        string $storagePath,
        string $content,
        string $contentType,
        string $locationKind,
        array $meta = []
    ): void {
        if (! $this->shouldDualWriteBlobCatalog()) {
            return;
        }

        try {
            $hash = hash('sha256', $content);
            $now = now();
            $existing = $this->blobCatalogService->findByHash($hash);

            $blob = $this->blobCatalogService->upsertBlob([
                'hash' => $hash,
                'disk' => 'local',
                'storage_path' => $this->blobCatalogService->storagePathForHash($hash),
                'size_bytes' => strlen($content),
                'content_type' => $contentType,
                'encoding' => 'identity',
                'ref_count' => 0,
                'first_seen_at' => $existing?->first_seen_at ?? $now,
                'last_verified_at' => $now,
            ]);

            $this->blobCatalogService->upsertBlobLocation([
                'blob_hash' => $blob->hash,
                'disk' => 'local',
                'storage_path' => $storagePath,
                'location_kind' => $locationKind,
                'size_bytes' => strlen($content),
                'checksum' => $hash,
                'etag' => $hash,
                'storage_class' => 'local',
                'verified_at' => $now,
                'meta_json' => array_filter($meta, static fn (mixed $value): bool => $value !== null),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ARTIFACT_BLOB_CATALOG_WRITE_FAILED', [
                'storage_path' => $storagePath,
                'content_type' => $contentType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordReportJsonSidecars(
        string $scaleCode,
        string $attemptId,
        string $storagePath,
        string $json,
        array $payload
    ): void {
        try {
            $this->reportArtifactCatalogWriter->recordReportJsonMaterialized(
                $attemptId,
                $scaleCode,
                $storagePath,
                $json,
                $payload
            );

            $this->unifiedAccessProjectionWriter->refreshAttemptProjection(
                $attemptId,
                [
                    'report_state' => 'ready',
                    'reason_code' => 'report_json_materialized',
                ],
                [
                    'source_system' => 'artifact_store',
                    'source_ref' => $storagePath,
                    'actor_type' => 'system',
                    'actor_id' => 'artifact_store',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('REPORT_JSON_SIDE_CAR_WRITE_FAILED', [
                'attempt_id' => $attemptId,
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{scale_code:string,attempt_id:string,manifest_hash:string,variant:string}  $context
     */
    private function recordReportPdfSidecars(array $context, string $storagePath, string $bytes): void
    {
        $attemptId = trim($context['attempt_id']);
        $scaleCode = trim($context['scale_code']);
        $manifestHash = trim($context['manifest_hash']);
        $variant = trim($context['variant']);

        if ($attemptId === '' || $scaleCode === '' || $manifestHash === '' || $variant === '') {
            return;
        }

        try {
            $this->reportArtifactCatalogWriter->recordReportPdfMaterialized(
                $attemptId,
                $scaleCode,
                $variant,
                $manifestHash,
                $storagePath,
                $bytes,
                [
                    'manifest_hash' => $manifestHash,
                    'variant' => $variant,
                ]
            );

            $this->unifiedAccessProjectionWriter->refreshAttemptProjection(
                $attemptId,
                [
                    'pdf_state' => 'ready',
                    'reason_code' => 'report_pdf_materialized',
                ],
                [
                    'source_system' => 'artifact_store',
                    'source_ref' => $storagePath,
                    'actor_type' => 'system',
                    'actor_id' => 'artifact_store',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('REPORT_PDF_SIDE_CAR_WRITE_FAILED', [
                'attempt_id' => $attemptId,
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{scale_code:string,attempt_id:string,manifest_hash:string,variant:string}|null
     */
    private function pdfContextFromPath(string $path): ?array
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (preg_match('#^artifacts/pdf/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#', $path, $matches) !== 1) {
            return null;
        }

        return [
            'scale_code' => (string) $matches[1],
            'attempt_id' => (string) $matches[2],
            'manifest_hash' => (string) $matches[3],
            'variant' => (string) $matches[4],
        ];
    }

    private function shouldDualWriteBlobCatalog(): bool
    {
        return (bool) config('storage_rollout.blob_catalog_enabled')
            && (bool) config('storage_rollout.artifact_dual_write_enabled');
    }

    private function sanitizeScaleCode(string $scaleCode): string
    {
        $scale = strtoupper(trim($scaleCode));
        if ($scale === '') {
            $scale = 'UNKNOWN';
        }

        return preg_replace('/[^A-Z0-9_]/', '_', $scale) ?? 'UNKNOWN';
    }

    private function sanitizeAttemptId(string $attemptId): string
    {
        $attempt = trim($attemptId);
        if ($attempt === '') {
            $attempt = 'unknown_attempt';
        }

        return preg_replace('/[^a-zA-Z0-9\\-_]/', '_', $attempt) ?? 'unknown_attempt';
    }

    private function sanitizeManifestHash(string $manifestHash): string
    {
        $hash = trim($manifestHash);
        if ($hash === '') {
            $hash = 'nohash';
        }

        $hash = preg_replace('/[^a-zA-Z0-9\\-_\\.]/', '', $hash) ?? 'nohash';

        return $hash !== '' ? $hash : 'nohash';
    }

    private function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));

        return in_array($variant, ['free', 'full'], true) ? $variant : 'free';
    }
}
