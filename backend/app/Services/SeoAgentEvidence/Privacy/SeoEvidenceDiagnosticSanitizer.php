<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

use App\Support\Logging\SensitiveDiagnosticRedactor;

final class SeoEvidenceDiagnosticSanitizer
{
    /** @param array<string, int> $statistics @return array<string, mixed> */
    public function diagnostic(string $safeCode, array $statistics = [], ?string $bundleId = null, ?int $bundleVersion = null, ?string $bundleHash = null): array
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $safeCode) !== 1) {
            $safeCode = 'SEO_EVIDENCE_FAILURE';
        }
        $statistics = array_filter($statistics, static fn (mixed $value, mixed $key): bool => is_string($key) && is_int($value), ARRAY_FILTER_USE_BOTH);

        return SensitiveDiagnosticRedactor::redactArray(array_filter([
            'safe_error_code' => $safeCode,
            'bundle_id' => $bundleId,
            'bundle_version' => $bundleVersion,
            'bundle_hash' => preg_match('/^[a-f0-9]{64}$/', (string) $bundleHash) === 1 ? $bundleHash : null,
            'statistics' => $statistics,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
