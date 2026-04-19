<?php

declare(strict_types=1);

namespace App\Support;

final class PublicMediaUrlGuard
{
    public const DEFAULT_ASSET_ORIGIN = 'https://assets.fermatmind.com';

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

    public static function canonicalAssetOrigin(): string
    {
        $origin = trim((string) config('fap.media.asset_origin', self::DEFAULT_ASSET_ORIGIN));
        $origin = rtrim($origin, '/');

        if ($origin === '' || ! preg_match('/^https?:\/\//i', $origin)) {
            return self::DEFAULT_ASSET_ORIGIN;
        }

        return $origin;
    }

    public static function publicMediaUrlForPath(?string $disk, mixed $path): ?string
    {
        $normalizedPath = trim((string) $path);
        if ($normalizedPath === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $normalizedPath)) {
            return self::sanitizeNullableUrl($normalizedPath);
        }

        $publicPath = self::publicPathForDisk($disk, $normalizedPath);
        if ($publicPath === '') {
            return null;
        }

        return self::canonicalAssetOrigin().$publicPath;
    }

    public static function canonicalMediaUrl(?string $disk, mixed $path, mixed $url): ?string
    {
        $fromPath = self::publicMediaUrlForPath($disk, $path);
        if ($fromPath !== null) {
            return $fromPath;
        }

        return self::sanitizeNullableUrl($url);
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

    private static function publicPathForDisk(?string $disk, string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (trim((string) $disk) === 'public') {
            $prefix = trim((string) config('fap.media.public_storage_prefix', '/storage'));
            $prefix = $prefix === '' ? '/storage' : '/'.trim($prefix, '/');

            return $prefix.'/'.ltrim($trimmed, '/');
        }

        return '/'.ltrim($trimmed, '/');
    }
}
