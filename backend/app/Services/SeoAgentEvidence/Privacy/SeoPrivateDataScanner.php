<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

use Normalizer;

final class SeoPrivateDataScanner
{
    public const VERSION = 'seo.private_data_scanner.v2';

    /** @var list<string> */
    private const PRIVATE_FLOW_TERMS = [
        'attempt', 'result', 'report', 'history', 'order', 'payment', 'token',
        'user', 'identity', 'account', 'auth', 'authorization', 'invite', 'recovery',
    ];

    /** @var list<string> */
    private const PII_TERMS = [
        'email', 'phone', 'mobile', 'address', 'password', 'secret', 'cookie',
        'credential', 'answers', 'answer', 'private',
    ];

    /** @var array<string, true> */
    private const SAFE_AGGREGATE_FIELDS = [
        'aggregate_result_count' => true,
        'counts' => true,
        'status_counts' => true,
        'public_visits' => true,
        'public_cta_events' => true,
        'test_starts' => true,
    ];

    /** @return array<string, mixed> */
    public function scan(mixed $value): array
    {
        $counts = [];
        if (! class_exists(Normalizer::class)) {
            return [
                'private_data_present' => true,
                'category_counts' => ['normalization_unavailable' => 1],
                'scanner_version' => self::VERSION,
                'decision' => 'deny',
            ];
        }
        $this->walk($value, $counts, null);
        ksort($counts, SORT_STRING);
        $present = array_sum($counts) > 0;

        return [
            'private_data_present' => $present,
            'category_counts' => $counts,
            'scanner_version' => self::VERSION,
            'decision' => $present ? 'deny' : 'pass',
        ];
    }

    /** @param array<string, int> $counts */
    private function walk(mixed $value, array &$counts, ?string $key): void
    {
        if ($key !== null) {
            if (! mb_check_encoding($key, 'UTF-8')) {
                $counts['invalid_encoding'] = ($counts['invalid_encoding'] ?? 0) + 1;

                return;
            }
            $normalizedKey = $this->normalizeKey($key);
            if (! isset(self::SAFE_AGGREGATE_FIELDS[$normalizedKey])) {
                $tokens = array_values(array_filter(explode('_', $normalizedKey), 'strlen'));
                if (array_intersect($tokens, self::PRIVATE_FLOW_TERMS) !== []) {
                    $counts['private_flow_field'] = ($counts['private_flow_field'] ?? 0) + 1;
                }
                if (array_intersect($tokens, self::PII_TERMS) !== []) {
                    $counts['pii_field'] = ($counts['pii_field'] ?? 0) + 1;
                }
            }
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->walk($child, $counts, is_string($childKey) ? $childKey : null);
            }

            return;
        }
        if (is_object($value)) {
            $counts['non_array_object'] = ($counts['non_array_object'] ?? 0) + 1;

            return;
        }
        if (is_resource($value)) {
            $counts['unsupported_value'] = ($counts['unsupported_value'] ?? 0) + 1;

            return;
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return;
        }
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $counts['invalid_encoding'] = ($counts['invalid_encoding'] ?? 0) + 1;

            return;
        }
        $value = $this->normalizeValue((string) $value);
        if ($value === '') {
            return;
        }
        $patterns = [
            'email' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            'phone' => '/(?<!\d)(?:\+?86[- ]?)?1[3-9]\d{9}(?!\d)|(?<!\d)\+?1[- .]?(?:\d{3})[- .]?\d{3}[- .]?\d{4}(?!\d)/',
            'credential' => '/\b(?:bearer\s+[A-Za-z0-9._~+\/-]+=*|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+|(?:sk|rk|pk)-(?:live|test)-[A-Za-z0-9_-]{8,}|api[_-]?key\s*[:=]|authorization\s*[:=]|cookie\s*[:=])/i',
            'payment_identifier' => '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/',
            'identity_identifier' => '/(?<!\d)\d{17}[\dXx](?!\d)|\b\d{3}-\d{2}-\d{4}\b/',
            'opaque_identifier' => '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b|\b[0-7][0-9A-HJKMNP-TV-Z]{25}\b/i',
            'ip_address' => '/(?<![\w:])(?:\d{1,3}\.){3}\d{1,3}(?![\w:])|(?<![\w:])(?:[a-f0-9]{1,4}:){2,7}[a-f0-9]{0,4}(?![\w:])/i',
            'private_id_prefix' => '/\b(?:attempt|result|report|order|payment|user|account|token)[_-](?:id[_:-]?)?[A-Za-z0-9_-]{4,}\b/i',
        ];
        foreach ($patterns as $category => $pattern) {
            $matches = preg_match_all($pattern, $value);
            if ($matches > 0) {
                $counts[$category] = ($counts[$category] ?? 0) + $matches;
            }
        }
    }

    private function normalizeKey(string $key): string
    {
        $normalized = $this->normalizeValue($key);
        $normalized = preg_replace('/(?<=[\p{Ll}\d])(?=\p{Lu})/u', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '_', $normalized) ?? $normalized;

        return trim(mb_strtolower($normalized, 'UTF-8'), '_');
    }

    private function normalizeValue(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        if (is_string($normalized)) {
            return $normalized;
        }

        return '';
    }
}
