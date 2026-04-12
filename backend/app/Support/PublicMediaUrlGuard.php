<?php

declare(strict_types=1);

namespace App\Support;

final class PublicMediaUrlGuard
{
    /**
     * @var list<string>
     */
    private const BLOCKED_MARKERS = [
        'myqcloud.com',
        '.qcloud.com',
        'qcloud',
        'cos.',
        'ci-process',
        'imagemogr2',
        'watermark',
    ];

    public static function sanitizeNullableUrl(mixed $url): ?string
    {
        $normalized = trim((string) $url);

        if ($normalized === '') {
            return null;
        }

        return self::isBlockedUrl($normalized) ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    public static function sanitizeArrayFields(?array $payload, array $keys): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $payload[$key] = self::sanitizeNullableUrl($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function sanitizeSeoMeta(array $meta): array
    {
        if (is_array($meta['og'] ?? null)) {
            $meta['og'] = self::sanitizeArrayFields($meta['og'], ['image']) ?? [];
        }

        if (is_array($meta['twitter'] ?? null)) {
            $meta['twitter'] = self::sanitizeArrayFields($meta['twitter'], ['image']) ?? [];
        }

        return $meta;
    }

    public static function isBlockedUrl(?string $url): bool
    {
        $normalized = strtolower(trim((string) $url));
        if ($normalized === '') {
            return false;
        }

        foreach (self::BLOCKED_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
