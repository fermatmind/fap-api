<?php

declare(strict_types=1);

namespace App\Services\Storage;

final class ArtifactLedgerClassifier
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function classify(array $candidate): array
    {
        $sourcePath = $this->normalizePath((string) ($candidate['source_path'] ?? $candidate['relative_path'] ?? ''));
        $relativePath = $this->normalizePath((string) ($candidate['relative_path'] ?? $sourcePath));
        $sourceRoot = $this->normalizePath((string) ($candidate['source_root'] ?? ''));
        $attemptId = $this->normalizeText($candidate['attempt_id'] ?? null);
        $scaleCode = $this->normalizeText($candidate['scale_code'] ?? null);
        $slotCode = $this->normalizeText($candidate['slot_code'] ?? null);
        $variant = $this->normalizeText($candidate['variant'] ?? null);
        $manifestHash = $this->normalizeText($candidate['manifest_hash'] ?? null);

        $manualOrTestOwned = $this->isManualOrTestOwned($sourcePath, $relativePath, $sourceRoot);
        $parsed = $this->parseArtifactPath($sourcePath, $relativePath);

        $reasons = [];
        if ($manualOrTestOwned) {
            $reasons[] = 'manual_or_test_owned';
        }

        if (($parsed['legacy_path'] ?? false) === true) {
            $reasons[] = 'legacy_path';
        }

        if ($this->isLegacyAliasScale((string) ($parsed['scale_code'] ?? ''))) {
            $reasons[] = 'scale_alias:'.(string) ($parsed['scale_code'] ?? '');
        }

        if ($this->isNohashManifest((string) ($parsed['manifest_hash'] ?? $manifestHash ?? ''))) {
            $reasons[] = 'manifest_nohash';
        }

        if (($candidate['has_db_row'] ?? false) === true) {
            $reasons[] = 'db_row_present';
        }

        if (($candidate['has_file'] ?? false) === true) {
            $reasons[] = 'file_present';
        }

        if (($candidate['has_archive_proof'] ?? false) === true) {
            $reasons[] = 'archive_proof_present';
        }

        $bucket = $this->bucketForCandidate(
            manualOrTestOwned: $manualOrTestOwned,
            legacyAlias: $this->hasLegacyAliasEvidence($parsed, $sourcePath, $relativePath, $manifestHash),
            hasDbRow: (bool) ($candidate['has_db_row'] ?? false),
            hasFile: (bool) ($candidate['has_file'] ?? false),
            hasArchiveProof: (bool) ($candidate['has_archive_proof'] ?? false)
        );

        return [
            'bucket' => $bucket,
            'reasons' => array_values(array_unique($reasons)),
            'source_root' => $sourceRoot !== '' ? $sourceRoot : null,
            'source_path' => $sourcePath !== '' ? $sourcePath : null,
            'relative_path' => $relativePath !== '' ? $relativePath : null,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode ?? ($parsed['scale_code'] ?? null),
            'slot_code' => $slotCode,
            'variant' => $variant ?? ($parsed['variant'] ?? null),
            'manifest_hash' => $manifestHash ?? ($parsed['manifest_hash'] ?? null),
            'artifact_kind' => $candidate['artifact_kind'] ?? ($parsed['artifact_kind'] ?? null),
            'has_db_row' => (bool) ($candidate['has_db_row'] ?? false),
            'has_file' => (bool) ($candidate['has_file'] ?? false),
            'has_archive_proof' => (bool) ($candidate['has_archive_proof'] ?? false),
            'manual_or_test_owned' => $manualOrTestOwned,
            'legacy_alias' => $this->hasLegacyAliasEvidence($parsed, $sourcePath, $relativePath, $manifestHash),
            'legacy_path' => (bool) ($parsed['legacy_path'] ?? false),
            'parsed' => $parsed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bucketForCandidate(
        bool $manualOrTestOwned,
        bool $legacyAlias,
        bool $hasDbRow,
        bool $hasFile,
        bool $hasArchiveProof
    ): array|string {
        if ($manualOrTestOwned) {
            return 'manual_or_test_owned';
        }

        if ($legacyAlias) {
            return 'alias_or_legacy_path';
        }

        if ($hasDbRow && $hasFile) {
            return 'matched_db_and_file';
        }

        if ($hasDbRow) {
            return 'db_only';
        }

        if ($hasFile) {
            return 'file_only';
        }

        if ($hasArchiveProof) {
            return 'archive_proof_only';
        }

        return 'db_only';
    }

    /**
     * @return array{artifact_kind:?string,scale_code:?string,attempt_id:?string,variant:?string,manifest_hash:?string,legacy_path:bool}
     */
    private function parseArtifactPath(string $sourcePath, string $relativePath): array
    {
        $path = $sourcePath !== '' ? $sourcePath : $relativePath;
        $path = ltrim($this->normalizePath($path), '/');

        if ($path === '') {
            return [
                'artifact_kind' => null,
                'scale_code' => null,
                'attempt_id' => null,
                'variant' => null,
                'manifest_hash' => null,
                'legacy_path' => false,
            ];
        }

        if (preg_match('#(?:^|/)artifacts/reports/([^/]+)/([^/]+)/report\.json$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
                'variant' => 'full',
                'manifest_hash' => null,
                'legacy_path' => false,
            ];
        }

        if (preg_match('#(?:^|/)artifacts/pdf/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
                'variant' => (string) $matches[4],
                'manifest_hash' => (string) $matches[3],
                'legacy_path' => false,
            ];
        }

        if (preg_match('#(?:^|/)(?:private/)?reports/([^/]+)/([^/]+)/report\.json$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
                'variant' => 'full',
                'manifest_hash' => null,
                'legacy_path' => true,
            ];
        }

        if (preg_match('#(?:^|/)(?:private/)?reports/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
                'variant' => (string) $matches[4],
                'manifest_hash' => (string) $matches[3],
                'legacy_path' => true,
            ];
        }

        if (preg_match('#(?:^|/)(?:private/)?reports/([^/]+)/([^/]+)/report_(free|full)\.pdf$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
                'variant' => (string) $matches[3],
                'manifest_hash' => 'nohash',
                'legacy_path' => true,
            ];
        }

        if (preg_match('#(?:^|/)(?:private/)?reports/([^/]+)/report\.json$#', $path, $matches) === 1) {
            return [
                'artifact_kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => null,
                'variant' => 'full',
                'manifest_hash' => null,
                'legacy_path' => true,
            ];
        }

        return [
            'artifact_kind' => null,
            'scale_code' => null,
            'attempt_id' => null,
            'variant' => null,
            'manifest_hash' => null,
            'legacy_path' => false,
        ];
    }

    private function isManualOrTestOwned(string $sourcePath, string $relativePath, string $sourceRoot): bool
    {
        $haystack = strtolower(implode('|', array_filter([$sourcePath, $relativePath, $sourceRoot])));

        return str_contains($haystack, '/content_releases/')
            || str_contains($haystack, '/evidence/')
            || str_contains($haystack, '/testing/');
    }

    private function hasLegacyAliasEvidence(array $parsed, string $sourcePath, string $relativePath, ?string $manifestHash): bool
    {
        if (($parsed['legacy_path'] ?? false) === true) {
            return true;
        }

        $scaleCode = strtoupper(trim((string) ($parsed['scale_code'] ?? '')));
        if ($this->isLegacyAliasScale($scaleCode)) {
            return true;
        }

        return $this->isNohashManifest((string) ($parsed['manifest_hash'] ?? $manifestHash ?? ''))
            || str_contains(strtolower($sourcePath), '/private/reports/')
            || str_contains(strtolower($relativePath), 'reports/big5/')
            || str_contains(strtolower($relativePath), 'reports/big5_ocean/');
    }

    private function isLegacyAliasScale(string $scaleCode): bool
    {
        return in_array($scaleCode, ['BIG5', 'BIG5_OCEAN'], true);
    }

    private function isNohashManifest(string $manifestHash): bool
    {
        return strtolower(trim($manifestHash)) === 'nohash';
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
