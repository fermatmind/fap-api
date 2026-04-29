<?php

declare(strict_types=1);

namespace App\Support;

final class CanonicalFrontendUrl
{
    public const APEX_URL = 'https://fermatmind.com';

    /** @var array<string, true> */
    private const OWNED_HOSTS = [
        'fermatmind.com' => true,
        'www.fermatmind.com' => true,
    ];

    public static function fromConfig(): string
    {
        return self::normalizeBaseUrl((string) config('app.frontend_url', config('app.url', '')));
    }

    public static function normalizeBaseUrl(?string $value): string
    {
        $normalized = rtrim(trim((string) $value), '/');

        return self::normalizeAbsoluteUrl($normalized) ?? $normalized;
    }

    public static function normalizeAbsoluteUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = trim($value);
        if ($url === '') {
            return null;
        }

        if (! preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return $url;
        }

        $host = strtolower($parts['host']);
        if (! isset(self::OWNED_HOSTS[$host])) {
            return rtrim($url, '/');
        }

        $path = (string) ($parts['path'] ?? '');
        $path = $path === '/' ? '' : $path;
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return self::APEX_URL.$path.$query.$fragment;
    }

    /**
     * @param  array<string,mixed>  $urls
     * @return array<string,string>
     */
    public static function normalizeUrlMap(array $urls): array
    {
        $normalized = [];

        foreach ($urls as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $url = self::normalizeAbsoluteUrl((string) $value);
            if ($url === null || $url === '') {
                continue;
            }

            $normalized[(string) $key] = $url;
        }

        ksort($normalized);

        return $normalized;
    }

    public static function normalizeNestedUrls(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $nested) {
                $normalized[$key] = self::normalizeNestedUrls($nested);
            }

            return $normalized;
        }

        if (! is_string($value)) {
            return $value;
        }

        return self::normalizeAbsoluteUrl($value) ?? $value;
    }
}
