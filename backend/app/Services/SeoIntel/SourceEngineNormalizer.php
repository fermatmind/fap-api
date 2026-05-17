<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SourceEngineNormalizer
{
    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return [
            'google',
            'baidu',
            'bing_indexnow',
            'llms',
            'direct',
            'paid_google',
            'paid_baidu',
            'unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizeFromPayload(array $payload): string
    {
        $explicit = $this->normalize($payload['source_engine'] ?? null);

        if ($explicit !== 'unknown') {
            return $explicit;
        }

        if ($this->hasNonEmpty($payload, ['gclid', 'gbraid', 'wbraid'])) {
            return 'paid_google';
        }

        $source = $this->lower($payload['utm_source'] ?? null);
        $medium = $this->lower($payload['utm_medium'] ?? null);
        $referrer = $this->lower($payload['referrer'] ?? null);

        if ($source !== null && str_contains($source, 'baidu') && $this->isPaidMedium($medium)) {
            return 'paid_baidu';
        }

        if ($source !== null && str_contains($source, 'google') && $this->isPaidMedium($medium)) {
            return 'paid_google';
        }

        if ($this->containsAny($source, ['google']) || $this->containsAny($referrer, ['google.'])) {
            return 'google';
        }

        if ($this->containsAny($source, ['baidu']) || $this->containsAny($referrer, ['baidu.'])) {
            return 'baidu';
        }

        if ($this->containsAny($source, ['bing']) || $this->containsAny($referrer, ['bing.'])) {
            return 'bing_indexnow';
        }

        if ($this->containsAny($source, ['llms']) || $this->containsAny($referrer, ['llms.txt', 'llms-full'])) {
            return 'llms';
        }

        if (($source === null || $source === '') && ($referrer === null || $referrer === '')) {
            return 'direct';
        }

        return 'unknown';
    }

    public function normalize(mixed $value): string
    {
        $normalized = $this->lower($value);

        if ($normalized === null || $normalized === '') {
            return 'unknown';
        }

        $aliases = [
            'bing' => 'bing_indexnow',
            'indexnow' => 'bing_indexnow',
            'google_ads' => 'paid_google',
            'googleads' => 'paid_google',
            'adwords' => 'paid_google',
            'baidu_paid' => 'paid_baidu',
            'baidu_ads' => 'paid_baidu',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        return in_array($normalized, $this->allowedValues(), true) ? $normalized : 'unknown';
    }

    /**
     * @param  list<string>  $keys
     */
    private function hasNonEmpty(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (($payload[$key] ?? null) !== null && trim((string) $payload[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isPaidMedium(?string $medium): bool
    {
        if ($medium === null) {
            return false;
        }

        return in_array($medium, ['cpc', 'ppc', 'paid', 'paid_search', 'sem'], true);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(?string $haystack, array $needles): bool
    {
        if ($haystack === null) {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function lower(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : strtolower($value);
    }
}
