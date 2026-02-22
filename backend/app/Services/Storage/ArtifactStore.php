<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;

final class ArtifactStore
{
    public function putReportJson(string $scaleCode, string $attemptId, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (! is_string($json)) {
            throw new \RuntimeException('report_json_encode_failed: '.json_last_error_msg());
        }

        Storage::disk('local')->put($this->reportJsonPath($scaleCode, $attemptId), $json);
    }

    public function getReportJson(string $scaleCode, string $attemptId): ?array
    {
        foreach ($this->reportJsonCandidatePaths($scaleCode, $attemptId) as $path) {
            $decoded = $this->readJson($path);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function putPdf(
        string $scaleCode,
        string $attemptId,
        string $manifestHash,
        string $variant,
        string $bytes
    ): string {
        $path = $this->pdfPath($scaleCode, $attemptId, $manifestHash, $variant);
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /**
     * @return array{path:string,binary:string}|null
     */
    public function getPdf(
        string $scaleCode,
        string $attemptId,
        string $manifestHash,
        string $variant
    ): ?array {
        foreach ($this->pdfCandidatePaths($scaleCode, $attemptId, $manifestHash, $variant) as $path) {
            $contents = $this->readBinary($path);
            if ($contents !== null) {
                return [
                    'path' => $path,
                    'binary' => $contents,
                ];
            }
        }

        return null;
    }

    public function reportJsonPath(string $scaleCode, string $attemptId): string
    {
        return sprintf(
            'artifacts/reports/%s/%s/report.json',
            $this->normalizeScaleCode($scaleCode),
            $this->normalizeAttemptId($attemptId)
        );
    }

    public function pdfPath(string $scaleCode, string $attemptId, string $manifestHash, string $variant): string
    {
        return sprintf(
            'artifacts/pdf/%s/%s/%s/report_%s.pdf',
            $this->normalizeScaleCode($scaleCode),
            $this->normalizeAttemptId($attemptId),
            $this->normalizeManifestHash($manifestHash),
            $this->normalizeVariant($variant)
        );
    }

    /**
     * @return list<string>
     */
    private function reportJsonCandidatePaths(string $scaleCode, string $attemptId): array
    {
        $attempt = $this->normalizeAttemptId($attemptId);
        $paths = [
            $this->reportJsonPath($scaleCode, $attemptId),
            "reports/{$attempt}/report.json",
            "private/reports/{$attempt}/report.json",
        ];

        foreach ($this->scaleCandidates($scaleCode) as $scale) {
            $paths[] = "reports/{$scale}/{$attempt}/report.json";
            $paths[] = "private/reports/{$scale}/{$attempt}/report.json";
        }

        return $this->uniquePaths($paths);
    }

    /**
     * @return list<string>
     */
    private function pdfCandidatePaths(
        string $scaleCode,
        string $attemptId,
        string $manifestHash,
        string $variant
    ): array {
        $attempt = $this->normalizeAttemptId($attemptId);
        $hash = $this->normalizeManifestHash($manifestHash);
        $normalizedVariant = $this->normalizeVariant($variant);

        $paths = [
            $this->pdfPath($scaleCode, $attemptId, $manifestHash, $variant),
            "private/reports/big5/{$attempt}/report_{$normalizedVariant}.pdf",
            "reports/big5/{$attempt}/report_{$normalizedVariant}.pdf",
        ];

        foreach ($this->scaleCandidates($scaleCode) as $scale) {
            $paths[] = "private/reports/{$scale}/{$attempt}/{$hash}/report_{$normalizedVariant}.pdf";
            $paths[] = "reports/{$scale}/{$attempt}/{$hash}/report_{$normalizedVariant}.pdf";
        }

        return $this->uniquePaths($paths);
    }

    private function readJson(string $path): ?array
    {
        $contents = $this->readBinary($path);
        if ($contents === null || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function readBinary(string $path): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private function normalizeScaleCode(string $scaleCode): string
    {
        $scale = strtoupper(trim($scaleCode));
        if ($scale === '') {
            $scale = 'UNKNOWN';
        }

        $scale = preg_replace('/[^A-Z0-9_]/', '_', $scale) ?? 'UNKNOWN';

        return $scale !== '' ? $scale : 'UNKNOWN';
    }

    private function normalizeAttemptId(string $attemptId): string
    {
        $attempt = trim($attemptId);
        if ($attempt === '') {
            $attempt = 'unknown_attempt';
        }

        $attempt = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $attempt) ?? 'unknown_attempt';

        return $attempt !== '' ? $attempt : 'unknown_attempt';
    }

    private function normalizeManifestHash(string $manifestHash): string
    {
        $hash = trim($manifestHash);
        if ($hash === '') {
            $hash = 'nohash';
        }

        $hash = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $hash) ?? 'nohash';

        return $hash !== '' ? $hash : 'nohash';
    }

    private function normalizeVariant(string $variant): string
    {
        $normalized = strtolower(trim($variant));

        return in_array($normalized, ['free', 'full'], true) ? $normalized : 'free';
    }

    /**
     * @return list<string>
     */
    private function scaleCandidates(string $scaleCode): array
    {
        $raw = trim($scaleCode);
        $normalized = $this->normalizeScaleCode($raw);
        $rawSanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $raw) ?? '';

        return $this->uniquePaths([
            $normalized,
            $rawSanitized,
            strtoupper($rawSanitized),
            strtolower($rawSanitized),
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function uniquePaths(array $paths): array
    {
        $seen = [];
        $unique = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $unique[] = $path;
        }

        return $unique;
    }
}
