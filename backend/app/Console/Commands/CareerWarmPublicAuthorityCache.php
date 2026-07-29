<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\CareerDirectoryReadModelBuilder;
use App\Services\Career\Dataset\CareerFullDatasetAuthorityBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CareerWarmPublicAuthorityCache extends Command
{
    public const FINGERPRINT_CACHE_KEY = 'career:public-authority:warm-fingerprint:v1';

    private const FINGERPRINT_SCHEMA_VERSION = 'fermatmind.career-public-authority-warm-fingerprint.v1';

    private const CACHE_SCHEMA_VERSION = 'career.public-authority-cache.v1';

    private const CODE_FINGERPRINT_PATHS = [
        'app/Console/Commands/CareerWarmPublicAuthorityCache.php',
        'app/Domain/Career/Publish/CareerLaunchGovernanceClosureService.php',
        'app/Services/Career/Bundles/CareerJobListBundleBuilder.php',
        'app/Services/Career/CareerDirectoryReadModelBuilder.php',
        'app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php',
        'app/Services/Career/PublicCareerAuthorityResponseCache.php',
    ];

    protected $signature = 'career:warm-public-authority-cache
        {--job-detail-slugs= : Comma-separated career job slugs for per-locale detail cache warm}
        {--job-detail-manifest= : JSON manifest/report file used to derive per-locale career job slugs}
        {--job-detail-manifest-source=auto : Slug source in the manifest: auto, items, candidate_slugs, controlled_import_manifest.candidate_slugs, slugs}
        {--job-detail-locales=zh-CN : Comma-separated public locales for detail cache warm}
        {--forget-job-detail : Forget targeted job detail caches before warming them}
        {--job-detail-only : Warm only targeted job detail caches}
        {--directory-only : Warm only the EN/ZH Career directory read models}
        {--verify-only : Verify EN/ZH directory versions without rebuilding authority}
        {--refresh-if-changed : Verify the exact authority fingerprint and rebuild only when it changed}
        {--json : Emit JSON output}';

    protected $description = 'Warm public Career dataset and launch-governance authority response caches outside the HTTP request path.';

    public function handle(
        PublicCareerAuthorityResponseCache $cache,
        CareerFullDatasetAuthorityBuilder $datasetAuthorityBuilder,
        CareerJobListBundleBuilder $jobListBundleBuilder,
        CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
    ): int {
        try {
            if ((bool) $this->option('refresh-if-changed')) {
                return $this->refreshIfChanged(
                    $cache,
                    $datasetAuthorityBuilder,
                    $jobListBundleBuilder,
                    $launchGovernanceClosureService,
                );
            }

            if ((bool) $this->option('verify-only')) {
                return $this->verifyDirectoryCaches($cache);
            }

            $manifestPath = trim((string) $this->option('job-detail-manifest'));
            $manifestSource = trim((string) $this->option('job-detail-manifest-source'));
            $jobDetailSlugs = array_values(array_unique(array_merge(
                $this->csvOption('job-detail-slugs'),
                $manifestPath === '' ? [] : $this->slugsFromManifest($manifestPath, $manifestSource === '' ? 'auto' : $manifestSource),
            )));
            $jobDetailLocales = $this->csvOption('job-detail-locales');
            $jobDetailOnly = (bool) $this->option('job-detail-only');
            $directoryOnly = (bool) $this->option('directory-only');
            if ($directoryOnly && ($jobDetailOnly || $jobDetailSlugs !== [] || (bool) $this->option('forget-job-detail'))) {
                $this->error('--directory-only cannot be combined with job-detail warm options.');

                return self::FAILURE;
            }
            if ($jobDetailOnly && $jobDetailSlugs === []) {
                $this->error('--job-detail-only requires --job-detail-slugs or --job-detail-manifest.');

                return self::FAILURE;
            }

            $reporter = function (string $phase, string $state): void {
                if (! (bool) $this->option('json')) {
                    $this->line(sprintf('career_warm_phase=%s state=%s', $phase, $state));
                }
            };
            $summary = [];
            $locales = $jobDetailLocales === [] ? ['zh-CN'] : $jobDetailLocales;
            if ($jobDetailSlugs !== []) {
                $summary = array_merge(
                    $summary,
                    $cache->warmJobDetailPayloads(
                        $jobDetailSlugs,
                        $locales,
                        (bool) $this->option('forget-job-detail'),
                        $reporter,
                    ),
                );
            }
            $summary = array_merge(
                $summary,
                $directoryOnly
                    ? $cache->warmDirectoryReadModels(['en', 'zh-CN'], $reporter)
                    : ($jobDetailOnly ? [] : $cache->warm($reporter)),
            );
            $jobDetailReport = $jobDetailSlugs === [] ? null : $this->jobDetailReport(
                $summary,
                $jobDetailSlugs,
                $locales,
                $manifestPath === '' ? null : $manifestPath,
                $manifestPath === '' ? null : ($manifestSource === '' ? 'auto' : $manifestSource),
            );

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'warmed',
                    'entries' => $summary,
                    'job_detail_refresh' => $jobDetailReport,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=warmed');
            if ($jobDetailReport !== null) {
                $this->line(sprintf(
                    'job_detail_refresh slug_count=%d locale_count=%d expected_cache_entries=%d',
                    (int) $jobDetailReport['slug_count'],
                    (int) $jobDetailReport['locale_count'],
                    (int) $jobDetailReport['expected_cache_entries'],
                ));
            }
            foreach ($summary as $name => $entry) {
                $this->line(sprintf(
                    '%s cache_key=%s member_count=%d',
                    $name,
                    (string) ($entry['cache_key'] ?? ''),
                    (int) ($entry['member_count'] ?? 0),
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function refreshIfChanged(
        PublicCareerAuthorityResponseCache $cache,
        CareerFullDatasetAuthorityBuilder $datasetAuthorityBuilder,
        CareerJobListBundleBuilder $jobListBundleBuilder,
        CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
    ): int {
        $conflictingOptions = [
            'job-detail-slugs',
            'job-detail-manifest',
            'forget-job-detail',
            'job-detail-only',
            'directory-only',
            'verify-only',
        ];
        foreach ($conflictingOptions as $option) {
            if ($this->optionIsSet($option)) {
                throw new \InvalidArgumentException(sprintf(
                    '--refresh-if-changed cannot be combined with --%s.',
                    $option,
                ));
            }
        }

        $fingerprint = $this->buildFingerprint(
            $datasetAuthorityBuilder,
            $jobListBundleBuilder,
            $launchGovernanceClosureService,
        );
        $cachedFingerprint = Cache::get(self::FINGERPRINT_CACHE_KEY);
        $cacheReadiness = $this->publicAuthorityCacheReadiness($cache);
        if (
            $this->fingerprintReceiptMatches($cachedFingerprint, $fingerprint)
            && ($cacheReadiness['ready'] ?? false) === true
        ) {
            $this->emitRefreshReport(
                'verified_unchanged',
                'fingerprint_and_cache_verified',
                $fingerprint,
                $cacheReadiness,
                [],
            );

            return self::SUCCESS;
        }

        $reporter = function (string $phase, string $state): void {
            if (! (bool) $this->option('json')) {
                $this->line(sprintf('career_warm_phase=%s state=%s', $phase, $state));
            }
        };
        $summary = $cache->warm($reporter);
        $cacheReadiness = $this->publicAuthorityCacheReadiness($cache);
        if (($cacheReadiness['ready'] ?? false) !== true) {
            throw new \RuntimeException('Career public authority cache rebuild did not produce a readable cache set.');
        }

        $receipt = [
            ...$fingerprint,
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        if (! Cache::forever(self::FINGERPRINT_CACHE_KEY, $receipt)) {
            throw new \RuntimeException('Career public authority cache fingerprint could not be published atomically.');
        }

        $this->emitRefreshReport(
            'rebuilt',
            'fingerprint_missing_or_changed',
            $fingerprint,
            $cacheReadiness,
            $summary,
        );

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   schema_version: string,
     *   fingerprint_sha256: string,
     *   authoritative_data_summary_sha256: string,
     *   cache_schema_version: string,
     *   code_fingerprint_sha256: string,
     *   normalization_identity_sha256: string,
     *   environment_identity_sha256: string
     * }
     */
    private function buildFingerprint(
        CareerFullDatasetAuthorityBuilder $datasetAuthorityBuilder,
        CareerJobListBundleBuilder $jobListBundleBuilder,
        CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
    ): array {
        $jobList = array_map(
            static fn (mixed $item): array => $item->toArray(),
            $jobListBundleBuilder->build(false),
        );
        $authoritySummary = [
            'dataset_authority' => $datasetAuthorityBuilder->build()->toArray(),
            'job_list_authority' => $jobList,
            'launch_governance_authority' => $launchGovernanceClosureService->build()->toArray(),
        ];
        $normalizationIdentity = [
            'cache_schema_version' => self::CACHE_SCHEMA_VERSION,
            'dataset_authority_version' => CareerFullDatasetAuthorityBuilder::AUTHORITY_VERSION,
            'directory_read_model_version' => CareerDirectoryReadModelBuilder::READ_MODEL_VERSION,
            'job_index_bundle_version' => 'career.protocol.job_index.v1',
            'launch_governance_version' => CareerLaunchGovernanceClosureService::GOVERNANCE_VERSION,
            'locales' => ['en', 'zh-CN'],
        ];
        $codeHashes = [];
        foreach (self::CODE_FINGERPRINT_PATHS as $relativePath) {
            $absolutePath = base_path($relativePath);
            $digest = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
            if (! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                throw new \RuntimeException(sprintf(
                    'Career public authority fingerprint source is unavailable: %s.',
                    $relativePath,
                ));
            }
            $codeHashes[$relativePath] = $digest;
        }
        $components = [
            'authoritative_data_summary_sha256' => $this->fingerprint($authoritySummary),
            'cache_schema_version' => self::CACHE_SCHEMA_VERSION,
            'code_fingerprint_sha256' => $this->fingerprint($codeHashes),
            'normalization_identity_sha256' => $this->fingerprint($normalizationIdentity),
            'environment_identity_sha256' => $this->fingerprint([
                'app_environment' => app()->environment(),
                'cache_default_store' => (string) config('cache.default'),
                'cache_prefix' => (string) config('cache.prefix'),
            ]),
        ];

        return [
            'schema_version' => self::FINGERPRINT_SCHEMA_VERSION,
            'fingerprint_sha256' => $this->fingerprint($components),
            ...$components,
        ];
    }

    /** @return array{ready: bool, entries: array<string, array<string, mixed>>} */
    private function publicAuthorityCacheReadiness(PublicCareerAuthorityResponseCache $cache): array
    {
        $datasetHub = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
        $datasetMethod = Cache::get(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY);
        $launchGovernance = Cache::get(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY);
        $entries = [
            'dataset_hub' => [
                'ready' => is_array($datasetHub)
                    && ($datasetHub['contract_kind'] ?? null) === 'career_public_dataset_hub'
                    && ($datasetHub['contract_version'] ?? null) === 'career.dataset_public_contract.v1'
                    && is_array($datasetHub['members'] ?? null),
            ],
            'dataset_method' => [
                'ready' => is_array($datasetMethod)
                    && ($datasetMethod['contract_kind'] ?? null) === 'career_public_dataset_method'
                    && ($datasetMethod['contract_version'] ?? null) === 'career.dataset_public_method.v1'
                    && is_array($datasetMethod['scope_summary'] ?? null),
            ],
            'launch_governance' => [
                'ready' => is_array($launchGovernance)
                    && ($launchGovernance['governance_version'] ?? null)
                        === CareerLaunchGovernanceClosureService::GOVERNANCE_VERSION
                    && is_array($launchGovernance['members'] ?? null),
            ],
        ];

        foreach (['en', 'zh-CN'] as $locale) {
            $jobIndexPrefix = PublicCareerAuthorityResponseCache::JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX
                .':'.$locale.':public';
            $jobIndexVersion = Cache::get($jobIndexPrefix.':active');
            $jobIndex = is_string($jobIndexVersion) && $jobIndexVersion !== ''
                ? Cache::get($jobIndexPrefix.':versions:'.$jobIndexVersion)
                : null;
            $entries['job_index_'.$locale] = [
                'ready' => is_array($jobIndex)
                    && ($jobIndex['bundle_kind'] ?? null) === 'career_job_index'
                    && ($jobIndex['bundle_version'] ?? null) === 'career.protocol.job_index.v1'
                    && is_array($jobIndex['items'] ?? null),
                'active_version' => is_string($jobIndexVersion) ? $jobIndexVersion : null,
            ];

            $directoryStatus = $cache->directoryCacheStatus($locale);
            try {
                $directory = $cache->directoryReadModelPayload($locale);
            } catch (\Throwable) {
                $directory = null;
            }
            $entries['directory_'.$locale] = [
                'ready' => ($directoryStatus['status'] ?? null) === 'ready'
                    && is_array($directory)
                    && ($directory['read_model_version'] ?? null) === CareerDirectoryReadModelBuilder::READ_MODEL_VERSION
                    && ($directory['locale'] ?? null) === $locale
                    && is_array($directory['items'] ?? null),
                'active_version' => $directoryStatus['active_version'] ?? null,
                'lkg_version' => $directoryStatus['lkg_version'] ?? null,
            ];
        }

        return [
            'ready' => array_reduce(
                $entries,
                static fn (bool $ready, array $entry): bool => $ready && ($entry['ready'] ?? false) === true,
                true,
            ),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     * @param  array<string, string>  $expected
     */
    private function fingerprintReceiptMatches(mixed $cached, array $expected): bool
    {
        if (! is_array($cached) || array_is_list($cached)) {
            return false;
        }
        $expectedKeys = [...array_keys($expected), 'generated_at'];
        $actualKeys = array_keys($cached);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (! is_string($cached[$field] ?? null) || ! hash_equals($value, $cached[$field])) {
                return false;
            }
        }

        return is_string($cached['generated_at'] ?? null)
            && preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $cached['generated_at']) === 1;
    }

    /**
     * @param  array<string, string>  $fingerprint
     * @param  array<string, mixed>  $cacheReadiness
     * @param  array<string, mixed>  $entries
     */
    private function emitRefreshReport(
        string $status,
        string $decision,
        array $fingerprint,
        array $cacheReadiness,
        array $entries,
    ): void {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status,
                'decision' => $decision,
                'fingerprint_sha256' => $fingerprint['fingerprint_sha256'],
                'cache_readiness' => $cacheReadiness,
                'entries' => $entries,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return;
        }

        $this->line('career_cache_refresh_result='.$status);
        $this->line('status='.$status);
        $this->line('fingerprint_sha256='.$fingerprint['fingerprint_sha256']);
    }

    private function optionIsSet(string $name): bool
    {
        $value = $this->option($name);

        return is_bool($value) ? $value : trim((string) $value) !== '';
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function verifyDirectoryCaches(PublicCareerAuthorityResponseCache $cache): int
    {
        $entries = [
            'en' => $cache->directoryCacheStatus('en'),
            'zh-CN' => $cache->directoryCacheStatus('zh-CN'),
        ];
        $ready = array_reduce(
            $entries,
            static fn (bool $carry, array $entry): bool => $carry && ($entry['status'] ?? null) === 'ready',
            true,
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => $ready ? 'ready' : 'unavailable',
                'entries' => $entries,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            foreach ($entries as $locale => $entry) {
                $this->line(sprintf(
                    'career_directory_cache locale=%s status=%s active_version=%s lkg_version=%s',
                    $locale,
                    (string) ($entry['status'] ?? 'unavailable'),
                    (string) ($entry['active_version'] ?? ''),
                    (string) ($entry['lkg_version'] ?? ''),
                ));
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $raw = trim((string) $this->option($name));
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    private function slugsFromManifest(string $path, string $source): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Job detail manifest not found: %s', $path));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Job detail manifest must decode to a JSON object or array.');
        }

        $sources = match ($source) {
            'auto' => [
                ['items'],
                ['controlled_import_manifest', 'candidate_slugs'],
                ['candidate_slugs'],
                ['slugs'],
            ],
            'items' => [['items']],
            'candidate_slugs' => [['candidate_slugs']],
            'controlled_import_manifest.candidate_slugs' => [['controlled_import_manifest', 'candidate_slugs']],
            'slugs' => [['slugs']],
            default => throw new \InvalidArgumentException(sprintf('Unsupported --job-detail-manifest-source value: %s', $source)),
        };

        foreach ($sources as $segments) {
            $value = $this->arrayPath($decoded, $segments);
            $slugs = $this->slugListFromValue($value);
            if ($slugs !== []) {
                return $slugs;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'No job detail slugs found in manifest %s using source %s.',
            $path,
            $source,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $segments
     */
    private function arrayPath(array $data, array $segments): mixed
    {
        $value = $data;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function slugListFromValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $slugs = [];
        foreach ($value as $item) {
            $slug = is_array($item) ? ($item['slug'] ?? null) : $item;
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, array<string, mixed>>  $summary
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function jobDetailReport(array $summary, array $slugs, array $locales, ?string $manifestPath, ?string $manifestSource): array
    {
        $entries = array_filter(
            $summary,
            static fn (array $entry): bool => ($entry['slug'] ?? null) !== null && ($entry['locale'] ?? null) !== null,
        );
        $statusCounts = [];
        foreach ($entries as $entry) {
            $status = (string) ($entry['status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }
        ksort($statusCounts);

        return [
            'manifest_path' => $manifestPath,
            'manifest_source' => $manifestSource,
            'slug_count' => count($slugs),
            'locales' => $locales,
            'locale_count' => count($locales),
            'expected_cache_entries' => count($slugs) * count($locales),
            'observed_cache_entries' => count($entries),
            'status_counts' => $statusCounts,
            'forget_first' => (bool) $this->option('forget-job-detail'),
        ];
    }
}
