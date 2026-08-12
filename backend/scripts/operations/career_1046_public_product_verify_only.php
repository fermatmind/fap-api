<?php

declare(strict_types=1);

const CAREER_PUBLIC_VERIFY_CONTRACT = 'career.1046.public_product_verify_only.v1';
const CAREER_PUBLIC_VERIFY_POINTER_SCHEMA = 'career.generation_pointer.v1';
const CAREER_PUBLIC_VERIFY_MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';
const CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';
const CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';
const CAREER_PUBLIC_VERIFY_TARGET_COUNT = 1046;
const CAREER_PUBLIC_VERIFY_LOCALE_COUNT = 2092;
const CAREER_PUBLIC_VERIFY_MAX_POINTER_BYTES = 256_000;
const CAREER_PUBLIC_VERIFY_MAX_PRODUCT_BYTES = 268_435_456;
const CAREER_PUBLIC_VERIFY_HTTP_CONCURRENCY = 20;

final class Career1046PublicVerifyFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/** @var array<string, int> $careerPublicVerifyCounts */
$careerPublicVerifyCounts = careerPublicVerifyEmptyCounts();

try {
    $expected = careerPublicVerifyExpected();
    $release = careerPublicVerifyRelease($expected);
    $generation = careerPublicVerifyGeneration($expected, $release);
    $observed = careerPublicVerifyPublicProducts($expected, $generation, $release);
    $careerPublicVerifyCounts = $observed['counts'];
    careerPublicVerifyStableReadback($expected, $generation);

    careerPublicVerifyEmit([
        'contract_version' => CAREER_PUBLIC_VERIFY_CONTRACT,
        'status' => 'PASS_PUBLIC_PRODUCT_VERIFY_ONLY',
        'failed_stage' => null,
        'control_plane_sha' => $expected['control_plane_sha'],
        'release_sha' => $expected['release_sha'],
        'release_name_sha256' => hash('sha256', $expected['release_name']),
        'generation_id' => $expected['generation_id'],
        'workflow_run_id' => $expected['workflow_run_id'],
        'workflow_run_attempt' => $expected['workflow_run_attempt'],
        'active_pointer_sha256_before' => $generation['active_pointer_sha256'],
        'active_pointer_sha256_after' => $generation['active_pointer_sha256'],
        'immutable_pointer_sha256' => $generation['active_pointer_sha256'],
        'target_slug_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256,
        'target_locale_row_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256,
        'counts' => $careerPublicVerifyCounts,
        'production_read_only_evidence' => true,
        ...careerPublicVerifyNegativeGuarantees(),
    ]);
    exit(0);
} catch (Career1046PublicVerifyFailure $failure) {
    careerPublicVerifyEmit([
        'contract_version' => CAREER_PUBLIC_VERIFY_CONTRACT,
        'status' => 'FAIL_PUBLIC_PRODUCT_VERIFY_ONLY',
        'failed_stage' => $failure->safeCode,
        'control_plane_sha' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_CONTROL_PLANE_SHA'),
        'release_sha' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_RELEASE_SHA'),
        'release_name_sha256' => careerPublicVerifyOptionalIdentityHash('CAREER_PUBLIC_VERIFY_RELEASE_NAME'),
        'generation_id' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_GENERATION_ID'),
        'workflow_run_id' => careerPublicVerifyOptionalPositiveInt('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => careerPublicVerifyOptionalPositiveInt('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ATTEMPT'),
        'active_pointer_sha256_before' => null,
        'active_pointer_sha256_after' => null,
        'immutable_pointer_sha256' => null,
        'target_slug_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256,
        'target_locale_row_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256,
        'counts' => $careerPublicVerifyCounts,
        'production_read_only_evidence' => true,
        ...careerPublicVerifyNegativeGuarantees(),
    ]);
    exit(1);
} catch (Throwable) {
    careerPublicVerifyEmit([
        'contract_version' => CAREER_PUBLIC_VERIFY_CONTRACT,
        'status' => 'FAIL_PUBLIC_PRODUCT_VERIFY_ONLY',
        'failed_stage' => 'UNEXPECTED_VERIFY_FAILURE',
        'control_plane_sha' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_CONTROL_PLANE_SHA'),
        'release_sha' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_RELEASE_SHA'),
        'release_name_sha256' => careerPublicVerifyOptionalIdentityHash('CAREER_PUBLIC_VERIFY_RELEASE_NAME'),
        'generation_id' => careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_GENERATION_ID'),
        'workflow_run_id' => careerPublicVerifyOptionalPositiveInt('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => careerPublicVerifyOptionalPositiveInt('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ATTEMPT'),
        'active_pointer_sha256_before' => null,
        'active_pointer_sha256_after' => null,
        'immutable_pointer_sha256' => null,
        'target_slug_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256,
        'target_locale_row_set_sha256' => CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256,
        'counts' => $careerPublicVerifyCounts,
        'production_read_only_evidence' => true,
        ...careerPublicVerifyNegativeGuarantees(),
    ]);
    exit(1);
}

/** @return array<string, mixed> */
function careerPublicVerifyExpected(): array
{
    $baseUrl = rtrim(careerPublicVerifyRequiredEnv('CAREER_PUBLIC_VERIFY_API_BASE_URL'), '/');
    if (preg_match('#^https://[A-Za-z0-9.-]+$#', $baseUrl) !== 1
        && careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_HTTP_FIXTURE_FILE') === null) {
        throw new Career1046PublicVerifyFailure('PUBLIC_API_BASE_URL_INVALID');
    }

    return [
        'deploy_path' => careerPublicVerifyAbsolutePathEnv('CAREER_PUBLIC_VERIFY_DEPLOY_PATH'),
        'control_plane_sha' => careerPublicVerifyShaEnv('CAREER_PUBLIC_VERIFY_CONTROL_PLANE_SHA', 40),
        'release_sha' => careerPublicVerifyShaEnv('CAREER_PUBLIC_VERIFY_RELEASE_SHA', 40),
        'release_name' => careerPublicVerifyIdentityEnv('CAREER_PUBLIC_VERIFY_RELEASE_NAME'),
        'generation_id' => careerPublicVerifyGenerationIdEnv('CAREER_PUBLIC_VERIFY_GENERATION_ID'),
        'active_pointer_sha256' => careerPublicVerifyShaEnv('CAREER_PUBLIC_VERIFY_ACTIVE_POINTER_SHA256'),
        'runner_sha256' => careerPublicVerifyShaEnv('CAREER_PUBLIC_VERIFY_RUNNER_SHA256'),
        'workflow_run_id' => careerPublicVerifyPositiveIntEnv('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => careerPublicVerifyPositiveIntEnv('CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ATTEMPT'),
        'api_base_url' => $baseUrl,
    ];
}

/** @param array<string, mixed> $expected @return array<string, string> */
function careerPublicVerifyRelease(array $expected): array
{
    if ($expected['release_sha'] !== $expected['control_plane_sha']) {
        throw new Career1046PublicVerifyFailure('RELEASE_CONTROL_PLANE_SHA_MISMATCH');
    }
    $current = $expected['deploy_path'].'/current';
    $releasesRoot = realpath($expected['deploy_path'].'/releases');
    $releaseRoot = realpath($current);
    if (! is_string($releasesRoot)
        || ! is_string($releaseRoot)
        || basename($releaseRoot) !== $expected['release_name']
        || dirname($releaseRoot) !== $releasesRoot) {
        throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_IDENTITY_MISMATCH');
    }
    $revision = careerPublicVerifyReadFile($releaseRoot, $releaseRoot.'/REVISION', 128);
    if (trim($revision) !== $expected['release_sha']) {
        throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_REVISION_MISMATCH');
    }
    $runnerPath = $releaseRoot.'/backend/scripts/operations/career_1046_public_product_verify_only.php';
    $runner = careerPublicVerifyReadFile($releaseRoot, $runnerPath, 2_000_000);
    if (! hash_equals($expected['runner_sha256'], hash('sha256', $runner))) {
        throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_RUNNER_SHA256_MISMATCH');
    }

    return [
        'release_root' => $releaseRoot,
        'authority_root' => $releaseRoot.'/backend/storage/app/private/career_generation_authority',
        'signing_key' => careerPublicVerifySigningKey($releaseRoot),
    ];
}

/** @param array<string, mixed> $expected @param array<string, string> $release @return array<string, mixed> */
function careerPublicVerifyGeneration(array $expected, array $release): array
{
    $root = $release['authority_root'];
    $activePath = $root.'/active-generation.json';
    $activeRaw = careerPublicVerifyReadFile($root, $activePath, CAREER_PUBLIC_VERIFY_MAX_POINTER_BYTES);
    $activeSha = hash('sha256', $activeRaw);
    if (! hash_equals($expected['active_pointer_sha256'], $activeSha)) {
        throw new Career1046PublicVerifyFailure('ACTIVE_POINTER_SHA256_MISMATCH');
    }
    $active = careerPublicVerifyDecode($activeRaw, 'ACTIVE_POINTER_JSON_INVALID');
    $payload = $active['payload'] ?? null;
    if (($active['schema_version'] ?? null) !== CAREER_PUBLIC_VERIFY_POINTER_SCHEMA
        || ! is_array($payload)
        || ! is_string($active['payload_sha256'] ?? null)
        || ! hash_equals($active['payload_sha256'], careerPublicVerifyCanonicalSha($payload))
        || ($payload['generation_id'] ?? null) !== $expected['generation_id']
        || ($payload['artifact_format'] ?? null) !== 'generation_native_v1'
        || careerPublicVerifyDataGet($payload, 'counts.public_slug_count') !== CAREER_PUBLIC_VERIFY_TARGET_COUNT
        || careerPublicVerifyDataGet($payload, 'counts.public_locale_row_count') !== CAREER_PUBLIC_VERIFY_LOCALE_COUNT
        || careerPublicVerifyDataGet($payload, 'authority.frozen_manifest_sha256') !== CAREER_PUBLIC_VERIFY_MANIFEST_SHA256
        || careerPublicVerifyDataGet($payload, 'authority.target_slug_set_sha256') !== CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256
        || careerPublicVerifyDataGet($payload, 'authority.target_locale_row_set_sha256') !== CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256
        || careerPublicVerifyDataGet($payload, 'discoverability.sitemap_mutated') !== false
        || careerPublicVerifyDataGet($payload, 'discoverability.llms_mutated') !== false
        || careerPublicVerifyDataGet($payload, 'discoverability.search_mutated') !== false) {
        throw new Career1046PublicVerifyFailure('ACTIVE_POINTER_CONTRACT_INVALID');
    }
    $generationRoot = $root.'/generations/'.$expected['generation_id'];
    $immutableRaw = careerPublicVerifyReadFile(
        $root,
        $generationRoot.'/generation-pointer.json',
        CAREER_PUBLIC_VERIFY_MAX_POINTER_BYTES,
    );
    if (! hash_equals($activeSha, hash('sha256', $immutableRaw)) || ! hash_equals($activeRaw, $immutableRaw)) {
        throw new Career1046PublicVerifyFailure('ACTIVE_POINTER_IMMUTABLE_READBACK_MISMATCH');
    }

    $documents = [];
    foreach ([
        'generation_manifest' => 'generation-manifest.json',
        'directory_en' => 'career-directory-en.json',
        'directory_zh' => 'career-directory-zh.json',
        'detail_en' => 'career-job-details-en.json',
        'detail_zh' => 'career-job-details-zh.json',
    ] as $key => $filename) {
        $descriptor = careerPublicVerifyDataGet($payload, 'artifacts.'.$key);
        $expectedPath = 'generations/'.$expected['generation_id'].'/'.$filename;
        if (! is_array($descriptor)
            || ($descriptor['path'] ?? null) !== $expectedPath
            || ! is_string($descriptor['sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/', $descriptor['sha256']) !== 1) {
            throw new Career1046PublicVerifyFailure('PRODUCT_DESCRIPTOR_INVALID');
        }
        $raw = careerPublicVerifyReadFile($root, $root.'/'.$expectedPath, CAREER_PUBLIC_VERIFY_MAX_PRODUCT_BYTES);
        if (! hash_equals($descriptor['sha256'], hash('sha256', $raw))) {
            throw new Career1046PublicVerifyFailure('PRODUCT_DOCUMENT_SHA256_MISMATCH');
        }
        $documents[$key] = careerPublicVerifyDecode($raw, 'PRODUCT_DOCUMENT_JSON_INVALID');
    }

    $manifest = $documents['generation_manifest'];
    if (($manifest['schema_version'] ?? null) !== 'career.generation_manifest.v1'
        || ($manifest['generation_id'] ?? null) !== $expected['generation_id']
        || careerPublicVerifyDataGet($manifest, 'counts.unique_slugs') !== CAREER_PUBLIC_VERIFY_TARGET_COUNT
        || careerPublicVerifyDataGet($manifest, 'counts.locale_rows') !== CAREER_PUBLIC_VERIFY_LOCALE_COUNT
        || careerPublicVerifyDataGet($manifest, 'counts.missing') !== 0
        || careerPublicVerifyDataGet($manifest, 'counts.duplicate') !== 0
        || careerPublicVerifyDataGet($manifest, 'counts.outside_target') !== 0
        || careerPublicVerifyDataGet($manifest, 'discoverability.sitemap_released') !== false
        || careerPublicVerifyDataGet($manifest, 'discoverability.llms_released') !== false
        || careerPublicVerifyDataGet($manifest, 'discoverability.search_submission_enabled') !== false) {
        throw new Career1046PublicVerifyFailure('GENERATION_MANIFEST_CONTRACT_INVALID');
    }

    $detailPayloads = [];
    $targetSlugs = null;
    foreach (['en', 'zh'] as $locale) {
        $detail = $documents['detail_'.$locale];
        $directory = $documents['directory_'.$locale];
        if (($detail['schema_version'] ?? null) !== 'career.job_detail_generation.v1'
            || ($detail['generation_id'] ?? null) !== $expected['generation_id']
            || ($detail['locale'] ?? null) !== $locale
            || ($detail['count'] ?? null) !== CAREER_PUBLIC_VERIFY_TARGET_COUNT
            || ! is_array($detail['items'] ?? null)
            || ($directory['schema_version'] ?? null) !== 'career.directory_generation.v1'
            || ($directory['generation_id'] ?? null) !== $expected['generation_id']
            || ($directory['locale'] ?? null) !== $locale
            || ($directory['public_count'] ?? null) !== CAREER_PUBLIC_VERIFY_TARGET_COUNT
            || ! is_array($directory['items'] ?? null)) {
            throw new Career1046PublicVerifyFailure('GENERATION_PRODUCT_CONTRACT_INVALID');
        }
        $payloads = [];
        foreach ($detail['items'] as $row) {
            $slug = careerPublicVerifyRequiredSlug(is_array($row) ? ($row['slug'] ?? null) : null);
            if (isset($payloads[$slug]) || ! is_array($row['payload'] ?? null)) {
                throw new Career1046PublicVerifyFailure('GENERATION_DETAIL_DUPLICATE_OR_INVALID');
            }
            $payloads[$slug] = $row['payload'];
        }
        ksort($payloads, SORT_STRING);
        if (count($payloads) !== CAREER_PUBLIC_VERIFY_TARGET_COUNT) {
            throw new Career1046PublicVerifyFailure('GENERATION_DETAIL_COUNT_INVALID');
        }
        $directorySlugs = [];
        foreach ($directory['items'] as $row) {
            $slug = careerPublicVerifyRequiredSlug(is_array($row) ? ($row['slug'] ?? null) : null);
            if (isset($directorySlugs[$slug])
                || ($row['locale'] ?? null) !== $locale
                || ($row['detail_sha256'] ?? null) !== careerPublicVerifyCanonicalSha($payloads[$slug] ?? null)) {
                throw new Career1046PublicVerifyFailure('GENERATION_DIRECTORY_DUPLICATE_OR_MISMATCH');
            }
            $directorySlugs[$slug] = true;
        }
        $slugs = array_keys($payloads);
        if (array_keys($directorySlugs) !== $slugs) {
            $directoryKeys = array_keys($directorySlugs);
            sort($directoryKeys, SORT_STRING);
            if ($directoryKeys !== $slugs) {
                throw new Career1046PublicVerifyFailure('GENERATION_DIRECTORY_SET_MISMATCH');
            }
        }
        if ($targetSlugs !== null && $targetSlugs !== $slugs) {
            throw new Career1046PublicVerifyFailure('GENERATION_LOCALE_SLUG_SET_MISMATCH');
        }
        $targetSlugs = $slugs;
        $detailPayloads[$locale] = $payloads;
    }
    if (! is_array($targetSlugs)
        || ! hash_equals(CAREER_PUBLIC_VERIFY_TARGET_SET_SHA256, hash('sha256', implode("\n", $targetSlugs)."\n"))) {
        throw new Career1046PublicVerifyFailure('GENERATION_TARGET_SET_SHA256_MISMATCH');
    }
    $localeRows = [];
    foreach ($targetSlugs as $slug) {
        $localeRows[] = $slug.'|en';
        $localeRows[] = $slug.'|zh';
    }
    sort($localeRows, SORT_STRING);
    if (! hash_equals(CAREER_PUBLIC_VERIFY_TARGET_LOCALE_SET_SHA256, hash('sha256', implode("\n", $localeRows)."\n"))) {
        throw new Career1046PublicVerifyFailure('GENERATION_LOCALE_SET_SHA256_MISMATCH');
    }

    return [
        'authority_root' => $root,
        'active_path' => $activePath,
        'immutable_path' => $generationRoot.'/generation-pointer.json',
        'active_pointer_raw' => $activeRaw,
        'active_pointer_sha256' => $activeSha,
        'target_slugs' => $targetSlugs,
        'detail_payloads' => $detailPayloads,
    ];
}

/** @param array<string, mixed> $expected @param array<string, mixed> $generation @param array<string, string> $release @return array{counts:array<string,int>} */
function careerPublicVerifyPublicProducts(array $expected, array $generation, array $release): array
{
    $requests = [];
    foreach (['en' => 'en', 'zh' => 'zh-CN'] as $locale => $queryLocale) {
        for ($page = 1; $page <= 11; $page++) {
            $key = 'directory|'.$locale.'|'.$page;
            $requests[$key] = $expected['api_base_url'].'/api/v0.5/career/directory?locale='.$queryLocale.'&per_page=100&page='.$page;
        }
    }
    $directoryResponses = careerPublicVerifyFetch($requests, $release['signing_key']);
    $observedByLocale = ['en' => [], 'zh' => []];
    $duplicate = 0;
    $directoryHttpErrors = 0;
    $directoryNotFound = 0;
    $directoryServerError = 0;
    $directoryTimeout = 0;
    $directoryOtherStatus = 0;
    foreach ($directoryResponses as $key => $response) {
        [, $locale, $pageText] = explode('|', $key);
        $page = (int) $pageText;
        if ($response['timeout'] || $response['status'] !== 200) {
            $directoryHttpErrors++;
            if ($response['timeout']) {
                $directoryTimeout++;
            } elseif ($response['status'] === 404) {
                $directoryNotFound++;
            } elseif ($response['status'] >= 500 && $response['status'] <= 599) {
                $directoryServerError++;
            } else {
                $directoryOtherStatus++;
            }

            continue;
        }
        $body = careerPublicVerifyDecode($response['body'], 'PUBLIC_DIRECTORY_JSON_INVALID');
        if (careerPublicVerifyDataGet($body, 'pagination.page') !== $page
            || careerPublicVerifyDataGet($body, 'pagination.per_page') !== 100
            || careerPublicVerifyDataGet($body, 'pagination.total') !== CAREER_PUBLIC_VERIFY_TARGET_COUNT
            || careerPublicVerifyDataGet($body, 'pagination.total_pages') !== 11
            || ! is_array($body['items'] ?? null)) {
            throw new Career1046PublicVerifyFailure('PUBLIC_DIRECTORY_PAGINATION_INVALID');
        }
        foreach ($body['items'] as $item) {
            $slug = careerPublicVerifyRequiredSlug(is_array($item) ? ($item['slug'] ?? null) : null);
            if (isset($observedByLocale[$locale][$slug])) {
                $duplicate++;
            }
            $observedByLocale[$locale][$slug] = true;
        }
    }

    $expectedSet = array_fill_keys($generation['target_slugs'], true);
    $missing = 0;
    $extra = 0;
    foreach (['en', 'zh'] as $locale) {
        $missing += count(array_diff_key($expectedSet, $observedByLocale[$locale]));
        $extra += count(array_diff_key($observedByLocale[$locale], $expectedSet));
    }

    $detailRequests = [];
    foreach (['en' => 'en', 'zh' => 'zh-CN'] as $locale => $queryLocale) {
        foreach ($generation['target_slugs'] as $slug) {
            $detailRequests[$locale.'|'.$slug] = $expected['api_base_url'].'/api/v0.5/career/jobs/'.$slug.'?locale='.$queryLocale;
        }
    }
    $detailResponses = careerPublicVerifyFetch($detailRequests, $release['signing_key']);
    $http200 = 0;
    $notFound = $directoryNotFound;
    $serverError = $directoryServerError;
    $timeout = $directoryTimeout;
    $otherStatus = $directoryOtherStatus;
    $generationMismatch = 0;
    foreach ($detailResponses as $key => $response) {
        [$locale, $slug] = explode('|', $key, 2);
        if ($response['timeout']) {
            $timeout++;

            continue;
        }
        if ($response['status'] === 404) {
            $notFound++;

            continue;
        }
        if ($response['status'] >= 500 && $response['status'] <= 599) {
            $serverError++;

            continue;
        }
        if ($response['status'] !== 200) {
            $otherStatus++;

            continue;
        }
        $http200++;
        try {
            $payload = careerPublicVerifyDecode($response['body'], 'PUBLIC_DETAIL_JSON_INVALID');
            $expectedPayload = $generation['detail_payloads'][$locale][$slug] ?? null;
            if (! is_array($expectedPayload)
                || ! hash_equals(careerPublicVerifyCanonicalSha($expectedPayload), careerPublicVerifyCanonicalSha($payload))) {
                $generationMismatch++;
            }
        } catch (Career1046PublicVerifyFailure) {
            $generationMismatch++;
        }
    }

    $counts = [
        'directory_en' => count($observedByLocale['en']),
        'directory_zh' => count($observedByLocale['zh']),
        'detail_targets' => count($detailRequests),
        'detail_http_200' => $http200,
        'missing' => $missing,
        'duplicate' => $duplicate,
        'extra' => $extra,
        'directory_http_error' => $directoryHttpErrors,
        'http_404' => $notFound,
        'http_5xx' => $serverError,
        'timeout' => $timeout,
        'other_http_status' => $otherStatus,
        'generation_mismatch' => $generationMismatch,
    ];
    if ($counts !== [
        'directory_en' => CAREER_PUBLIC_VERIFY_TARGET_COUNT,
        'directory_zh' => CAREER_PUBLIC_VERIFY_TARGET_COUNT,
        'detail_targets' => CAREER_PUBLIC_VERIFY_LOCALE_COUNT,
        'detail_http_200' => CAREER_PUBLIC_VERIFY_LOCALE_COUNT,
        'missing' => 0,
        'duplicate' => 0,
        'extra' => 0,
        'directory_http_error' => 0,
        'http_404' => 0,
        'http_5xx' => 0,
        'timeout' => 0,
        'other_http_status' => 0,
        'generation_mismatch' => 0,
    ]) {
        $GLOBALS['careerPublicVerifyCounts'] = $counts;
        throw new Career1046PublicVerifyFailure('PUBLIC_PRODUCT_COUNTS_NOT_EXACT');
    }

    return ['counts' => $counts];
}

/** @param array<string, mixed> $expected @param array<string, mixed> $generation */
function careerPublicVerifyStableReadback(array $expected, array $generation): void
{
    $active = careerPublicVerifyReadFile(
        $generation['authority_root'],
        $generation['active_path'],
        CAREER_PUBLIC_VERIFY_MAX_POINTER_BYTES,
    );
    $immutable = careerPublicVerifyReadFile(
        $generation['authority_root'],
        $generation['immutable_path'],
        CAREER_PUBLIC_VERIFY_MAX_POINTER_BYTES,
    );
    if (! hash_equals($generation['active_pointer_raw'], $active)
        || ! hash_equals($generation['active_pointer_raw'], $immutable)
        || ! hash_equals($expected['active_pointer_sha256'], hash('sha256', $active))) {
        throw new Career1046PublicVerifyFailure('ACTIVE_POINTER_DRIFT_DURING_VERIFY');
    }
}

/** @param array<string, string> $requests @return array<string, array{status:int,body:string,timeout:bool}> */
function careerPublicVerifyFetch(array $requests, string $signingKey): array
{
    $fixturePath = careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_HTTP_FIXTURE_FILE');
    if ($fixturePath !== null) {
        $raw = file_get_contents($fixturePath);
        if (! is_string($raw)) {
            throw new Career1046PublicVerifyFailure('HTTP_FIXTURE_UNREADABLE');
        }
        $fixture = careerPublicVerifyDecode($raw, 'HTTP_FIXTURE_JSON_INVALID');
        $responses = [];
        foreach ($requests as $key => $url) {
            $entry = $fixture[$url] ?? null;
            if (! is_array($entry) || ! is_int($entry['status'] ?? null) || ! is_bool($entry['timeout'] ?? null)) {
                throw new Career1046PublicVerifyFailure('HTTP_FIXTURE_TARGET_MISSING');
            }
            $body = $entry['body'] ?? null;
            $responses[$key] = [
                'status' => $entry['status'],
                'timeout' => $entry['timeout'],
                'body' => is_string($body) ? $body : careerPublicVerifyCanonicalJson($body),
            ];
        }

        return $responses;
    }
    if (! function_exists('curl_multi_init')) {
        throw new Career1046PublicVerifyFailure('HTTP_CLIENT_UNAVAILABLE');
    }

    $responses = [];
    foreach (array_chunk($requests, CAREER_PUBLIC_VERIFY_HTTP_CONCURRENCY, true) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        $timestamp = (string) time();
        foreach ($batch as $key => $url) {
            $parts = parse_url($url);
            $requestUri = is_array($parts) && is_string($parts['path'] ?? null)
                ? $parts['path'].(is_string($parts['query'] ?? null) ? '?'.$parts['query'] : '')
                : '';
            if ($requestUri === '') {
                throw new Career1046PublicVerifyFailure('PUBLIC_REQUEST_URI_INVALID');
            }
            $signature = hash_hmac('sha256', "GET\n{$requestUri}\n{$timestamp}", $signingKey);
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-Fermat-Career-Verify-Only: 1',
                    'X-Fermat-Career-Verify-Timestamp: '.$timestamp,
                    'X-Fermat-Career-Verify-Signature: '.$signature,
                ],
                CURLOPT_USERAGENT => 'FermatMind-Career1046-VerifyOnly/1.0',
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        foreach ($handles as $key => $handle) {
            $body = curl_multi_getcontent($handle);
            $errno = curl_errno($handle);
            $responses[$key] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => is_string($body) && strlen($body) <= 2_000_000 ? $body : '',
                'timeout' => $errno === CURLE_OPERATION_TIMEDOUT,
            ];
            if ($errno !== 0 && $errno !== CURLE_OPERATION_TIMEDOUT) {
                $responses[$key]['status'] = 0;
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
    }

    return $responses;
}

function careerPublicVerifySigningKey(string $releaseRoot): string
{
    if (careerPublicVerifySafeEnv('CAREER_PUBLIC_VERIFY_HTTP_FIXTURE_FILE') !== null) {
        return careerPublicVerifyRequiredEnv('CAREER_PUBLIC_VERIFY_SIGNING_KEY');
    }

    $backendRoot = $releaseRoot.'/backend';
    $autoload = $backendRoot.'/vendor/autoload.php';
    $bootstrap = $backendRoot.'/bootstrap/app.php';
    if (! is_file($autoload) || ! is_file($bootstrap)) {
        throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_BOOTSTRAP_UNAVAILABLE');
    }
    $previous = getcwd();
    try {
        if (! chdir($backendRoot)) {
            throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_BOOTSTRAP_UNAVAILABLE');
        }
        require_once $autoload;
        $app = require $bootstrap;
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $key = (string) $app['config']->get('app.key', '');
    } catch (Career1046PublicVerifyFailure $failure) {
        throw $failure;
    } catch (Throwable) {
        throw new Career1046PublicVerifyFailure('ACTIVE_RELEASE_BOOTSTRAP_FAILED');
    } finally {
        if (is_string($previous)) {
            chdir($previous);
        }
    }
    if (strlen($key) < 16) {
        throw new Career1046PublicVerifyFailure('VERIFY_SIGNING_KEY_INVALID');
    }

    return $key;
}

/** @return array<string, int> */
function careerPublicVerifyEmptyCounts(): array
{
    return [
        'directory_en' => 0,
        'directory_zh' => 0,
        'detail_targets' => 0,
        'detail_http_200' => 0,
        'missing' => 0,
        'duplicate' => 0,
        'extra' => 0,
        'directory_http_error' => 0,
        'http_404' => 0,
        'http_5xx' => 0,
        'timeout' => 0,
        'other_http_status' => 0,
        'generation_mismatch' => 0,
    ];
}

/** @return array<string, bool|int> */
function careerPublicVerifyNegativeGuarantees(): array
{
    return [
        'repair_count' => 0,
        'warm_count' => 0,
        'rollback_count' => 0,
        'deploy_count' => 0,
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'cache_write_count' => 0,
        'pointer_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
        'writes_committed' => false,
    ];
}

function careerPublicVerifyReadFile(string $root, string $path, int $maxBytes): string
{
    if (is_link($path) || ! is_file($path)) {
        throw new Career1046PublicVerifyFailure('READ_ONLY_FILE_INVALID');
    }
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if (! is_string($rootReal) || ! is_string($pathReal)
        || ($pathReal !== $rootReal && ! str_starts_with($pathReal, $rootReal.DIRECTORY_SEPARATOR))) {
        throw new Career1046PublicVerifyFailure('READ_ONLY_FILE_OUTSIDE_ROOT');
    }
    $size = filesize($pathReal);
    if (! is_int($size) || $size < 1 || $size > $maxBytes) {
        throw new Career1046PublicVerifyFailure('READ_ONLY_FILE_SIZE_INVALID');
    }
    $raw = file_get_contents($pathReal);
    if (! is_string($raw) || strlen($raw) !== $size) {
        throw new Career1046PublicVerifyFailure('READ_ONLY_FILE_READ_FAILED');
    }

    return $raw;
}

/** @return array<string, mixed> */
function careerPublicVerifyDecode(string $raw, string $safeCode): array
{
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new Career1046PublicVerifyFailure($safeCode);
    }
    if (! is_array($decoded)) {
        throw new Career1046PublicVerifyFailure($safeCode);
    }

    return $decoded;
}

function careerPublicVerifyCanonicalSha(mixed $value): string
{
    return hash('sha256', careerPublicVerifyCanonicalJson($value));
}

function careerPublicVerifyDataGet(array $payload, string $path): mixed
{
    $value = $payload;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function careerPublicVerifyCanonicalJson(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (! is_array($item)) {
            return $item;
        }
        if (! array_is_list($item)) {
            ksort($item, SORT_STRING);
        }
        foreach ($item as $key => $child) {
            $item[$key] = $normalize($child);
        }

        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

function careerPublicVerifyRequiredSlug(mixed $value): string
{
    if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
        throw new Career1046PublicVerifyFailure('PUBLIC_PRODUCT_SLUG_INVALID');
    }

    return $value;
}

function careerPublicVerifyRequiredEnv(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        throw new Career1046PublicVerifyFailure('ENVIRONMENT_INVALID');
    }

    return trim($value);
}

function careerPublicVerifyAbsolutePathEnv(string $name): string
{
    $value = careerPublicVerifyRequiredEnv($name);
    if (preg_match('#^/[A-Za-z0-9._/-]+$#', $value) !== 1 || str_contains($value, '..')) {
        throw new Career1046PublicVerifyFailure('ABSOLUTE_PATH_INVALID');
    }

    return rtrim($value, '/');
}

function careerPublicVerifyShaEnv(string $name, int $length = 64): string
{
    $value = careerPublicVerifyRequiredEnv($name);
    if (preg_match('/^[0-9a-f]{'.$length.'}$/', $value) !== 1) {
        throw new Career1046PublicVerifyFailure('SHA256_ENV_INVALID');
    }

    return $value;
}

function careerPublicVerifyIdentityEnv(string $name): string
{
    $value = careerPublicVerifyRequiredEnv($name);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) !== 1) {
        throw new Career1046PublicVerifyFailure('IDENTITY_ENV_INVALID');
    }

    return $value;
}

function careerPublicVerifyGenerationIdEnv(string $name): string
{
    $value = careerPublicVerifyRequiredEnv($name);
    if (preg_match('/^career-1046-[0-9a-f]{32}$/', $value) !== 1) {
        throw new Career1046PublicVerifyFailure('GENERATION_ID_INVALID');
    }

    return $value;
}

function careerPublicVerifyPositiveIntEnv(string $name): int
{
    $value = careerPublicVerifyRequiredEnv($name);
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new Career1046PublicVerifyFailure('POSITIVE_INTEGER_ENV_INVALID');
    }

    return (int) $value;
}

function careerPublicVerifySafeEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function careerPublicVerifyOptionalIdentityHash(string $name): ?string
{
    $value = careerPublicVerifySafeEnv($name);

    return $value === null ? null : hash('sha256', $value);
}

function careerPublicVerifyOptionalPositiveInt(string $name): ?int
{
    $value = careerPublicVerifySafeEnv($name);

    return is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : null;
}

/** @param array<string, mixed> $receipt */
function careerPublicVerifyEmit(array $receipt): void
{
    echo careerPublicVerifyCanonicalJson($receipt)."\n";
}
