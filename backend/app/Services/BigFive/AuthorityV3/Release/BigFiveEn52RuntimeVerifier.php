<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\Release;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class BigFiveEn52RuntimeVerifier
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const REQUEST_TIMEOUT_SECONDS = 20;

    /** @var list<string> */
    private const APPROVED_PUBLIC_HOSTS = [
        'api.fermatmind.com',
        'fermatmind.com',
        'www.fermatmind.com',
    ];

    /** @var list<string> */
    private const SEARCH_TABLES = [
        'seo_domestic_submission_logs',
        'seo_indexnow_submissions',
        'seo_issue_queue',
        'seo_search_channel_queue_batches',
        'seo_search_channel_queue_items',
        'seo_search_channel_queue_events',
    ];

    public function __construct(private readonly BigFiveEn52Publisher $publisher) {}

    /**
     * @param  array{approved_sha:string,release_name:string,api_origin:string,frontend_origin:string,package_path:string,expected_zh_fingerprint:string,expected_non_target_fingerprint:string,expected_search_fingerprint:string}  $approval
     * @param  array{sha:string,name:string}|null  $testingReleaseIdentity
     * @return array<string,mixed>
     */
    public function verify(array $approval, ?array $testingReleaseIdentity = null): array
    {
        $approvedSha = $this->gitSha($approval['approved_sha'] ?? '');
        $releaseName = $this->releaseName($approval['release_name'] ?? '');
        $apiOrigin = $this->httpsOrigin($approval['api_origin'] ?? '');
        $frontendOrigin = $this->httpsOrigin($approval['frontend_origin'] ?? '');
        $expectedZh = $this->sha256($approval['expected_zh_fingerprint'] ?? '', 'approval_zh_fingerprint_invalid');
        $expectedNonTarget = $this->sha256($approval['expected_non_target_fingerprint'] ?? '', 'approval_non_target_fingerprint_invalid');
        $expectedSearch = $this->sha256($approval['expected_search_fingerprint'] ?? '', 'approval_search_fingerprint_invalid');
        $identity = $this->releaseIdentity($testingReleaseIdentity);
        if (! hash_equals($approvedSha, $identity['sha']) || ! hash_equals($releaseName, $identity['name'])) {
            throw new RuntimeException('release_identity_mismatch');
        }

        $before = $this->databaseFingerprint();
        try {
            $plan = $this->publisher->preflight($approval['package_path'] ?? '');
        } catch (Throwable) {
            throw new RuntimeException('database_or_package_boundary_mismatch');
        }
        if (($plan['ok'] ?? false) !== true
            || ($plan['release_id'] ?? null) !== BigFiveEn52PackageCompiler::RELEASE_ID
            || ($plan['release_package_sha256'] ?? null) !== BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            || (int) ($plan['asset_count'] ?? 0) !== BigFiveEn52PackageCompiler::ASSET_COUNT
            || (int) ($plan['existing_release_revision_count'] ?? 0) !== BigFiveEn52PackageCompiler::ASSET_COUNT) {
            throw new RuntimeException('database_or_package_boundary_mismatch');
        }

        $db = $this->databaseCohort();
        if (! hash_equals($expectedZh, $db['zh_fingerprint'])) {
            throw new RuntimeException('zh_fingerprint_mismatch');
        }
        if (! hash_equals($expectedNonTarget, $db['non_target_fingerprint'])) {
            throw new RuntimeException('non_target_fingerprint_mismatch');
        }
        if (! hash_equals($expectedSearch, $db['search_fingerprint'])) {
            throw new RuntimeException('search_fingerprint_mismatch');
        }

        $api = $this->publicApi($apiOrigin, $db['expected_paths']);
        $discoverability = $this->discoverability($apiOrigin, $frontendOrigin, $db['expected_paths']);
        $redirects = $this->redirectBoundary($frontendOrigin, [
            ...$db['expected_paths'],
            ...array_column(BigFiveCanonicalRouteCatalog::canonicalEntries('zh-CN'), 'path'),
        ]);
        $after = $this->databaseFingerprint();
        if (! hash_equals($before, $after)) {
            throw new RuntimeException('database_write_detected');
        }

        return [
            'schema_version' => 'big_five_en52_runtime_verify.v1',
            'ok' => true,
            'status' => 'PASS_BIG_FIVE_EN52_RUNTIME_VERIFY',
            'mode' => 'verify_only',
            'approved_sha' => $approvedSha,
            'release_name' => $releaseName,
            'release_id' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            'locale' => 'en',
            'asset_count' => 52,
            'revision_count' => 52,
            'public_api_count' => $api['count'],
            'canonical_total_count' => 104,
            'family_counts' => BigFiveEn52PackageCompiler::FAMILY_COUNTS,
            'alias_database_count' => 0,
            'alias_public_surface_count' => 0,
            'permanent_single_hop_redirect_count' => $redirects,
            'canonical_redirect_count' => 0,
            'media_exposure_count' => $api['media_exposure_count'],
            'search_action_count' => 0,
            'sitemap_count' => $discoverability['sitemap_count'],
            'llms_count' => $discoverability['llms_count'],
            'llms_full_count' => $discoverability['llms_full_count'],
            'zh_fingerprint' => $db['zh_fingerprint'],
            'non_target_fingerprint' => $db['non_target_fingerprint'],
            'search_fingerprint' => $db['search_fingerprint'],
            'database_fingerprint_before' => $before,
            'database_fingerprint_after' => $after,
            'writes_committed' => false,
            'production_execution' => false,
            'sanitized_runner_artifact_only' => true,
        ];
    }

    /** @return array{expected_paths:list<string>,zh_fingerprint:string,non_target_fingerprint:string,search_fingerprint:string} */
    private function databaseCohort(): array
    {
        $entries = BigFiveCanonicalRouteCatalog::canonicalEntries('en');
        $expectedPaths = array_column($entries, 'path');
        sort($expectedPaths);
        $enRows = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', 'en')->orderBy('entity_type')->orderBy('entity_key')->get();
        $zhRows = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', 'zh-CN')->orderBy('entity_type')->orderBy('entity_key')->get();
        if ($enRows->count() !== 52 || $zhRows->count() !== 52) {
            throw new RuntimeException('canonical_cohort_count_mismatch');
        }
        $observed = [];
        foreach ($enRows as $asset) {
            $path = (string) data_get($asset->canonical_json, 'path', '');
            $expected = BigFiveCanonicalRouteCatalog::expectedPath('en', (string) $asset->entity_type, (string) $asset->entity_key);
            $zhPath = BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', (string) $asset->entity_type, (string) $asset->entity_key);
            $revision = PersonalityPublicContentAssetRevision::query()->find($asset->published_revision_id);
            if ($path !== $expected || (array) $asset->hreflang_json !== ['en' => $expected, 'zh-CN' => $zhPath]
                || ! $asset->is_public || ! $asset->index_eligible || ! $asset->sitemap_eligible || ! $asset->llms_eligible
                || (string) $asset->robots !== PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW
                || (string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                || ! $revision instanceof PersonalityPublicContentAssetRevision
                || (int) $asset->working_revision_id !== (int) $revision->id
                || (string) $revision->source_package !== BigFiveEn52PackageCompiler::RELEASE_ID
                || (string) $revision->authority_package_sha256 !== BigFiveEn52Publisher::PACKAGE_FILE_SHA256) {
                throw new RuntimeException('canonical_revision_boundary_mismatch');
            }
            $observed[] = $path;
        }
        sort($observed);
        if ($observed !== $expectedPaths || $this->aliasDatabaseCount() !== 0) {
            throw new RuntimeException('canonical_or_alias_boundary_mismatch');
        }

        return [
            'expected_paths' => $expectedPaths,
            'zh_fingerprint' => $this->fingerprint($zhRows->map(fn ($row) => $row->getAttributes())->all()),
            'non_target_fingerprint' => $this->nonTargetFingerprint(),
            'search_fingerprint' => $this->searchFingerprint(),
        ];
    }

    /** @param list<string> $expectedPaths @return array{count:int,media_exposure_count:int} */
    private function publicApi(string $origin, array $expectedPaths): array
    {
        $response = $this->get($origin.'/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100');
        $payload = $this->successfulJson($response, 'public_api_failed');
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (count($items) !== 52 || (int) data_get($payload, 'pagination.total', 0) !== 52) {
            throw new RuntimeException('public_api_count_mismatch');
        }
        $paths = [];
        $media = 0;
        foreach ($items as $item) {
            $expectedPath = is_array($item)
                ? BigFiveCanonicalRouteCatalog::expectedPath('en', (string) ($item['entity_type'] ?? ''), (string) ($item['code'] ?? ''))
                : null;
            $expectedHreflang = is_array($item) ? [
                'en' => $expectedPath,
                'zh-CN' => BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', (string) ($item['entity_type'] ?? ''), (string) ($item['code'] ?? '')),
            ] : [];
            if (! is_array($item) || ($item['locale'] ?? null) !== 'en'
                || ($item['source_package'] ?? null) !== BigFiveEn52PackageCompiler::RELEASE_ID
                || ($item['canonical_path'] ?? null) !== $expectedPath
                || ($item['hreflang'] ?? null) !== $expectedHreflang
                || ! ($item['is_public'] ?? false) || ! ($item['index_eligible'] ?? false)
                || ! ($item['sitemap_eligible'] ?? false) || ! ($item['llms_eligible'] ?? false)
                || ($item['robots'] ?? null) !== PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW) {
                throw new RuntimeException('public_api_projection_mismatch');
            }
            $paths[] = (string) ($item['canonical_path'] ?? '');
            $media += $this->containsMediaKey($item) ? 1 : 0;
        }
        sort($paths);
        if ($paths !== $expectedPaths || $media !== 0) {
            throw new RuntimeException('public_api_projection_mismatch');
        }
        foreach (BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets('en') as $alias => $_target) {
            foreach (['en', 'zh-CN'] as $locale) {
                $aliasResponse = $this->get($origin.'/api/v0.5/personality-content-assets/big_five/polarity/'.$alias.'?locale='.urlencode($locale));
                if ($aliasResponse->status() !== 404) {
                    throw new RuntimeException('alias_public_api_reappearance');
                }
            }
        }

        return ['count' => 52, 'media_exposure_count' => 0];
    }

    /** @param list<string> $enPaths @return array{sitemap_count:int,llms_count:int,llms_full_count:int} */
    private function discoverability(string $apiOrigin, string $frontendOrigin, array $enPaths): array
    {
        $expected = [...$enPaths, ...array_column(BigFiveCanonicalRouteCatalog::canonicalEntries('zh-CN'), 'path')];
        sort($expected);
        $aliases = array_keys(BigFiveCanonicalRouteCatalog::reviewedRedirectPaths());
        $source = $this->successfulJson($this->get($apiOrigin.'/api/v0.5/seo/sitemap-source'), 'sitemap_source_failed');
        $sourcePaths = $this->pathsFromUrls(array_column((array) ($source['items'] ?? []), 'loc'), $frontendOrigin);
        if ($this->bigFiveSubset($sourcePaths) !== $expected) {
            throw new RuntimeException('sitemap_source_cohort_mismatch');
        }
        $counts = [];
        foreach (['sitemap' => '/sitemap.xml', 'llms' => '/llms.txt', 'llms_full' => '/llms-full.txt'] as $name => $path) {
            $response = $this->get($frontendOrigin.$path);
            if (! $response->successful()) {
                throw new RuntimeException($name.'_failed');
            }
            preg_match_all('#https?://[^\s<>()"\']+|(?<![A-Za-z0-9:/])/(?!/)[^\s<>()"\']+#i', $response->body(), $matches);
            $paths = $this->pathsFromUrls($matches[0] ?? [], $frontendOrigin);
            if ($this->bigFiveSubset($paths) !== $expected || array_intersect($aliases, $paths) !== []) {
                throw new RuntimeException($name.'_cohort_mismatch');
            }
            $counts[$name.'_count'] = 104;
        }

        return $counts;
    }

    /** @param list<string> $canonicalPaths */
    private function redirectBoundary(string $origin, array $canonicalPaths): int
    {
        foreach ($canonicalPaths as $path) {
            if (! $this->get($origin.$path)->successful()) {
                throw new RuntimeException('canonical_redirect_or_http_failure');
            }
        }
        foreach (BigFiveCanonicalRouteCatalog::reviewedRedirectPaths() as $from => $to) {
            $response = $this->get($origin.$from);
            if ($response->status() !== 301
                || $this->pathFromUrl((string) $response->header('Location'), $origin) !== $to) {
                throw new RuntimeException('alias_redirect_boundary_mismatch');
            }
        }

        return 20;
    }

    private function aliasDatabaseCount(): int
    {
        $aliases = array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS);

        return PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->whereIn('locale', ['en', 'zh-CN'])->whereIn('entity_key', $aliases)->count();
    }

    private function nonTargetFingerprint(): string
    {
        $assets = DB::table('personality_public_content_assets')->where(function ($query): void {
            $query->where('framework', '!=', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)->orWhere('locale', '!=', 'en');
        })->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $revisions = DB::table('personality_public_content_asset_revisions')->where(function ($query): void {
            $query->whereNull('authority_package_sha256')->orWhere('authority_package_sha256', '!=', BigFiveEn52Publisher::PACKAGE_FILE_SHA256);
        })->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $authority = [];
        foreach ([
            'articles', 'topic_profiles', 'landing_surfaces', 'content_pages',
            'career_guides', 'career_guide_revisions', 'career_guide_seo_meta',
            'career_jobs', 'career_job_revisions', 'career_job_seo_meta', 'career_job_sections',
            'career_job_ai_impact_assets', 'career_job_display_assets',
            'career_job_page_assembly_assets', 'career_job_salary_assets',
            'media_assets', 'media_variants',
        ] as $table) {
            $authority[$table] = Schema::hasTable($table)
                ? DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                : [];
        }
        $connection = trim((string) config('seo_intel.connection', 'seo_intel'));
        foreach (self::SEARCH_TABLES as $table) {
            $authority[$table] = Schema::connection($connection)->hasTable($table)
                ? DB::connection($connection)->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                : [];
        }

        return $this->fingerprint([
            'non_target_personality' => $assets,
            'non_target_personality_revisions' => $revisions,
            'non_personality_authority' => $authority,
        ]);
    }

    private function searchFingerprint(): string
    {
        $connection = trim((string) config('seo_intel.connection', 'seo_intel'));
        $rows = [];
        foreach (self::SEARCH_TABLES as $table) {
            $rows[$table] = Schema::connection($connection)->hasTable($table)
                ? DB::connection($connection)->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                : [];
        }

        return $this->fingerprint($rows);
    }

    private function databaseFingerprint(): string
    {
        return $this->fingerprint([
            DB::table('personality_public_content_assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            $this->nonTargetFingerprint(),
            $this->searchFingerprint(),
        ]);
    }

    private function get(string $url): Response
    {
        return Http::accept('*/*')->withoutRedirecting()->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)->get($url);
    }

    /** @return array<string,mixed> */
    private function successfulJson(Response $response, string $error): array
    {
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['ok'] ?? false) !== true) {
            throw new RuntimeException($error);
        }

        return $json;
    }

    /** @param list<string> $urls @return list<string> */
    private function pathsFromUrls(array $urls, string $origin): array
    {
        $paths = array_values(array_filter(array_map(fn ($url) => $this->pathFromUrl((string) $url, $origin), $urls)));
        sort($paths);

        return $paths;
    }

    /** @param list<string> $paths @return list<string> */
    private function bigFiveSubset(array $paths): array
    {
        $paths = array_values(array_filter($paths, fn ($path) => preg_match('#^/(?:en|zh)/personality/big-five(?:/|$)#', $path) === 1));
        sort($paths);

        return $paths;
    }

    private function pathFromUrl(string $url, string $origin): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $parts = parse_url($url);
            if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
                return '';
            }

            return (string) ($parts['path'] ?? '');
        }

        $urlParts = parse_url($url);
        $originParts = parse_url($origin);
        if (! is_array($urlParts) || ! is_array($originParts)
            || strtolower((string) ($urlParts['scheme'] ?? '')) !== strtolower((string) ($originParts['scheme'] ?? ''))
            || strtolower((string) ($urlParts['host'] ?? '')) !== strtolower((string) ($originParts['host'] ?? ''))
            || ($urlParts['port'] ?? null) !== ($originParts['port'] ?? null)
            || isset($urlParts['user']) || isset($urlParts['pass'])
            || isset($urlParts['query']) || isset($urlParts['fragment'])) {
            return '';
        }

        return (string) ($urlParts['path'] ?? '');
    }

    private function containsMediaKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match('/(?:image|media|hero|og_image|twitter_image)/i', $key) === 1) {
                return true;
            }
            if ($this->containsMediaKey($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{sha:string,name:string}|null $override @return array{sha:string,name:string} */
    private function releaseIdentity(?array $override): array
    {
        if ($override !== null) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('release_identity_override_prohibited');
            }

            return ['sha' => $this->gitSha($override['sha'] ?? ''), 'name' => $this->releaseName($override['name'] ?? '')];
        }
        $root = dirname(base_path());
        $revision = $root.'/REVISION';
        if (! is_file($revision) || ! is_readable($revision)) {
            throw new RuntimeException('release_identity_unavailable');
        }

        return ['sha' => $this->gitSha((string) file_get_contents($revision)), 'name' => $this->releaseName(basename($root))];
    }

    private function gitSha(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new RuntimeException('approval_sha_invalid');
        }

        return $value;
    }

    private function sha256(string $value, string $error): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException($error);
        }

        return $value;
    }

    private function releaseName(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) !== 1) {
            throw new RuntimeException('release_name_invalid');
        }

        return $value;
    }

    private function httpsOrigin(string $value): string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''))) : '';
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array($host, self::APPROVED_PUBLIC_HOSTS, true) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '') {
            throw new RuntimeException('public_origin_invalid');
        }

        return $value;
    }

    private function fingerprint(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
