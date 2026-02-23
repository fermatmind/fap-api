<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;

final class ArtifactStore
{
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
