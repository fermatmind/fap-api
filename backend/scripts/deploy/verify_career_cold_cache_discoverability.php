<?php

declare(strict_types=1);

namespace FermatMind\Deploy;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Throwable;

final class CareerColdCacheDiscoverabilityFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerColdCacheDiscoverabilityValidator
{
    public const CONTRACT_VERSION = 'career.cold_cache_discoverability_gate.v1';

    /** @var list<string> */
    private const LOCALES = ['en', 'zh-CN'];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public static function authoritySnapshot(?array $payload, string $artifactSha256): array
    {
        if ($payload === null) {
            self::fail('AUTHORITY_ARTIFACT_MISSING');
        }
        if (($payload['projection_kind'] ?? null) !== 'career_runtime_publish_projection') {
            self::fail('AUTHORITY_PROJECTION_KIND_INVALID');
        }
        if (($payload['projection_version'] ?? null) !== 'career.runtime_publish_projection.v1') {
            self::fail('AUTHORITY_PROJECTION_VERSION_INVALID');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactSha256) !== 1) {
            self::fail('AUTHORITY_ARTIFACT_SHA_INVALID');
        }

        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            self::fail('AUTHORITY_ITEMS_EMPTY');
        }

        $publishedRows = [];
        $seenRows = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                self::fail('AUTHORITY_ITEM_INVALID');
            }

            $slug = self::slug($item);
            $locale = self::locale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                self::fail('AUTHORITY_IDENTITY_INVALID');
            }

            $rowKey = $slug.'|'.$locale;
            if (isset($seenRows[$rowKey])) {
                self::fail('AUTHORITY_DUPLICATE_SLUG_LOCALE');
            }
            $seenRows[$rowKey] = true;

            if (! self::isPublishedDetailItem($item)) {
                continue;
            }
            foreach (['dataset_visible', 'search_visible'] as $field) {
                if (($item[$field] ?? false) !== true) {
                    self::fail('AUTHORITY_PUBLISHED_SURFACE_FLAGS_MISALIGNED');
                }
            }
            $publishedRows[$locale][$slug] = true;
        }

        return self::snapshotFromLocaleMaps($publishedRows, 'AUTHORITY');
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function runtimeSnapshot(array $items): array
    {
        $publishedRows = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! self::isPublishedDetailItem($item)) {
                continue;
            }

            $slug = self::slug($item);
            $locale = self::locale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                self::fail('RUNTIME_IDENTITY_INVALID');
            }
            $publishedRows[$locale][$slug] = true;
        }

        return self::snapshotFromLocaleMaps($publishedRows, 'RUNTIME');
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  callable(string, string): bool  $allows
     * @return array<string, mixed>
     */
    public static function discoverabilitySnapshot(array $items, callable $allows): array
    {
        $releasedRows = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! self::isPublishedDetailItem($item)) {
                continue;
            }

            $slug = self::slug($item);
            $locale = self::locale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                self::fail('DISCOVERABILITY_IDENTITY_INVALID');
            }
            if ($allows($slug, $locale)) {
                $releasedRows[$locale][$slug] = true;
            }
        }

        return self::snapshotFromLocaleMaps($releasedRows, 'DISCOVERABILITY', true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function jobIndexSnapshot(array $payload, string $locale): array
    {
        $map = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slug = self::slug($item);
            if ($slug !== '') {
                $map[$locale][$slug] = true;
            }
        }

        return self::singleLocaleSnapshot($map, $locale);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function directorySnapshot(array $payload, string $locale): array
    {
        $map = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)
                || ($item['indexable'] ?? false) !== true
                || ($item['detail_ready'] ?? false) !== true) {
                continue;
            }
            $slug = self::slug($item);
            if ($slug !== '') {
                $map[$locale][$slug] = true;
            }
        }

        return self::singleLocaleSnapshot($map, $locale);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sitemapSnapshot(array $payload): array
    {
        if (($payload['ok'] ?? false) !== true || ($payload['source'] ?? null) !== 'backend_sitemap_generator') {
            self::fail('SITEMAP_SOURCE_NOT_AUTHORITATIVE');
        }

        $map = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $loc = is_array($item) ? trim((string) ($item['loc'] ?? '')) : '';
            $path = (string) (parse_url($loc, PHP_URL_PATH) ?? '');
            if (preg_match('#^/(en|zh)/career/jobs/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#D', $path, $matches) !== 1) {
                continue;
            }
            $locale = $matches[1] === 'zh' ? 'zh-CN' : 'en';
            $slug = $matches[2];
            if (isset($map[$locale][$slug])) {
                self::fail('SITEMAP_DUPLICATE_CAREER_URL');
            }
            $map[$locale][$slug] = true;
        }

        return self::snapshotFromLocaleMaps($map, 'SITEMAP', true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function validate(string $phase, array $snapshot): array
    {
        if (! in_array($phase, ['authority', 'pre_sitemap', 'post_sitemap'], true)) {
            self::fail('PHASE_INVALID');
        }

        $authority = self::requiredSnapshot($snapshot, 'authority');
        $runtime = self::requiredSnapshot($snapshot, 'runtime');
        self::assertSameSnapshot($authority, $runtime, 'RUNTIME_AUTHORITY_MISMATCH');

        if ($phase !== 'authority') {
            $coverage = (array) ($snapshot['coverage'] ?? []);
            $expectedRows = (int) ($authority['row_count'] ?? 0);
            if (($coverage['status'] ?? null) !== 'ready'
                || (int) ($coverage['expected_target_count'] ?? -1) !== $expectedRows
                || (int) ($coverage['eligible_target_count'] ?? -1) !== $expectedRows
                || (int) ($coverage['covered_target_count'] ?? -1) !== $expectedRows
                || (int) ($coverage['excluded_count'] ?? -1) !== 0) {
                self::fail('DETAIL_CACHE_COVERAGE_INCOMPLETE');
            }

            foreach (self::LOCALES as $locale) {
                self::assertSameLocaleSnapshot(
                    $authority,
                    self::requiredSnapshot($snapshot, 'jobs_'.$locale),
                    $locale,
                    'JOB_INDEX_AUTHORITY_MISMATCH',
                );
                self::assertSameLocaleSnapshot(
                    $authority,
                    self::requiredSnapshot($snapshot, 'directory_'.$locale),
                    $locale,
                    'DIRECTORY_AUTHORITY_MISMATCH',
                );
            }
        }

        if ($phase === 'post_sitemap') {
            self::assertSameSnapshot(
                self::requiredSnapshot($snapshot, 'discoverability'),
                self::requiredSnapshot($snapshot, 'sitemap'),
                'SITEMAP_DISCOVERABILITY_MISMATCH',
            );
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'pass',
            'phase' => $phase,
            'authority_artifact_sha256' => (string) ($snapshot['authority_artifact_sha256'] ?? ''),
            'cohort' => self::safeSummary($authority),
        ];
    }

    /** @param array<string, mixed> $item */
    private static function isPublishedDetailItem(array $item): bool
    {
        $state = (string) (
            $item['runtime_publish_state']
            ?? $item['runtime_state']
            ?? $item['projection_state']
            ?? $item['state']
            ?? ''
        );

        return $state === 'published'
            && ($item['detail_route_enabled'] ?? false) === true
            && ($item['robots_indexable'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true;
    }

    /** @param array<string, mixed> $item */
    private static function slug(array $item): string
    {
        $slug = strtolower(trim((string) (
            $item['slug']
            ?? $item['canonical_slug']
            ?? ($item['identity']['canonical_slug'] ?? '')
        )));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : '';
    }

    private static function locale(string $locale): ?string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us' => 'en',
            'zh', 'zh-cn' => 'zh-CN',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, bool>>  $maps
     * @return array<string, mixed>
     */
    private static function snapshotFromLocaleMaps(array $maps, string $safePrefix, bool $allowEmpty = false): array
    {
        $localeRows = [];
        $slugSets = [];
        foreach (self::LOCALES as $locale) {
            $slugs = array_keys((array) ($maps[$locale] ?? []));
            sort($slugs, SORT_STRING);
            if ($slugs === [] && ! $allowEmpty) {
                self::fail($safePrefix.'_LOCALE_EMPTY');
            }
            $localeRows[$locale] = array_map(
                static fn (string $slug): string => $slug.'|'.$locale,
                $slugs,
            );
            $slugSets[$locale] = $slugs;
        }

        if ($slugSets['en'] !== $slugSets['zh-CN']) {
            self::fail($safePrefix.'_BILINGUAL_SET_MISMATCH');
        }

        $rows = array_merge($localeRows['en'], $localeRows['zh-CN']);
        sort($rows, SORT_STRING);

        return [
            'slug_count' => count($slugSets['en']),
            'row_count' => count($rows),
            'slug_set_sha256' => self::setHash($slugSets['en']),
            'row_set_sha256' => self::setHash($rows),
            'locales' => [
                'en' => [
                    'count' => count($slugSets['en']),
                    'set_sha256' => self::setHash($slugSets['en']),
                ],
                'zh-CN' => [
                    'count' => count($slugSets['zh-CN']),
                    'set_sha256' => self::setHash($slugSets['zh-CN']),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array<string, bool>>  $map
     * @return array<string, mixed>
     */
    private static function singleLocaleSnapshot(array $map, string $locale): array
    {
        $slugs = array_keys((array) ($map[$locale] ?? []));
        sort($slugs, SORT_STRING);

        return [
            'locales' => [
                $locale => [
                    'count' => count($slugs),
                    'set_sha256' => self::setHash($slugs),
                ],
            ],
        ];
    }

    /** @param list<string> $items */
    private static function setHash(array $items): string
    {
        return hash('sha256', implode("\n", $items)."\n");
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function requiredSnapshot(array $snapshot, string $key): array
    {
        $value = $snapshot[$key] ?? null;
        if (! is_array($value)) {
            self::fail('SNAPSHOT_MISSING_'.strtoupper(str_replace('-', '_', $key)));
        }

        return $value;
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private static function assertSameSnapshot(array $expected, array $actual, string $failure): void
    {
        if ((int) ($expected['slug_count'] ?? -1) !== (int) ($actual['slug_count'] ?? -2)
            || (int) ($expected['row_count'] ?? -1) !== (int) ($actual['row_count'] ?? -2)
            || ! hash_equals((string) ($expected['slug_set_sha256'] ?? ''), (string) ($actual['slug_set_sha256'] ?? 'x'))
            || ! hash_equals((string) ($expected['row_set_sha256'] ?? ''), (string) ($actual['row_set_sha256'] ?? 'x'))) {
            self::fail($failure);
        }
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private static function assertSameLocaleSnapshot(array $expected, array $actual, string $locale, string $failure): void
    {
        $expectedLocale = (array) (($expected['locales'] ?? [])[$locale] ?? []);
        $actualLocale = (array) (($actual['locales'] ?? [])[$locale] ?? []);
        if ((int) ($expectedLocale['count'] ?? -1) !== (int) ($actualLocale['count'] ?? -2)
            || ! hash_equals((string) ($expectedLocale['set_sha256'] ?? ''), (string) ($actualLocale['set_sha256'] ?? 'x'))) {
            self::fail($failure);
        }
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private static function safeSummary(array $snapshot): array
    {
        return [
            'slug_count' => (int) ($snapshot['slug_count'] ?? 0),
            'locale_row_count' => (int) ($snapshot['row_count'] ?? 0),
            'slug_set_sha256' => (string) ($snapshot['slug_set_sha256'] ?? ''),
            'locale_row_set_sha256' => (string) ($snapshot['row_set_sha256'] ?? ''),
        ];
    }

    private static function fail(string $safeCode): never
    {
        throw new CareerColdCacheDiscoverabilityFailure($safeCode);
    }
}

final class CareerColdCacheDiscoverabilityRunner
{
    public static function main(array $argv): int
    {
        $phase = trim((string) ($argv[1] ?? ''));

        try {
            $app = self::bootstrapApplication();
            $artifact = self::activeProjectionArtifact($app);
            $authority = CareerColdCacheDiscoverabilityValidator::authoritySnapshot(
                $artifact['payload'],
                $artifact['sha256'],
            );
            $runtimeProjection = $app->make('App\\Domain\\Career\\Publish\\CareerRuntimePublishProjectionLookup');
            $runtimeItems = $runtimeProjection->jobDetailCoverageItems(['en', 'zh-CN']);
            $snapshot = [
                'authority_artifact_sha256' => $artifact['sha256'],
                'authority' => $authority,
                'runtime' => CareerColdCacheDiscoverabilityValidator::runtimeSnapshot($runtimeItems),
            ];

            if ($phase !== 'authority') {
                $coverage = $app->make('App\\Services\\Career\\CareerJobDetailCacheCoverageService');
                $cache = $app->make('App\\Services\\Career\\PublicCareerAuthorityResponseCache');
                $snapshot['coverage'] = (array) (($coverage->inspect(['en', 'zh-CN'], 0)['report'] ?? []));
                foreach (['en', 'zh-CN'] as $locale) {
                    $snapshot['jobs_'.$locale] = CareerColdCacheDiscoverabilityValidator::jobIndexSnapshot(
                        $cache->jobIndexPayload($locale),
                        $locale,
                    );
                    $snapshot['directory_'.$locale] = CareerColdCacheDiscoverabilityValidator::directorySnapshot(
                        $cache->directoryReadModelPayload($locale),
                        $locale,
                    );
                }
            }

            if ($phase === 'post_sitemap') {
                $discoverabilityGate = $app->make('App\\Domain\\Career\\Publish\\Career1046DiscoverabilityReleaseGate');
                $snapshot['discoverability'] = CareerColdCacheDiscoverabilityValidator::discoverabilitySnapshot(
                    $runtimeItems,
                    static fn (string $slug, string $locale): bool => $discoverabilityGate->allows($slug, $locale),
                );
                $cacheFacade = 'Illuminate\\Support\\Facades\\Cache';
                $sitemapController = 'App\\Http\\Controllers\\API\\V0_5\\SEO\\SitemapSourceController';
                $payload = $cacheFacade::get($sitemapController::CACHE_KEY_FRESH);
                $snapshot['sitemap'] = CareerColdCacheDiscoverabilityValidator::sitemapSnapshot(
                    is_array($payload) ? $payload : [],
                );
            }

            self::emit(CareerColdCacheDiscoverabilityValidator::validate($phase, $snapshot));

            return 0;
        } catch (CareerColdCacheDiscoverabilityFailure $failure) {
            self::emit(self::failureReceipt($phase, $failure->safeCode));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt($phase, 'UNEXPECTED_GATE_FAILURE'));

            return 1;
        }
    }

    /** @return array{payload: array<string, mixed>, sha256: string} */
    private static function activeProjectionArtifact(object $app): array
    {
        try {
            $loaded = $app->make('App\\Domain\\Career\\Publish\\CareerGenerationAuthorityLoader')->loadStrict();
        } catch (Throwable) {
            throw new CareerColdCacheDiscoverabilityFailure('ACTIVE_GENERATION_AUTHORITY_INVALID');
        }

        $projection = $loaded['projection'] ?? null;
        $sha256 = $loaded['pointer']['artifacts']['projection']['sha256'] ?? null;
        if (! is_array($projection) || ! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new CareerColdCacheDiscoverabilityFailure('ACTIVE_GENERATION_AUTHORITY_INVALID');
        }

        return [
            'payload' => $projection,
            'sha256' => $sha256,
        ];
    }

    private static function bootstrapApplication(): object
    {
        $backend = dirname(__DIR__, 2);
        require_once $backend.'/vendor/autoload.php';
        $app = require $backend.'/bootstrap/app.php';
        if (! is_object($app) || ! method_exists($app, 'make')) {
            throw new CareerColdCacheDiscoverabilityFailure('APPLICATION_BOOTSTRAP_INVALID');
        }
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** @return array<string, string> */
    private static function failureReceipt(string $phase, string $safeCode): array
    {
        return [
            'contract_version' => CareerColdCacheDiscoverabilityValidator::CONTRACT_VERSION,
            'status' => 'failed',
            'phase' => in_array($phase, ['authority', 'pre_sitemap', 'post_sitemap'], true) ? $phase : 'invalid',
            'error_code' => $safeCode,
        ];
    }

    /** @param array<string, mixed> $receipt */
    private static function emit(array $receipt): void
    {
        fwrite(STDOUT, (string) json_encode(
            $receipt,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }
}

if (getenv('FM_CAREER_COLD_CACHE_GATE_EXECUTE') === '1') {
    exit(CareerColdCacheDiscoverabilityRunner::main($argv));
}
