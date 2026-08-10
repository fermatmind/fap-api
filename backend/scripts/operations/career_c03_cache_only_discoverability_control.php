<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class CareerC03ControlFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerC03CacheOnlyDiscoverabilityControl
{
    public const CONTRACT_VERSION = 'career.c03.cache_only_discoverability_control.v1';

    private const LOCALES = ['en', 'zh-CN'];

    private const MIGRATION = '2026_08_10_060000_create_public_topic_edges_table';

    private const SEO_CACHE_KEYS = [
        'seo:sitemap-source:v1:fresh',
        'seo:sitemap-source:v1:stale',
        'seo:sitemap-source:warm-fingerprint:v1',
        'seo:sitemap:xml:v6',
        'seo:sitemap:etag:v6',
        'seo:llms-txt:v1:body',
        'seo:llms-full-txt:v1:body',
    ];

    private const BASE_REVALIDATION_PATHS = [
        '/en/career/jobs',
        '/zh/career/jobs',
        '/sitemap.xml',
        '/llms.txt',
        '/llms-full.txt',
    ];

    private const PRIVATE_PATH_PATTERN = '#^(?:(?:/(?:en|zh))?/(?:attempts?|results?|reports?|orders?|share|pay|payment|history)(?:/|$)|/(?:en|zh)/tests/[^/]+/take(?:/|$))#iD';

    public static function main(array $argv): int
    {
        $mode = trim((string) ($argv[1] ?? ''));

        try {
            return match ($mode) {
                'inspect' => self::runInspect(),
                'public-verify' => self::runPublicVerify(array_slice($argv, 2)),
                'apply' => self::runApply(),
                'rollback' => self::runRollback(),
                default => throw new CareerC03ControlFailure('MODE_INVALID'),
            };
        } catch (CareerC03ControlFailure $failure) {
            self::emit(self::failureReceipt($mode, $failure->safeCode));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE'));

            return 1;
        }
    }

    private static function runInspect(): int
    {
        $app = self::bootstrapApplication();
        self::emit(self::inspect($app));

        return 0;
    }

    /** @param list<string> $paths */
    private static function runPublicVerify(array $paths): int
    {
        if (count($paths) !== 10) {
            throw new CareerC03ControlFailure('PUBLIC_VERIFY_INPUT_COUNT_INVALID');
        }

        [$inspectPath, $jobsEnPath, $jobsZhPath, $directoryEnPath, $directoryZhPath,
            $sitemapSourcePath, $sitemapXmlPath, $llmsPath, $llmsFullPath, $detailStatusPath] = $paths;
        $inspection = self::jsonFile($inspectPath, 'INSPECTION_INPUT_INVALID');
        $expectedRows = self::stringList($inspection['expected_rows'] ?? null, 'EXPECTED_ROWS_INVALID');
        $expected = self::snapshotFromRows($expectedRows, 'EXPECTED');

        $actual = [
            'jobs' => self::surfaceSnapshotFromRows(array_merge(
                self::rowsFromPayload(self::jsonFile($jobsEnPath, 'JOBS_EN_INVALID'), 'en', false),
                self::rowsFromPayload(self::jsonFile($jobsZhPath, 'JOBS_ZH_INVALID'), 'zh-CN', false),
            ), 'JOBS'),
            'directory' => self::surfaceSnapshotFromRows(array_merge(
                self::rowsFromPayload(self::jsonFile($directoryEnPath, 'DIRECTORY_EN_INVALID'), 'en', true),
                self::rowsFromPayload(self::jsonFile($directoryZhPath, 'DIRECTORY_ZH_INVALID'), 'zh-CN', true),
            ), 'DIRECTORY'),
            'sitemap_source' => self::snapshotFromSitemapJson(self::jsonFile($sitemapSourcePath, 'SITEMAP_SOURCE_INVALID')),
            'sitemap' => self::snapshotFromTextFile($sitemapXmlPath),
            'llms' => self::snapshotFromTextFile($llmsPath),
            'llms_full' => self::snapshotFromTextFile($llmsFullPath),
        ];

        $mismatches = [];
        foreach ($actual as $surface => $snapshot) {
            if (! self::sameSnapshot($expected, $snapshot)) {
                $mismatches[] = $surface;
            }
        }

        $detail = self::detailStatus($detailStatusPath, (array) ($inspection['expected_urls'] ?? []));
        $nonCareer = [
            'sitemap_source' => self::nonCareerHashFromSitemapJson(self::jsonFile($sitemapSourcePath, 'SITEMAP_SOURCE_INVALID')),
            'sitemap' => self::nonCareerHashFromTextFile($sitemapXmlPath),
            'llms' => self::nonCareerHashFromTextFile($llmsPath),
            'llms_full' => self::nonCareerHashFromTextFile($llmsFullPath),
        ];
        $privateLeakCount = 0;
        foreach ([$sitemapXmlPath, $llmsPath, $llmsFullPath] as $path) {
            foreach (self::urlsFromText(self::fileBytes($path, 'PUBLIC_TEXT_INPUT_INVALID')) as $url) {
                $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?? '');
                $privateLeakCount += preg_match(self::PRIVATE_PATH_PATTERN, $urlPath) === 1 ? 1 : 0;
            }
        }

        $converged = $mismatches === []
            && $detail['timeout_count'] === 0
            && $detail['server_error_count'] === 0
            && $detail['non_200_count'] === 0
            && $privateLeakCount === 0;

        self::emit([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'public-verify',
            'status' => $converged ? 'PASS_PUBLIC_CONVERGED' : 'HOLD_PUBLIC_DRIFT',
            'converged' => $converged,
            'expected' => self::safeSnapshot($expected),
            'surface_mismatches' => $mismatches,
            'detail_readback' => $detail,
            'non_career_url_set_sha256' => $nonCareer,
            'private_path_leak_count' => $privateLeakCount,
            'cache_write_count' => 0,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'search_submission_count' => 0,
        ]);

        return $converged ? 0 : 2;
    }

    private static function runApply(): int
    {
        self::assertApplyAuthorization();
        $app = self::bootstrapApplication();
        $before = self::inspect($app);
        if (($before['job_index_converged'] ?? false) !== true) {
            throw new CareerC03ControlFailure('JOB_INDEX_DRIFT_NOT_RECOVERABLE');
        }

        $repairable = (int) ($before['coverage']['missing_count'] ?? 0)
            + (int) ($before['coverage']['broken_count'] ?? 0);
        if ($repairable > 250) {
            throw new CareerC03ControlFailure('DETAIL_REPAIR_LIMIT_EXCEEDED');
        }

        $backupPath = self::backupPath();
        $backup = self::createBackup($before);
        self::writeBackup($backupPath, $backup);
        $backupSha256 = hash_file('sha256', $backupPath);
        if (! is_string($backupSha256)) {
            throw new CareerC03ControlFailure('BACKUP_HASH_FAILED');
        }

        $paths = self::BASE_REVALIDATION_PATHS;
        foreach ((array) ($before['repair_targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }
            $locale = ($target['locale'] ?? null) === 'zh-CN' ? 'zh' : 'en';
            $slug = (string) ($target['slug'] ?? '');
            if (self::validSlug($slug)) {
                $paths[] = sprintf('/%s/career/jobs/%s', $locale, $slug);
            }
        }
        $paths = array_values(array_unique($paths));

        try {
            /** @var Kernel $kernel */
            $kernel = $app->make(Kernel::class);
            if ($repairable > 0) {
                $exit = $kernel->call('career:verify-job-detail-cache-coverage', [
                    '--repair-missing-sync' => true,
                    '--locales' => 'en,zh-CN',
                    '--minimum-targets' => (string) ($before['authority']['row_count'] ?? 0),
                    '--maximum-sync-repairs' => '250',
                    '--confirm-production-write' => true,
                    '--json' => true,
                    '--no-interaction' => true,
                    '--no-ansi' => true,
                ]);
                if ($exit !== 0) {
                    throw new CareerC03ControlFailure('DETAIL_REPAIR_FAILED');
                }
            }

            $directoryExit = $kernel->call('career:warm-public-authority-cache', [
                '--directory-only' => true,
                '--json' => true,
                '--no-interaction' => true,
                '--no-ansi' => true,
            ]);
            if ($directoryExit !== 0) {
                throw new CareerC03ControlFailure('DIRECTORY_REBUILD_FAILED');
            }

            foreach (self::SEO_CACHE_KEYS as $key) {
                Cache::forget($key);
            }
            $sitemapExit = $kernel->call('seo:warm-sitemap-source-cache', [
                '--refresh-if-changed' => true,
                '--json' => true,
                '--no-interaction' => true,
                '--no-ansi' => true,
            ]);
            if ($sitemapExit !== 0) {
                throw new CareerC03ControlFailure('SITEMAP_SOURCE_REBUILD_FAILED');
            }

            self::revalidateFrontend($paths);
            $after = self::inspect($app);
            if (($after['internal_converged'] ?? false) !== true) {
                throw new CareerC03ControlFailure('POST_APPLY_INTERNAL_DRIFT');
            }

            self::emit([
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'apply',
                'status' => 'PASS_CACHE_APPLY_INTERNAL',
                'backup_sha256' => $backupSha256,
                'backup_state_sha256' => (string) ($backup['state_sha256'] ?? ''),
                'authority_artifact_sha256' => (string) ($after['authority_artifact_sha256'] ?? ''),
                'cohort' => self::safeSnapshot((array) ($after['authority'] ?? [])),
                'detail_repair_target_count' => $repairable,
                'directory_locale_count' => 2,
                'backend_cache_key_count' => count(self::SEO_CACHE_KEYS),
                'frontend_revalidation_path_count' => count($paths),
                'database_write_count' => 0,
                'publication_write_count' => 0,
                'indexability_write_count' => 0,
                'deploy_count' => 0,
                'migration_count' => 0,
                'process_restart_count' => 0,
                'queue_reload_count' => 0,
                'search_submission_count' => 0,
            ]);

            return 0;
        } catch (Throwable $throwable) {
            $restored = self::restoreBackup($backupPath);
            try {
                self::revalidateFrontend($paths);
            } catch (Throwable) {
                $restored = false;
            }
            self::emit([
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'apply',
                'status' => $restored ? 'HOLD_APPLY_ROLLED_BACK' : 'HOLD_ROLLBACK_INCOMPLETE',
                'safe_failure_code' => $throwable instanceof CareerC03ControlFailure
                    ? $throwable->safeCode
                    : 'APPLY_UNEXPECTED_FAILURE',
                'backup_sha256' => $backupSha256,
                'rollback_verified' => $restored,
                'automatic_retry_allowed' => false,
            ]);

            return 1;
        }
    }

    private static function runRollback(): int
    {
        self::assertApplyAuthorization();
        $restored = self::restoreBackup(self::backupPath());
        try {
            self::revalidateFrontend(self::BASE_REVALIDATION_PATHS);
        } catch (Throwable) {
            $restored = false;
        }
        self::emit([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'rollback',
            'status' => $restored ? 'PASS_ROLLBACK_VERIFIED' : 'HOLD_ROLLBACK_INCOMPLETE',
            'rollback_verified' => $restored,
            'automatic_retry_allowed' => false,
        ]);

        return $restored ? 0 : 1;
    }

    /** @return array<string, mixed> */
    private static function inspect(object $app): array
    {
        $artifact = self::latestProjectionArtifact();
        $expectedSha = trim((string) getenv('CAREER_C03_EXPECTED_AUTHORITY_SHA256'));
        if ($expectedSha !== '' && ! hash_equals($expectedSha, $artifact['sha256'])) {
            throw new CareerC03ControlFailure('AUTHORITY_SHA_DRIFT');
        }

        $authority = self::authoritySnapshot($artifact['payload']);
        $runtimeProjection = $app->make('App\\Domain\\Career\\Publish\\CareerRuntimePublishProjectionLookup');
        $runtime = self::runtimeSnapshot($runtimeProjection->jobDetailCoverageItems(self::LOCALES));
        $coverageService = $app->make('App\\Services\\Career\\CareerJobDetailCacheCoverageService');
        $coverageInspection = $coverageService->inspect(self::LOCALES, 0);
        $coverage = (array) ($coverageInspection['report'] ?? []);
        $coverageRows = array_values(array_map(
            static fn (array $row): string => (string) $row['slug'].'|'.(string) $row['locale'],
            array_filter(
                (array) ($coverageInspection['rows'] ?? []),
                static fn (mixed $row): bool => is_array($row)
                    && ($row['classification'] ?? null) !== 'held_or_unpublished_excluded',
            ),
        ));
        $coverageSnapshot = self::snapshotFromRows($coverageRows, 'COVERAGE');
        $responseCache = $app->make('App\\Services\\Career\\PublicCareerAuthorityResponseCache');
        $jobs = [];
        $directories = [];
        foreach (self::LOCALES as $locale) {
            $jobs[$locale] = self::snapshotFromPayload($responseCache->jobIndexPayload($locale), $locale, false);
            $directories[$locale] = self::snapshotFromPayload($responseCache->directoryReadModelPayload($locale), $locale, true);
        }
        $sitemapPayload = Cache::get('seo:sitemap-source:v1:fresh');
        $sitemap = self::snapshotFromSitemapJson(is_array($sitemapPayload) ? $sitemapPayload : []);

        $jobConverged = self::sameLocale($authority, $jobs['en'], 'en')
            && self::sameLocale($authority, $jobs['zh-CN'], 'zh-CN');
        $directoryConverged = self::sameLocale($authority, $directories['en'], 'en')
            && self::sameLocale($authority, $directories['zh-CN'], 'zh-CN');
        $coverageConverged = ($coverage['status'] ?? null) === 'ready'
            && (int) ($coverage['expected_target_count'] ?? -1) === (int) $authority['row_count']
            && (int) ($coverage['covered_target_count'] ?? -1) === (int) $authority['row_count']
            && (int) ($coverage['excluded_count'] ?? -1) === 0
            && self::sameSnapshot($authority, $coverageSnapshot);
        $runtimeConverged = self::sameSnapshot($authority, $runtime);
        $sitemapConverged = self::sameSnapshot($authority, $sitemap);
        $repairTargets = array_values(array_map(
            static fn (array $row): array => [
                'slug' => (string) $row['slug'],
                'locale' => (string) $row['locale'],
                'classification' => (string) $row['classification'],
            ],
            array_filter(
                (array) ($coverageInspection['rows'] ?? []),
                static fn (mixed $row): bool => is_array($row) && ($row['repairable'] ?? false) === true,
            ),
        ));

        $expectedRows = (array) ($authority['rows'] ?? []);
        $expectedUrls = array_map(static function (string $row): string {
            [$slug, $locale] = explode('|', $row, 2);

            return sprintf('https://fermatmind.com/%s/career/jobs/%s', $locale === 'zh-CN' ? 'zh' : 'en', $slug);
        }, $expectedRows);
        $cacheKeyManifest = self::cacheKeysForBackup($expectedRows);
        $revalidationPathManifest = self::BASE_REVALIDATION_PATHS;
        foreach ($repairTargets as $target) {
            $revalidationPathManifest[] = sprintf(
                '/%s/career/jobs/%s',
                $target['locale'] === 'zh-CN' ? 'zh' : 'en',
                $target['slug'],
            );
        }
        $revalidationPathManifest = array_values(array_unique($revalidationPathManifest));
        sort($revalidationPathManifest, SORT_STRING);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'inspect',
            'status' => $runtimeConverged ? 'PASS_AUTHORITY_READABLE' : 'HOLD_RUNTIME_AUTHORITY_DRIFT',
            'authority_artifact_sha256' => $artifact['sha256'],
            'authority_inventory' => (array) ($authority['inventory'] ?? []),
            'authority' => self::safeSnapshot($authority),
            'runtime' => self::safeSnapshot($runtime),
            'coverage' => $coverage,
            'detail_coverage' => self::safeSnapshot($coverageSnapshot),
            'repair_targets' => $repairTargets,
            'job_index_converged' => $jobConverged,
            'directory_converged' => $directoryConverged,
            'sitemap_source_converged' => $sitemapConverged,
            'internal_converged' => $runtimeConverged && $coverageConverged
                && $jobConverged && $directoryConverged && $sitemapConverged,
            'expected_rows' => $expectedRows,
            'expected_urls' => $expectedUrls,
            'target_set_sha256' => self::setHash($expectedRows),
            'cache_key_manifest' => $cacheKeyManifest,
            'cache_key_manifest_sha256' => self::setHash($cacheKeyManifest),
            'revalidation_path_manifest' => $revalidationPathManifest,
            'revalidation_path_manifest_sha256' => self::setHash($revalidationPathManifest),
            'migration_name' => self::MIGRATION,
            'migration_record_present' => Schema::hasTable('migrations')
                && DB::table('migrations')->where('migration', self::MIGRATION)->exists(),
            'public_topic_edges_table_present' => Schema::hasTable('public_topic_edges'),
            'cache_write_count' => 0,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'process_restart_count' => 0,
            'queue_reload_count' => 0,
            'search_submission_count' => 0,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function authoritySnapshot(array $payload): array
    {
        if (($payload['projection_kind'] ?? null) !== 'career_runtime_publish_projection'
            || ($payload['projection_version'] ?? null) !== 'career.runtime_publish_projection.v1') {
            throw new CareerC03ControlFailure('AUTHORITY_CONTRACT_INVALID');
        }
        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw new CareerC03ControlFailure('AUTHORITY_ITEMS_EMPTY');
        }

        $allRows = [];
        $publishedRows = [];
        $uniqueSlugs = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new CareerC03ControlFailure('AUTHORITY_ITEM_INVALID');
            }
            $slug = self::slug($item);
            $locale = self::locale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                throw new CareerC03ControlFailure('AUTHORITY_IDENTITY_INVALID');
            }
            $row = $slug.'|'.$locale;
            if (isset($allRows[$row])) {
                throw new CareerC03ControlFailure('AUTHORITY_DUPLICATE_IDENTITY');
            }
            $allRows[$row] = true;
            $uniqueSlugs[$slug] = true;
            if (! self::isPublished($item)) {
                continue;
            }
            foreach (['dataset_visible', 'search_visible', 'sitemap_live', 'llms_live'] as $flag) {
                if (($item[$flag] ?? false) !== true) {
                    throw new CareerC03ControlFailure('AUTHORITY_SURFACE_FLAGS_MISALIGNED');
                }
            }
            $publishedRows[] = $row;
        }
        sort($publishedRows, SORT_STRING);
        $snapshot = self::snapshotFromRows($publishedRows, 'AUTHORITY');
        $snapshot['inventory'] = [
            'unique_slug_count' => count($uniqueSlugs),
            'locale_row_count' => count($allRows),
            'row_set_sha256' => self::setHash(array_keys($allRows)),
        ];

        return $snapshot;
    }

    /** @param array<string, array<string, mixed>> $items @return array<string, mixed> */
    public static function runtimeSnapshot(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! self::isPublished($item)) {
                continue;
            }
            $slug = self::slug($item);
            $locale = self::locale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                throw new CareerC03ControlFailure('RUNTIME_IDENTITY_INVALID');
            }
            $rows[] = $slug.'|'.$locale;
        }

        return self::snapshotFromRows($rows, 'RUNTIME');
    }

    /** @param list<string> $rows @return array<string, mixed> */
    public static function snapshotFromRows(array $rows, string $safePrefix): array
    {
        $maps = ['en' => [], 'zh-CN' => []];
        foreach ($rows as $row) {
            if (preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)\|(en|zh-CN)$/D', $row, $matches) !== 1) {
                throw new CareerC03ControlFailure($safePrefix.'_ROW_INVALID');
            }
            if (isset($maps[$matches[2]][$matches[1]])) {
                throw new CareerC03ControlFailure($safePrefix.'_ROW_DUPLICATE');
            }
            $maps[$matches[2]][$matches[1]] = true;
        }
        $en = array_keys($maps['en']);
        $zh = array_keys($maps['zh-CN']);
        sort($en, SORT_STRING);
        sort($zh, SORT_STRING);
        if ($en === [] || $en !== $zh) {
            throw new CareerC03ControlFailure($safePrefix.'_BILINGUAL_SET_INVALID');
        }
        $normalizedRows = array_merge(
            array_map(static fn (string $slug): string => $slug.'|en', $en),
            array_map(static fn (string $slug): string => $slug.'|zh-CN', $zh),
        );
        sort($normalizedRows, SORT_STRING);

        return [
            'slug_count' => count($en),
            'row_count' => count($normalizedRows),
            'slug_set_sha256' => self::setHash($en),
            'row_set_sha256' => self::setHash($normalizedRows),
            'locales' => [
                'en' => ['count' => count($en), 'set_sha256' => self::setHash($en)],
                'zh-CN' => ['count' => count($zh), 'set_sha256' => self::setHash($zh)],
            ],
            'rows' => $normalizedRows,
        ];
    }

    /** @param list<string> $rows @return array<string, mixed> */
    private static function surfaceSnapshotFromRows(array $rows, string $safePrefix): array
    {
        $maps = ['en' => [], 'zh-CN' => []];
        foreach ($rows as $row) {
            if (preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)\|(en|zh-CN)$/D', $row, $matches) !== 1) {
                throw new CareerC03ControlFailure($safePrefix.'_ROW_INVALID');
            }
            if (isset($maps[$matches[2]][$matches[1]])) {
                throw new CareerC03ControlFailure($safePrefix.'_ROW_DUPLICATE');
            }
            $maps[$matches[2]][$matches[1]] = true;
        }
        $en = array_keys($maps['en']);
        $zh = array_keys($maps['zh-CN']);
        sort($en, SORT_STRING);
        sort($zh, SORT_STRING);
        $slugs = array_values(array_unique(array_merge($en, $zh)));
        sort($slugs, SORT_STRING);
        $normalizedRows = array_merge(
            array_map(static fn (string $slug): string => $slug.'|en', $en),
            array_map(static fn (string $slug): string => $slug.'|zh-CN', $zh),
        );
        sort($normalizedRows, SORT_STRING);

        return [
            'slug_count' => count($slugs),
            'row_count' => count($normalizedRows),
            'slug_set_sha256' => self::setHash($slugs),
            'row_set_sha256' => self::setHash($normalizedRows),
            'locales' => [
                'en' => ['count' => count($en), 'set_sha256' => self::setHash($en)],
                'zh-CN' => ['count' => count($zh), 'set_sha256' => self::setHash($zh)],
            ],
            'rows' => $normalizedRows,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function snapshotFromPayload(array $payload, string $locale, bool $directory): array
    {
        return self::singleLocaleSnapshot(self::rowsFromPayload($payload, $locale, $directory), $locale);
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private static function rowsFromPayload(array $payload, string $locale, bool $directory): array
    {
        $rows = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ($directory && (($item['indexable'] ?? false) !== true || ($item['detail_ready'] ?? false) !== true)) {
                continue;
            }
            $slug = self::slug($item);
            if ($slug !== '') {
                $rows[] = $slug.'|'.$locale;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function snapshotFromSitemapJson(array $payload): array
    {
        if (($payload['ok'] ?? false) !== true || ($payload['source'] ?? null) !== 'backend_sitemap_generator') {
            throw new CareerC03ControlFailure('SITEMAP_SOURCE_NOT_AUTHORITATIVE');
        }
        $rows = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $url = is_array($item) ? (string) ($item['loc'] ?? '') : '';
            $row = self::careerRowFromUrl($url);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return self::surfaceSnapshotFromRows($rows, 'SITEMAP');
    }

    /** @return array<string, mixed> */
    private static function snapshotFromTextFile(string $path): array
    {
        $rows = [];
        foreach (self::urlsFromText(self::fileBytes($path, 'PUBLIC_TEXT_INPUT_INVALID')) as $url) {
            $row = self::careerRowFromUrl($url);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return self::surfaceSnapshotFromRows(array_values(array_unique($rows)), 'PUBLIC_TEXT');
    }

    /** @param list<string> $rows @return array<string, mixed> */
    private static function singleLocaleSnapshot(array $rows, string $locale): array
    {
        $slugs = [];
        foreach ($rows as $row) {
            [$slug, $rowLocale] = explode('|', $row, 2);
            if ($rowLocale === $locale) {
                $slugs[$slug] = true;
            }
        }
        $slugs = array_keys($slugs);
        sort($slugs, SORT_STRING);

        return ['locales' => [$locale => ['count' => count($slugs), 'set_sha256' => self::setHash($slugs)]]];
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private static function sameSnapshot(array $expected, array $actual): bool
    {
        return (int) ($expected['slug_count'] ?? -1) === (int) ($actual['slug_count'] ?? -2)
            && (int) ($expected['row_count'] ?? -1) === (int) ($actual['row_count'] ?? -2)
            && hash_equals((string) ($expected['slug_set_sha256'] ?? ''), (string) ($actual['slug_set_sha256'] ?? 'x'))
            && hash_equals((string) ($expected['row_set_sha256'] ?? ''), (string) ($actual['row_set_sha256'] ?? 'x'));
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private static function sameLocale(array $expected, array $actual, string $locale): bool
    {
        $left = (array) (($expected['locales'] ?? [])[$locale] ?? []);
        $right = (array) (($actual['locales'] ?? [])[$locale] ?? []);

        return (int) ($left['count'] ?? -1) === (int) ($right['count'] ?? -2)
            && hash_equals((string) ($left['set_sha256'] ?? ''), (string) ($right['set_sha256'] ?? 'x'));
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private static function safeSnapshot(array $snapshot): array
    {
        return [
            'slug_count' => (int) ($snapshot['slug_count'] ?? 0),
            'row_count' => (int) ($snapshot['row_count'] ?? 0),
            'slug_set_sha256' => (string) ($snapshot['slug_set_sha256'] ?? ''),
            'row_set_sha256' => (string) ($snapshot['row_set_sha256'] ?? ''),
            'locales' => (array) ($snapshot['locales'] ?? []),
        ];
    }

    /** @return array{payload: array<string, mixed>, sha256: string} */
    private static function latestProjectionArtifact(): array
    {
        $root = self::backendRoot().'/storage/app/private/career_runtime_publish_projection';
        $directories = is_dir($root) ? glob($root.'/*', GLOB_ONLYDIR) : false;
        if (! is_array($directories) || $directories === []) {
            throw new CareerC03ControlFailure('AUTHORITY_ARTIFACT_MISSING');
        }
        $candidates = [];
        foreach ($directories as $directory) {
            $path = $directory.'/career-runtime-publish-projection.json';
            if (! is_file($path) || is_link($path)) {
                continue;
            }
            $bytes = file_get_contents($path);
            $payload = is_string($bytes) ? json_decode($bytes, true) : null;
            if (is_array($payload)) {
                $candidates[] = [
                    'path' => $path,
                    'mtime' => filemtime($path) ?: 0,
                    'payload' => $payload,
                    'sha256' => hash('sha256', $bytes),
                ];
            }
        }
        if ($candidates === []) {
            throw new CareerC03ControlFailure('AUTHORITY_ARTIFACT_MISSING');
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['mtime'] <=> $left['mtime']) ?: strcmp((string) $right['path'], (string) $left['path']));

        return ['payload' => $candidates[0]['payload'], 'sha256' => $candidates[0]['sha256']];
    }

    private static function bootstrapApplication(): object
    {
        $backend = self::backendRoot();
        require_once $backend.'/vendor/autoload.php';
        $app = require $backend.'/bootstrap/app.php';
        if (! is_object($app) || ! method_exists($app, 'make')) {
            throw new CareerC03ControlFailure('APPLICATION_BOOTSTRAP_INVALID');
        }
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private static function backendRoot(): string
    {
        $root = rtrim(trim((string) getenv('CAREER_C03_BACKEND_ROOT')), '/');
        if ($root === '' || $root[0] !== '/' || str_contains($root, '..')
            || ! is_file($root.'/artisan') || ! is_file($root.'/bootstrap/app.php')) {
            throw new CareerC03ControlFailure('BACKEND_ROOT_INVALID');
        }

        return $root;
    }

    /** @param list<string> $rows @return list<string> */
    private static function cacheKeysForBackup(array $rows): array
    {
        $keys = self::SEO_CACHE_KEYS;
        foreach ($rows as $row) {
            [$slug, $locale] = explode('|', $row, 2);
            $prefix = sprintf('career:public-authority:job-detail:v3:%s:%s', $slug, $locale);
            $keys[] = $prefix.':active';
            $keys[] = $prefix.':lkg';
            $keys[] = $prefix.':negative';
            $keys[] = sprintf('career:public-authority:job-detail:v1:%s:%s', $slug, $locale);
        }
        foreach (self::LOCALES as $locale) {
            $prefix = 'career:public-authority:directory-read-model:v2:'.$locale;
            $keys[] = $prefix.':active';
            $keys[] = $prefix.':lkg';
            $keys[] = $prefix.':activated-at';
            $keys[] = $prefix.':last-rebuild-ms';
            $keys[] = 'career:public-authority:directory-read-model:v1:'.$locale;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param array<string, mixed> $inspection @return array<string, mixed> */
    private static function createBackup(array $inspection): array
    {
        $keys = self::cacheKeysForBackup((array) ($inspection['expected_rows'] ?? []));
        $snapshots = [];
        foreach ($keys as $key) {
            $value = Cache::get($key);
            $snapshots[$key] = [
                'present' => Cache::has($key),
                'value' => base64_encode(serialize($value)),
                'ttl_class' => str_starts_with($key, 'seo:') ? 'bounded_600' : 'forever',
            ];
            if (is_string($value) && (str_ends_with($key, ':active') || str_ends_with($key, ':lkg'))) {
                $base = substr($key, 0, (int) strrpos($key, ':'));
                $payloadKey = $base.':versions:'.$value;
                $snapshots[$payloadKey] = [
                    'present' => Cache::has($payloadKey),
                    'value' => base64_encode(serialize(Cache::get($payloadKey))),
                    'ttl_class' => 'forever',
                ];
                if (str_starts_with($key, 'career:public-authority:job-detail:v3:')) {
                    $exposureKey = $base.':exposure-projections:'.$value;
                    $snapshots[$exposureKey] = [
                        'present' => Cache::has($exposureKey),
                        'value' => base64_encode(serialize(Cache::get($exposureKey))),
                        'ttl_class' => 'forever',
                    ];
                }
            }
        }
        ksort($snapshots, SORT_STRING);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authority_artifact_sha256' => (string) ($inspection['authority_artifact_sha256'] ?? ''),
            'target_set_sha256' => (string) ($inspection['target_set_sha256'] ?? ''),
            'state_sha256' => self::canonicalHash($snapshots),
            'snapshots' => $snapshots,
        ];
    }

    /** @param array<string, mixed> $backup */
    private static function writeBackup(string $path, array $backup): void
    {
        $directory = dirname($path);
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory)) {
            throw new CareerC03ControlFailure('BACKUP_DIRECTORY_INVALID');
        }
        $bytes = json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new CareerC03ControlFailure('BACKUP_WRITE_FAILED');
        }
        $readback = self::jsonFile($path, 'BACKUP_READBACK_INVALID');
        if (! hash_equals((string) $backup['state_sha256'], self::canonicalHash((array) ($readback['snapshots'] ?? [])))) {
            throw new CareerC03ControlFailure('BACKUP_READBACK_MISMATCH');
        }
    }

    private static function restoreBackup(string $path): bool
    {
        try {
            $backup = self::jsonFile($path, 'BACKUP_INVALID');
            $snapshots = (array) ($backup['snapshots'] ?? []);
            if (! hash_equals((string) ($backup['state_sha256'] ?? ''), self::canonicalHash($snapshots))) {
                return false;
            }
            self::removeNewVersionPayloads($snapshots);
            foreach ($snapshots as $key => $snapshot) {
                if (! is_string($key) || ! is_array($snapshot)) {
                    return false;
                }
                if (($snapshot['present'] ?? false) !== true) {
                    Cache::forget($key);

                    continue;
                }
                $decoded = base64_decode((string) ($snapshot['value'] ?? ''), true);
                if (! is_string($decoded)) {
                    return false;
                }
                $value = unserialize($decoded, ['allowed_classes' => false]);
                if (($snapshot['ttl_class'] ?? null) === 'bounded_600') {
                    Cache::put($key, $value, 600);
                } else {
                    Cache::forever($key, $value);
                }
            }

            return hash_equals((string) $backup['state_sha256'], self::cacheStateHash($snapshots));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $snapshots */
    private static function removeNewVersionPayloads(array $snapshots): void
    {
        foreach ($snapshots as $key => $snapshot) {
            if (! is_string($key) || ! is_array($snapshot)
                || (! str_ends_with($key, ':active') && ! str_ends_with($key, ':lkg'))) {
                continue;
            }
            $current = Cache::get($key);
            $decoded = base64_decode((string) ($snapshot['value'] ?? ''), true);
            $previous = is_string($decoded) ? unserialize($decoded, ['allowed_classes' => false]) : null;
            if (! is_string($current) || $current === '' || $current === $previous) {
                continue;
            }
            $base = substr($key, 0, (int) strrpos($key, ':'));
            Cache::forget($base.':versions:'.$current);
            if (str_starts_with($key, 'career:public-authority:job-detail:v3:')) {
                Cache::forget($base.':exposure-projections:'.$current);
            }
        }
    }

    /** @param array<string, mixed> $snapshots */
    private static function cacheStateHash(array $snapshots): string
    {
        $current = [];
        foreach ($snapshots as $key => $snapshot) {
            if (! is_string($key) || ! is_array($snapshot)) {
                continue;
            }
            $current[$key] = [
                'present' => Cache::has($key),
                'value' => base64_encode(serialize(Cache::get($key))),
                'ttl_class' => (string) ($snapshot['ttl_class'] ?? ''),
            ];
        }
        ksort($current, SORT_STRING);

        return self::canonicalHash($current);
    }

    private static function backupPath(): string
    {
        $id = trim((string) getenv('CAREER_C03_BACKUP_ID'));
        if (preg_match('/^[1-9][0-9]*-[1-9][0-9]*$/D', $id) !== 1) {
            throw new CareerC03ControlFailure('BACKUP_ID_INVALID');
        }

        return self::backendRoot().'/storage/app/private/career-c03-cache-recovery/'.$id.'.json';
    }

    /** @param list<string> $paths */
    private static function revalidateFrontend(array $paths): void
    {
        $endpoints = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('ops.content_release_observability.cache_invalidation_urls', []),
        )));
        $secret = trim((string) config('ops.content_release_observability.hmac_revalidation_secret', ''));
        if ($secret === '') {
            $secret = trim((string) config('ops.content_release_observability.cache_invalidation_secret', ''));
        }
        if ($endpoints === [] || $secret === '') {
            throw new CareerC03ControlFailure('FRONTEND_REVALIDATION_NOT_CONFIGURED');
        }
        sort($paths, SORT_STRING);
        $payload = [
            'event' => 'content_release_revalidate',
            'source' => 'career_c03_cache_only_discoverability_recovery',
            'content' => [
                'type' => 'career-discoverability-cache',
                'id' => 0,
                'org_id' => 0,
                'title' => 'Career C03 cache-only discoverability recovery',
                'slug' => 'career-c03-cache-only',
                'locale' => 'en',
                'status' => 'published',
                'visibility' => 'public',
            ],
            'cache_signal' => ['kind' => 'invalidate', 'paths' => $paths, 'urls' => $paths],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($endpoints as $endpoint) {
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
            $response = Http::acceptJson()->timeout(20)->withHeaders([
                'X-FM-Content-Release-Source' => 'career_c03_cache_only_discoverability_recovery',
                'X-FM-Content-Release-Timestamp' => $timestamp,
                'X-FM-Content-Release-Nonce' => $nonce,
                'X-FM-Content-Release-Signature' => 'sha256='.$signature,
            ])->withBody($body, 'application/json')->post($endpoint);
            $json = $response->json();
            $accepted = is_array($json) ? array_values((array) ($json['revalidated_paths'] ?? [])) : [];
            $rejected = is_array($json) ? (array) ($json['rejected_paths'] ?? []) : [];
            sort($accepted, SORT_STRING);
            if (! $response->successful() || $rejected !== [] || $accepted !== $paths) {
                throw new CareerC03ControlFailure('FRONTEND_REVALIDATION_FAILED');
            }
        }
    }

    private static function assertApplyAuthorization(): void
    {
        if (getenv('CAREER_C03_CACHE_APPLY_AUTHORIZED') !== '1') {
            throw new CareerC03ControlFailure('APPLY_NOT_AUTHORIZED');
        }
    }

    /** @return array<string, mixed> */
    private static function detailStatus(string $path, array $expectedUrls): array
    {
        $expected = array_fill_keys(array_map('strval', $expectedUrls), [1 => false, 2 => false]);
        $timeouts = 0;
        $serverErrors = 0;
        $non200 = 0;
        foreach (preg_split('/\R/', trim(self::fileBytes($path, 'DETAIL_STATUS_INVALID'))) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 4) {
                throw new CareerC03ControlFailure('DETAIL_STATUS_ROW_INVALID');
            }
            [$round, $url, $code, $curlExit] = $parts;
            $roundNumber = (int) $round;
            if (! isset($expected[$url][$roundNumber]) || $expected[$url][$roundNumber] === true) {
                throw new CareerC03ControlFailure('DETAIL_STATUS_IDENTITY_INVALID');
            }
            $exit = (int) $curlExit;
            $status = (int) $code;
            $expected[$url][$roundNumber] = true;
            if ($exit !== 0) {
                $timeouts++;
            } elseif ($status >= 500) {
                $serverErrors++;
            } elseif ($status !== 200) {
                $non200++;
            }
        }
        foreach ($expected as $rounds) {
            if ($rounds[1] !== true || $rounds[2] !== true) {
                throw new CareerC03ControlFailure('DETAIL_STATUS_INCOMPLETE');
            }
        }

        return [
            'url_count' => count($expected),
            'round_count' => 2,
            'request_count' => count($expected) * 2,
            'timeout_count' => $timeouts,
            'server_error_count' => $serverErrors,
            'non_200_count' => $non200,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function isPublished(array $payload): bool
    {
        $state = (string) ($payload['runtime_publish_state'] ?? $payload['runtime_state']
            ?? $payload['projection_state'] ?? $payload['state'] ?? '');

        return $state === 'published'
            && ($payload['detail_route_enabled'] ?? false) === true
            && ($payload['robots_indexable'] ?? false) === true
            && ($payload['release_gate_pass'] ?? false) === true;
    }

    /** @param array<string, mixed> $item */
    private static function slug(array $item): string
    {
        $slug = strtolower(trim((string) ($item['slug'] ?? $item['canonical_slug']
            ?? ($item['identity']['canonical_slug'] ?? ''))));

        return self::validSlug($slug) ? $slug : '';
    }

    private static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }

    private static function locale(string $locale): ?string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us' => 'en',
            'zh', 'zh-cn' => 'zh-CN',
            default => null,
        };
    }

    private static function careerRowFromUrl(string $url): ?string
    {
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');
        if (preg_match('#^/(en|zh)/career/jobs/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#D', $path, $matches) !== 1) {
            return null;
        }

        return $matches[2].'|'.($matches[1] === 'zh' ? 'zh-CN' : 'en');
    }

    /** @return list<string> */
    private static function urlsFromText(string $bytes): array
    {
        preg_match_all('#https://(?:www\.)?fermatmind\.com/[^\s<"\']+#i', $bytes, $matches);
        $urls = array_values(array_unique(array_map(
            static fn (string $url): string => rtrim($url, '.,);]'),
            (array) ($matches[0] ?? []),
        )));
        sort($urls, SORT_STRING);

        return $urls;
    }

    /** @param array<string, mixed> $payload */
    private static function nonCareerHashFromSitemapJson(array $payload): string
    {
        $urls = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $url = is_array($item) ? trim((string) ($item['loc'] ?? '')) : '';
            if ($url !== '' && self::careerRowFromUrl($url) === null) {
                $urls[] = $url;
            }
        }

        return self::setHash($urls);
    }

    private static function nonCareerHashFromTextFile(string $path): string
    {
        return self::setHash(array_values(array_filter(
            self::urlsFromText(self::fileBytes($path, 'PUBLIC_TEXT_INPUT_INVALID')),
            static fn (string $url): bool => self::careerRowFromUrl($url) === null,
        )));
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value, string $safeCode): array
    {
        if (! is_array($value) || $value === []) {
            throw new CareerC03ControlFailure($safeCode);
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new CareerC03ControlFailure($safeCode);
            }
            $items[] = trim($item);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private static function jsonFile(string $path, string $safeCode): array
    {
        $decoded = json_decode(self::fileBytes($path, $safeCode), true);
        if (! is_array($decoded)) {
            throw new CareerC03ControlFailure($safeCode);
        }

        return $decoded;
    }

    private static function fileBytes(string $path, string $safeCode): string
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerC03ControlFailure($safeCode);
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new CareerC03ControlFailure($safeCode);
        }

        return $bytes;
    }

    /** @param list<string> $items */
    private static function setHash(array $items): string
    {
        $items = array_values(array_unique(array_map('strval', $items)));
        sort($items, SORT_STRING);

        return hash('sha256', implode("\n", $items)."\n");
    }

    private static function canonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function failureReceipt(string $mode, string $safeCode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => 'HOLD_CONTROL_FAILED',
            'safe_failure_code' => $safeCode,
            'automatic_retry_allowed' => false,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'process_restart_count' => 0,
            'queue_reload_count' => 0,
            'search_submission_count' => 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function emit(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(CareerC03CacheOnlyDiscoverabilityControl::main($argv));
}
