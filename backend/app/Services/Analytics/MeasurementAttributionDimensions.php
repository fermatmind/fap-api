<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class MeasurementAttributionDimensions
{
    /**
     * @param  array<string, mixed>|object  $attempt
     * @return array<string, string>
     */
    public function fromAttempt(array|object $attempt, string $resultState = 'unknown'): array
    {
        $row = is_array($attempt)
            ? $attempt
            : (method_exists($attempt, 'getAttributes') ? (array) $attempt->getAttributes() : (array) $attempt);
        $summary = $this->decodeArray($row['answers_summary_json'] ?? null);
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];

        return [
            'scale_code' => $this->dimension($row['scale_code'] ?? null, 64, true),
            'form_code' => $this->dimension($meta['form_code'] ?? null, 64),
            'locale' => $this->locale($row['locale'] ?? null),
            'entry_surface' => $this->dimension(
                $meta['entry_surface'] ?? $meta['entrypoint'] ?? null,
                128,
            ),
            'source_page_type' => $this->dimension($meta['source_page_type'] ?? null, 64),
            'organic_channel' => $this->organicChannel($meta, $row['channel'] ?? null),
            'device_class' => $this->deviceClass($row['client_platform'] ?? null),
            'result_state' => in_array($resultState, ['ready', 'invalid', 'unknown'], true)
                ? $resultState
                : 'unknown',
        ];
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<string, string>
     */
    public function aggregateDimensions(array $dimensions): array
    {
        return array_intersect_key($dimensions, array_flip([
            'scale_code',
            'form_code',
            'locale',
            'entry_surface',
            'source_page_type',
            'organic_channel',
            'device_class',
        ]));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function organicChannel(array $meta, mixed $storedChannel): string
    {
        $utm = is_array($meta['utm'] ?? null) ? $meta['utm'] : [];
        $source = strtolower($this->safeScalar($utm['source'] ?? null, 128));
        $medium = strtolower($this->safeScalar($utm['medium'] ?? null, 128));
        $channel = strtolower($this->safeScalar($storedChannel, 32));

        if ($medium !== '' && ! in_array($medium, ['organic', 'seo'], true)) {
            return 'not_organic';
        }

        if ($medium === '' && $channel !== '' && ! in_array($channel, ['organic', 'seo', 'web'], true)) {
            return 'not_organic';
        }

        $channelFromCampaign = match (true) {
            str_contains($source, 'google') => 'google',
            str_contains($source, 'baidu') => 'baidu',
            str_contains($source, 'bing') => 'bing',
            str_contains($source, 'sogou') => 'sogou',
            $medium === 'organic', $medium === 'seo', $channel === 'organic', $channel === 'seo' => 'other_search',
            default => 'unknown',
        };

        if ($channelFromCampaign !== 'unknown' || $source !== '' || $medium !== '') {
            return $channelFromCampaign;
        }

        foreach (['campaign', 'term', 'content'] as $field) {
            if ($this->safeScalar($utm[$field] ?? null, 128) !== '') {
                return 'unknown';
            }
        }

        foreach (['gclid', 'msclkid', 'fbclid', 'bd_vid'] as $clickId) {
            if (! empty($meta[$clickId])) {
                return 'unknown';
            }
        }

        foreach (['landing_path', 'current_path'] as $pathField) {
            $path = $meta[$pathField] ?? null;
            if (! is_string($path)) {
                continue;
            }
            $query = parse_url($path, PHP_URL_QUERY);
            if (! is_string($query)) {
                continue;
            }
            foreach (explode('&', $query) as $parameter) {
                $key = strtolower(rawurldecode(explode('=', $parameter, 2)[0]));
                if (in_array($key, ['gclid', 'msclkid', 'fbclid', 'bd_vid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], true)) {
                    return 'unknown';
                }
            }
        }

        return $this->searchEngineFromStoredReferrer($meta['referrer'] ?? null);
    }

    private function searchEngineFromStoredReferrer(mixed $value): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return 'unknown';
        }

        $url = parse_url($value);
        if (! is_array($url) || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass'])) {
            return 'unknown';
        }

        $host = rtrim(strtolower($url['host'] ?? ''), '.');
        foreach ([
            'google' => ['google.com', 'google.com.hk', 'google.co.uk', 'google.co.jp', 'google.cn'],
            'baidu' => ['baidu.com'],
            'bing' => ['bing.com'],
            'sogou' => ['sogou.com'],
        ] as $engine => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $engine;
                }
            }
        }

        return 'unknown';
    }

    private function deviceClass(mixed $value): string
    {
        $platform = strtolower($this->safeScalar($value, 32));
        if ($platform === '') {
            return 'unknown';
        }
        if (str_contains($platform, 'mini') || str_contains($platform, 'wechat')) {
            return 'mini_program';
        }
        if (str_contains($platform, 'ios') || str_contains($platform, 'android') || str_contains($platform, 'mobile')) {
            return 'mobile_app';
        }
        if (str_contains($platform, 'web') || str_contains($platform, 'desktop')) {
            return 'web';
        }

        return 'other';
    }

    private function locale(mixed $value): string
    {
        $locale = strtolower(str_replace('_', '-', $this->safeScalar($value, 16)));

        return match (true) {
            $locale === 'en', str_starts_with($locale, 'en-') => 'en',
            $locale === 'zh', str_starts_with($locale, 'zh-') => 'zh-CN',
            default => 'unknown',
        };
    }

    private function dimension(mixed $value, int $maxLength, bool $uppercase = false): string
    {
        $normalized = $this->safeScalar($value, $maxLength);
        if ($normalized === '' || preg_match('/\A[A-Za-z0-9._:-]+\z/', $normalized) !== 1) {
            return 'unknown';
        }

        return $uppercase ? strtoupper($normalized) : strtolower($normalized);
    }

    private function safeScalar(mixed $value, int $maxLength): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $maxLength, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
