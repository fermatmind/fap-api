<?php

declare(strict_types=1);

namespace App\Support;

final class PublicMediaUrlGuard
{
    public const DEFAULT_ASSET_ORIGIN = 'https://assets.fermatmind.com';

    /**
     * @var list<string>
     */
    private const DEFAULT_ALLOWED_MEDIA_HOSTS = [
        'api.fermatmind.com',
        'assets.fermatmind.com',
    ];

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

    /**
     * @var list<string>
     */
    private const JSON_LD_MEDIA_KEYS = [
        'contentUrl',
        'image',
        'logo',
        'thumbnail',
        'thumbnailUrl',
    ];

    public static function sanitizeNullableUrl(mixed $url): ?string
    {
        $normalized = trim((string) $url);

        if ($normalized === '') {
            return null;
        }

        return self::isAllowedPublicMediaUrl($normalized) ? $normalized : null;
    }

    public static function canonicalAssetOrigin(): string
    {
        $origin = trim((string) config('fap.media.asset_origin', self::DEFAULT_ASSET_ORIGIN));
        $origin = rtrim($origin, '/');

        if ($origin === '' || ! preg_match('/^https:\/\//i', $origin)) {
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

        return self::sanitizeNullableUrl(self::canonicalAssetOrigin().$publicPath);
    }

    public static function publicAppStorageUrlForPath(?string $disk, mixed $path): ?string
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

        $origin = rtrim(trim((string) config('app.url', '')), '/');
        if ($origin === '' || ! preg_match('/^https:\/\//i', $origin)) {
            return null;
        }

        return self::sanitizeNullableUrl($origin.$publicPath);
    }

    public static function canonicalMediaUrl(?string $disk, mixed $path, mixed $url): ?string
    {
        if (self::isPrivateOrOpsDisk($disk)) {
            return null;
        }

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

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $keys, true)) {
                $payload[$key] = self::sanitizeNullableUrl($value);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::sanitizeArrayFields($value, $keys) ?? [];
            }
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizeJsonLdImageFields(array $payload): array
    {
        /** @var array<string, mixed> */
        return self::sanitizeJsonLdNode($payload);
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

    public static function isAllowedPublicMediaUrl(?string $url): bool
    {
        $normalized = trim((string) $url);
        if ($normalized === '' || self::isBlockedUrl($normalized)) {
            return false;
        }

        $parts = parse_url($normalized);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return false;
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443) {
            return false;
        }

        if (self::isPrivateOrLocalHost($host)) {
            return false;
        }

        return in_array($host, self::allowedMediaHosts(), true);
    }

    private static function publicPathForDisk(?string $disk, string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $normalizedDisk = strtolower(trim((string) $disk));
        if (self::isPrivateOrOpsDisk($disk)) {
            return '';
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (in_array($normalizedDisk, ['public', 'public_static'], true)) {
            $prefix = trim((string) config('fap.media.public_storage_prefix', '/storage'));
            $prefix = $prefix === '' ? '/storage' : '/'.trim($prefix, '/');

            return $prefix.'/'.ltrim($trimmed, '/');
        }

        return '/'.ltrim($trimmed, '/');
    }

    /**
     * @return list<string>
     */
    private static function allowedMediaHosts(): array
    {
        $hosts = self::DEFAULT_ALLOWED_MEDIA_HOSTS;

        foreach ([self::DEFAULT_ASSET_ORIGIN, self::canonicalAssetOrigin(), (string) config('app.url', '')] as $origin) {
            $host = self::normalizeHost((string) parse_url($origin, PHP_URL_HOST));
            if ($host !== '' && ! self::isPrivateOrLocalHost($host)) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, "[] \t\n\r\0\x0B."));
    }

    private static function isPrivateOrOpsDisk(?string $disk): bool
    {
        return in_array(strtolower(trim((string) $disk)), ['local', 'private', 'ops'], true);
    }

    private static function isPrivateOrLocalHost(string $host): bool
    {
        $normalized = self::normalizeHost($host);
        if ($normalized === '' || $normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
            return true;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP)) {
            return filter_var(
                $normalized,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        return false;
    }

    private static function isJsonLdImageObject(mixed $type): bool
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            if (strcasecmp(trim((string) $candidate), 'ImageObject') === 0) {
                return true;
            }
        }

        return false;
    }

    private static function isJsonLdMediaKey(string $key): bool
    {
        return in_array($key, self::JSON_LD_MEDIA_KEYS, true);
    }

    private static function sanitizeJsonLdMediaValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeNullableUrl($value);
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            $sanitized = [];
            foreach ($value as $item) {
                $media = self::sanitizeJsonLdMediaValue($item);
                if ($media !== null && $media !== []) {
                    $sanitized[] = $media;
                }
            }

            return $sanitized;
        }

        return self::sanitizeJsonLdNode($value);
    }

    private static function sanitizeJsonLdNode(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isImageObject = self::isJsonLdImageObject($value['@type'] ?? null);
        foreach ($value as $key => $nestedValue) {
            $keyName = (string) $key;
            if (self::isJsonLdMediaKey($keyName) || ($isImageObject && in_array($keyName, ['contentUrl', 'url'], true))) {
                $value[$key] = self::sanitizeJsonLdMediaValue($nestedValue);

                continue;
            }

            $value[$key] = self::sanitizeJsonLdNode($nestedValue);
        }

        return $value;
    }
}
