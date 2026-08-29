<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

final class SeoEvidenceDiagnosticSanitizer
{
    /** @var list<string> */
    private const ALLOWED_CATEGORIES = [
        'private_flow_field', 'pii_field', 'non_array_object', 'unsupported_value',
        'invalid_encoding', 'normalization_unavailable', 'email', 'phone', 'credential',
        'payment_identifier', 'identity_identifier', 'opaque_identifier', 'ip_address',
        'private_id_prefix',
    ];

    public function __construct(private readonly SeoPrivateDataScanner $scanner) {}

    /** @param array<string, int> $statistics @return array<string, mixed> */
    public function diagnostic(string $safeCode, array $statistics = [], ?string $bundleId = null, ?int $bundleVersion = null, ?string $bundleHash = null): array
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $safeCode) !== 1) {
            $safeCode = 'SEO_EVIDENCE_FAILURE';
        }
        $categoryCounts = array_filter(
            $statistics,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && in_array($key, self::ALLOWED_CATEGORIES, true)
                && is_int($value) && $value >= 0,
            ARRAY_FILTER_USE_BOTH,
        );
        foreach ($this->scanner->scan([$bundleId, $bundleVersion, $bundleHash])['category_counts'] as $category => $count) {
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + $count;
        }
        ksort($categoryCounts, SORT_STRING);

        return [
            'safe_error_code' => $safeCode,
            'category_counts' => $categoryCounts,
        ];
    }
}
