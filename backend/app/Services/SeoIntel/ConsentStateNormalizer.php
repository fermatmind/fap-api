<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class ConsentStateNormalizer
{
    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return [
            'granted',
            'denied',
            'unknown',
            'not_applicable',
        ];
    }

    public function normalize(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 'granted';
        }

        if ($value === false || $value === 0 || $value === '0') {
            return 'denied';
        }

        $normalized = strtolower(trim((string) ($value ?? 'unknown')));

        $aliases = [
            'allow' => 'granted',
            'allowed' => 'granted',
            'yes' => 'granted',
            'reject' => 'denied',
            'rejected' => 'denied',
            'no' => 'denied',
            'n/a' => 'not_applicable',
            'na' => 'not_applicable',
            'none' => 'not_applicable',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        return in_array($normalized, $this->allowedValues(), true) ? $normalized : 'unknown';
    }
}
