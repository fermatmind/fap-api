<?php

declare(strict_types=1);

namespace App\Support;

final class CacheKeys
{
    private const PREFIX = 'fap';

    public static function versionTag(): string
    {
        $appVersion = trim((string) config('app.version', ''));
        if ($appVersion === '') {
            $appVersion = trim((string) \App\Support\RuntimeConfig::value('APP_VERSION', ''));
        }
        if ($appVersion === '') {
            $appVersion = 'dev';
        }

        $cachePrefix = trim((string) config('cache.prefix', ''));
        if ($cachePrefix === '') {
            $cachePrefix = trim((string) \App\Support\RuntimeConfig::value('CACHE_PREFIX', ''));
        }

        return $cachePrefix === '' ? $appVersion : $appVersion.':'.$cachePrefix;
    }

    public static function packsIndex(): string
    {
        return self::base().':content_packs:index';
    }

    public static function packManifest(string $packId, string $dirVersion): string
    {
        $packId = trim($packId);
        $dirVersion = trim($dirVersion);

        return self::base().':content_packs:manifest:'.$packId.':'.$dirVersion;
    }

    public static function packQuestions(string $packId, string $dirVersion): string
    {
        $packId = trim($packId);
        $dirVersion = trim($dirVersion);

        return self::base().':content_packs:questions:'.$packId.':'.$dirVersion;
    }

    public static function mbtiLookup(int $orgId, string $requestedSlug, string $locale, bool $allowAlias): string
    {
        return self::base().':mbti:lookup'
            .':org='.self::normalizeSegment((string) $orgId)
            .':slug='.self::normalizeSegment($requestedSlug)
            .':locale='.self::normalizeSegment($locale)
            .':alias='.(int) $allowAlias;
    }

    public static function mbtiQuestions(
        int $orgId,
        string $packId,
        string $dirVersion,
        string $resolvedFormCode,
        string $locale,
        string $region,
        ?string $assetsBaseUrlOverride = null,
    ): string {
        $assetsMarker = $assetsBaseUrlOverride === null || trim($assetsBaseUrlOverride) === ''
            ? 'default'
            : substr(hash('sha256', trim($assetsBaseUrlOverride)), 0, 12);

        return self::base().':mbti:questions'
            .':org='.self::normalizeSegment((string) $orgId)
            .':pack='.self::normalizeSegment($packId)
            .':dir='.self::normalizeSegment($dirVersion)
            .':form='.self::normalizeSegment($resolvedFormCode)
            .':locale='.self::normalizeSegment($locale)
            .':region='.self::normalizeSegment($region)
            .':assets='.$assetsMarker;
    }

    public static function contentAsset(string $packPath, string $relPath): string
    {
        $packPath = trim($packPath);
        $relPath = trim($relPath);

        return self::base().':asset:'.$packPath.':'.$relPath;
    }

    public static function scaleRegistryActive(int $orgId): string
    {
        return self::base().':scale_registry:active:'.$orgId;
    }

    public static function scaleRegistryByCode(int $orgId, string $code): string
    {
        $code = trim($code);

        return self::base().':scale_registry:code:'.$orgId.':'.$code;
    }

    public static function scaleRegistryBySlug(int $orgId, string $slug): string
    {
        $slug = trim($slug);

        return self::base().':scale_registry:slug:'.$orgId.':'.$slug;
    }

    private static function base(): string
    {
        return self::PREFIX.':v='.self::versionTag();
    }

    private static function normalizeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'na';
        }

        $normalized = preg_replace('/[^a-z0-9._-]+/i', '_', $value);

        return $normalized !== null && $normalized !== '' ? strtolower($normalized) : 'na';
    }
}
