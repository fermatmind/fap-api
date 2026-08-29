<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

final class SeoPrivateDataScanner
{
    public const VERSION = 'seo.private_data_scanner.v1';

    private const SENSITIVE_KEYS = '/(?:^|_)(?:attempt|result|report|order|payment|token|authorization|cookie|password|secret|user|account|invite|recovery|answers?)(?:_|$)/i';

    /** @return array<string, mixed> */
    public function scan(mixed $value): array
    {
        $counts = [];
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
        if ($key !== null && $key !== 'aggregate_result_count' && preg_match(self::SENSITIVE_KEYS, $key) === 1) {
            $counts['sensitive_field'] = ($counts['sensitive_field'] ?? 0) + 1;
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->walk($child, $counts, is_string($childKey) ? $childKey : null);
            }

            return;
        }
        if (! is_string($value) || $value === '') {
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
}
