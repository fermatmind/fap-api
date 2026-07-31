<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Import\CareerSelectedDisplayAssetMapper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const CONTRACT_VERSION = 'career.search_entry_batch.thin_authority_repair_probe.v1';
const EXPECTED_WORKBOOK_SHA256 = 'c30f8743cfd0d8baa14ac931cc7270807425164952f6a44953b5b4ab448778ef';
const EXPECTED_WORKBOOK_BASENAME = 'fermat_career_assets_v4_2_v9_d23b_schema_repaired.xlsx';
const EXPECTED_MANIFEST_POSITIONS = [30, 33, 34, 40, 42];
const EXPECTED_REPAIR_COUNT = 5;
const EXPECTED_COMPONENT_COUNT = 24;

/** @param array<string, mixed> $extra */
function emitRepair(array $extra, int $exitCode = 0): never
{
    echo json_encode([
        'contract_version' => CONTRACT_VERSION,
        ...$extra,
        'cache_write_count' => 0,
        'cms_write_count' => 0,
        'publication_write_count' => 0,
        'indexability_write_count' => 0,
        'queue_dispatch_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_channel_action_count' => 0,
        'url_submission_count' => 0,
        'deploy_count' => 0,
        'rollback_count' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($exitCode);
}

function requiredRepairEnv(string $name, string $pattern): string
{
    $value = trim((string) getenv($name));
    if ($value === '' || preg_match($pattern, $value) !== 1) {
        throw new RuntimeException('INVALID_INPUT');
    }

    return $value;
}

function canonicalRepairJson(mixed $value): string
{
    if (is_array($value)) {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = json_decode(canonicalRepairJson($item), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function repairManifestSlugs(string $currentRelease, string $manifestSha256): array
{
    $manifestPath = $currentRelease.'/backend/content_packs/career/CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json';
    if (! is_file($manifestPath) || hash_file('sha256', $manifestPath) !== $manifestSha256) {
        throw new RuntimeException('MANIFEST_DRIFT');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $candidates = is_array($manifest['candidates'] ?? null) ? $manifest['candidates'] : [];
    $slugs = array_values(array_map(
        static fn (array $candidate): string => strtolower(trim((string) ($candidate['canonical_slug'] ?? ''))),
        $candidates,
    ));
    if (
        ($manifest['schema_version'] ?? null) !== 'career.search_entry_quality_batch_manifest.v1'
        || ($manifest['task_id'] ?? null) !== 'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01'
        || count($slugs) !== 50
        || count(array_unique($slugs)) !== 50
        || in_array('', $slugs, true)
    ) {
        throw new RuntimeException('MANIFEST_CONTRACT_DRIFT');
    }

    return $slugs;
}

function approvedWorkbookPath(string $deployPath): ?string
{
    $candidates = [
        rtrim($deployPath, '/').'/shared/private/career-assets/'.EXPECTED_WORKBOOK_BASENAME,
        rtrim($deployPath, '/').'/shared/career-assets/'.EXPECTED_WORKBOOK_BASENAME,
        '/tmp/'.EXPECTED_WORKBOOK_BASENAME,
    ];
    $matches = [];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)
            && hash_file('sha256', $candidate) === EXPECTED_WORKBOOK_SHA256) {
            $matches[] = $candidate;
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
}

/** @return array<string, array<string, mixed>> */
function workbookRows(
    CareerSelectedDisplayAssetMapper $mapper,
    string $workbook,
    array $targetSlugs,
): array {
    $workbookData = $mapper->readWorkbook($workbook, $targetSlugs);
    $rows = [];
    foreach ($workbookData['rows'] as $row) {
        $slug = strtolower(trim((string) ($row['Slug'] ?? '')));
        if (in_array($slug, $targetSlugs, true)) {
            if (isset($rows[$slug])) {
                throw new RuntimeException('WORKBOOK_TARGET_DUPLICATE');
            }
            $rows[$slug] = $row;
        }
    }
    if (count($rows) !== EXPECTED_REPAIR_COUNT) {
        throw new RuntimeException('WORKBOOK_TARGET_INCOMPLETE');
    }

    return $rows;
}

/** @return array{items: list<array<string, mixed>>, payload_set_sha256: string, repair_set_sha256: string} */
function planRepair(
    CareerSelectedDisplayAssetMapper $mapper,
    array $manifestSlugs,
    array $rows,
): array {
    $items = [];
    $repairSet = [];
    foreach (EXPECTED_MANIFEST_POSITIONS as $position) {
        $slug = $manifestSlugs[$position - 1] ?? '';
        $row = $rows[$slug] ?? null;
        if (! is_array($row)) {
            throw new RuntimeException('WORKBOOK_TARGET_INCOMPLETE');
        }
        $occupationRows = Occupation::query()
            ->with('crosswalks')
            ->where('canonical_slug', $slug)
            ->get();
        if ($occupationRows->count() !== 1) {
            throw new RuntimeException('OCCUPATION_IDENTITY_DRIFT');
        }
        /** @var Occupation $occupation */
        $occupation = $occupationRows->first();
        $expectedSoc = trim((string) ($row['SOC_Code'] ?? ''));
        $expectedOnet = trim((string) ($row['O_NET_Code'] ?? ''));
        $crosswalkCounts = ['us_soc' => 0, 'onet_soc_2019' => 0];
        foreach ($occupation->crosswalks as $crosswalk) {
            $system = strtolower(trim((string) $crosswalk->source_system));
            $code = trim((string) $crosswalk->source_code);
            if ($system === 'us_soc' && $code === $expectedSoc) {
                $crosswalkCounts['us_soc']++;
            }
            if ($system === 'onet_soc_2019' && $code === $expectedOnet) {
                $crosswalkCounts['onet_soc_2019']++;
            }
        }
        if ($crosswalkCounts !== ['us_soc' => 1, 'onet_soc_2019' => 1]) {
            throw new RuntimeException('CROSSWALK_AUTHORITY_DRIFT');
        }
        $existing = CareerJobDisplayAsset::query()
            ->where('occupation_id', $occupation->id)
            ->where('canonical_slug', $slug)
            ->where('surface_version', CareerSelectedDisplayAssetMapper::SURFACE_VERSION)
            ->where('asset_version', CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION)
            ->where('template_version', CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION)
            ->where('status', CareerSelectedDisplayAssetMapper::STATUS)
            ->where('asset_type', CareerSelectedDisplayAssetMapper::ASSET_TYPE)
            ->count();
        if ($existing !== 0) {
            throw new RuntimeException('REPAIR_TARGET_STATE_DRIFT');
        }
        $mapped = $mapper->mapRow($row, ['soc' => $expectedSoc, 'onet' => $expectedOnet]);
        if (($mapped['errors'] ?? []) !== []
            || data_get($mapped, 'summary.publish_gate.decision') !== 'pass'
            || (int) data_get($mapped, 'summary.component_order_count') !== EXPECTED_COMPONENT_COUNT
            || data_get($mapped, 'summary.has_en_page') !== true
            || data_get($mapped, 'summary.has_zh_page') !== true) {
            throw new RuntimeException('REVIEWED_WORKBOOK_ROW_INVALID');
        }
        $payload = is_array($mapped['payload'] ?? null) ? $mapped['payload'] : [];
        $payloadSha = hash('sha256', canonicalRepairJson($payload));
        $items[] = [
            'manifest_position' => $position,
            'slug' => $slug,
            'occupation' => $occupation,
            'payload' => $payload,
            'payload_sha256' => $payloadSha,
            'row_number' => $mapped['row_number'],
            'workbook_row_sha256' => CareerSelectedDisplayAssetMapper::workbookRowAuthorityHash($row),
        ];
        $repairSet[] = [
            'manifest_position' => $position,
            'payload_sha256' => $payloadSha,
            'workbook_row_sha256' => CareerSelectedDisplayAssetMapper::workbookRowAuthorityHash($row),
        ];
    }

    return [
        'items' => $items,
        'payload_set_sha256' => hash('sha256', canonicalRepairJson(array_map(
            static fn (array $item): array => [
                'manifest_position' => $item['manifest_position'],
                'payload_sha256' => $item['payload_sha256'],
            ],
            $items,
        ))),
        'repair_set_sha256' => hash('sha256', canonicalRepairJson($repairSet)),
    ];
}

function installReadOnlyGuard(object $app): void
{
    $connection = $app->make('db')->connection();
    if (! method_exists($connection, 'beforeExecuting')) {
        throw new RuntimeException('DATABASE_GUARD_UNAVAILABLE');
    }
    $connection->beforeExecuting(static function (string $query): void {
        $normalized = strtolower(ltrim(preg_replace('/\s+/', ' ', $query) ?? $query));
        if (preg_match('/^(select|with|show|describe|desc|explain|pragma)\b/D', $normalized) !== 1) {
            throw new RuntimeException('DATABASE_WRITE_BLOCKED');
        }
    });
}

function installRepairGuard(object $app): void
{
    $connection = $app->make('db')->connection();
    if (! method_exists($connection, 'beforeExecuting')) {
        throw new RuntimeException('DATABASE_GUARD_UNAVAILABLE');
    }
    $connection->beforeExecuting(static function (string $query): void {
        $normalized = strtolower(ltrim(preg_replace('/\s+/', ' ', $query) ?? $query));
        if (preg_match('/^(select|with|show|describe|desc|explain|pragma)\b/D', $normalized) === 1) {
            return;
        }
        if (preg_match('/^insert into [`"]?career_job_display_assets[`"]?\b/D', $normalized) === 1) {
            return;
        }
        throw new RuntimeException('NON_TARGET_DATABASE_WRITE_BLOCKED');
    });
}

/** @param list<string> $targetSlugs */
function nonTargetStateSha256(array $targetSlugs): string
{
    $context = hash_init('sha256');
    foreach (CareerJobDisplayAsset::query()
        ->whereNotIn('canonical_slug', $targetSlugs)
        ->orderBy('id')
        ->cursor() as $asset) {
        hash_update($context, canonicalRepairJson([
            'id' => (string) $asset->id,
            'occupation_id' => (string) $asset->occupation_id,
            'canonical_slug' => (string) $asset->canonical_slug,
            'surface_version' => (string) $asset->surface_version,
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
            'status' => (string) $asset->status,
            'updated_at' => optional($asset->updated_at)->toISOString(),
        ])."\n");
    }

    return hash_final($context);
}

/** @param array<string, mixed> $value */
function hrefsLocaleSafe(array $value, string $locale): bool
{
    $expected = $locale === 'en' ? '/en/' : '/zh/';
    $other = $locale === 'en' ? '/zh/' : '/en/';
    $safe = true;
    array_walk_recursive($value, static function (mixed $item, mixed $key) use (&$safe, $expected, $other): void {
        if ($key !== 'href' || ! is_string($item) || trim($item) === '') {
            return;
        }
        if (str_starts_with(trim($item), $other) && ! str_starts_with(trim($item), $expected)) {
            $safe = false;
        }
    });

    return $safe;
}

$mode = trim((string) getenv('CAREER_THIN_AUTHORITY_REPAIR_MODE'));
$releaseSha = trim((string) getenv('EXPECTED_RELEASE_SHA'));
$releaseName = trim((string) getenv('EXPECTED_RELEASE_NAME'));
$manifestSha256 = trim((string) getenv('EXPECTED_MANIFEST_SHA256'));

try {
    if (! in_array($mode, ['preflight', 'repair'], true)) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $deployPath = requiredRepairEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $releaseSha = requiredRepairEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredRepairEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredRepairEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    $expectedRepairSet = $mode === 'repair'
        ? requiredRepairEnv('EXPECTED_REPAIR_SET_SHA256', '/^[0-9a-f]{64}$/D')
        : null;
    $expectedPayloadSet = $mode === 'repair'
        ? requiredRepairEnv('EXPECTED_REPAIR_PAYLOAD_SET_SHA256', '/^[0-9a-f]{64}$/D')
        : null;
    $currentRelease = realpath(rtrim($deployPath, '/').'/current');
    if (
        $currentRelease === false
        || basename($currentRelease) !== $releaseName
        || trim((string) file_get_contents($currentRelease.'/REVISION')) !== $releaseSha
        || file_exists(rtrim($deployPath, '/').'/.dep/deploy.lock')
    ) {
        throw new RuntimeException('ACTIVE_RELEASE_IDENTITY_DRIFT');
    }
    $manifestSlugs = repairManifestSlugs($currentRelease, $manifestSha256);
    $backendPath = $currentRelease.'/backend';
    chdir($backendPath);
    require $backendPath.'/vendor/autoload.php';
    $app = require $backendPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if ($mode === 'preflight') {
        installReadOnlyGuard($app);
    } else {
        installRepairGuard($app);
    }

    $workbook = approvedWorkbookPath($deployPath);
    if ($workbook === null) {
        emitRepair([
            'status' => 'HOLD_APPROVED_WORKBOOK_UNAVAILABLE',
            'mode' => $mode,
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'workbook_sha256' => EXPECTED_WORKBOOK_SHA256,
            'manifest_positions' => EXPECTED_MANIFEST_POSITIONS,
            'repair_count' => 0,
            'server_write_count' => 0,
            'database_write_count' => 0,
            'write_state' => 'none',
        ]);
    }
    $mapper = $app->make(CareerSelectedDisplayAssetMapper::class);
    $targetSlugs = array_map(
        static fn (int $position): string => $manifestSlugs[$position - 1],
        EXPECTED_MANIFEST_POSITIONS,
    );
    $rows = workbookRows($mapper, $workbook, $targetSlugs);
    $plan = planRepair($mapper, $manifestSlugs, $rows);
    if ($mode === 'preflight') {
        emitRepair([
            'status' => 'PASS_AUTHORITY_REPAIR_PREFLIGHT',
            'mode' => $mode,
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'workbook_sha256' => EXPECTED_WORKBOOK_SHA256,
            'manifest_positions' => EXPECTED_MANIFEST_POSITIONS,
            'repair_count' => EXPECTED_REPAIR_COUNT,
            'repair_set_sha256' => $plan['repair_set_sha256'],
            'repair_payload_set_sha256' => $plan['payload_set_sha256'],
            'server_write_count' => 0,
            'database_write_count' => 0,
            'write_state' => 'none',
        ]);
    }
    if ($plan['repair_set_sha256'] !== $expectedRepairSet
        || $plan['payload_set_sha256'] !== $expectedPayloadSet) {
        throw new RuntimeException('PREFLIGHT_PLAN_DRIFT');
    }

    $nonTargetBefore = nonTargetStateSha256($targetSlugs);
    $result = DB::transaction(function () use ($plan, $workbook, $app, $targetSlugs, $nonTargetBefore): array {
        $written = 0;
        foreach ($plan['items'] as $item) {
            /** @var Occupation $occupation */
            $occupation = $item['occupation'];
            $payload = $item['payload'];
            CareerJobDisplayAsset::query()->create([
                'occupation_id' => $occupation->id,
                'canonical_slug' => $item['slug'],
                'surface_version' => CareerSelectedDisplayAssetMapper::SURFACE_VERSION,
                'asset_version' => CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION,
                'template_version' => CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION,
                'asset_type' => CareerSelectedDisplayAssetMapper::ASSET_TYPE,
                'asset_role' => CareerSelectedDisplayAssetMapper::ASSET_ROLE,
                'status' => CareerSelectedDisplayAssetMapper::STATUS,
                'component_order_json' => $payload['component_order_json'],
                'page_payload_json' => $payload['page_payload_json'],
                'seo_payload_json' => $payload['seo_payload_json'],
                'sources_json' => $payload['sources_json'],
                'structured_data_json' => $payload['structured_data_json'],
                'implementation_contract_json' => $payload['implementation_contract_json'],
                'metadata_json' => [
                    'command' => 'career:import-selected-display-assets',
                    'repair_control' => 'career-search-entry-thin-authority-repair',
                    'validator_version' => 'career_selected_display_asset_import_v0.1',
                    'mapper_version' => CareerSelectedDisplayAssetMapper::MAPPER_VERSION,
                    'source_authority' => 'reviewed_workbook',
                    'workbook_basename' => basename($workbook),
                    'workbook_sha256' => EXPECTED_WORKBOOK_SHA256,
                    'row_number' => $item['row_number'],
                    'workbook_row_sha256' => $item['workbook_row_sha256'],
                    'repair_set_sha256' => $plan['repair_set_sha256'],
                    'imported_at' => now()->toISOString(),
                    'display_import_stage' => 'search_entry_thin_authority_repair',
                    'release_gates' => [
                        'sitemap' => false,
                        'llms' => false,
                        'paid' => false,
                        'backlink' => false,
                    ],
                ],
            ]);
            $written++;
        }
        if ($written !== EXPECTED_REPAIR_COUNT) {
            throw new RuntimeException('REPAIR_WRITE_COUNT_INVALID');
        }
        $surfaceBuilder = $app->make(CareerJobDisplaySurfaceBuilder::class);
        $verification = [];
        foreach ($plan['items'] as $item) {
            /** @var Occupation $occupation */
            $occupation = $item['occupation']->refresh();
            foreach (['en', 'zh-CN'] as $locale) {
                $surface = $surfaceBuilder->buildForOccupation($occupation, $locale);
                $content = is_array(data_get($surface, 'page.content'))
                    ? data_get($surface, 'page.content')
                    : [];
                if (! is_array($surface)
                    || count((array) ($surface['component_order'] ?? [])) !== EXPECTED_COMPONENT_COUNT
                    || count($content) < 20
                    || ! hrefsLocaleSafe($content, $locale)) {
                    throw new RuntimeException('POST_WRITE_SURFACE_VERIFICATION_FAILED');
                }
                $verification[] = [
                    'manifest_position' => $item['manifest_position'],
                    'locale' => $locale,
                    'module_count' => count($content),
                    'surface_sha256' => hash('sha256', canonicalRepairJson($surface)),
                ];
            }
        }

        $nonTargetAfter = nonTargetStateSha256($targetSlugs);
        if ($nonTargetBefore !== $nonTargetAfter) {
            throw new RuntimeException('NON_TARGET_STATE_DRIFT');
        }

        return [
            'written_count' => $written,
            'verification' => $verification,
            'verification_sha256' => hash('sha256', canonicalRepairJson($verification)),
            'non_target_state_sha256' => $nonTargetAfter,
        ];
    });

    emitRepair([
        'status' => 'PASS_AUTHORITY_REPAIR_COMPLETE',
        'mode' => $mode,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'workbook_sha256' => EXPECTED_WORKBOOK_SHA256,
        'manifest_positions' => EXPECTED_MANIFEST_POSITIONS,
        'repair_count' => EXPECTED_REPAIR_COUNT,
        'repair_set_sha256' => $plan['repair_set_sha256'],
        'repair_payload_set_sha256' => $plan['payload_set_sha256'],
        'verification_sha256' => $result['verification_sha256'],
        'verification_rows' => $result['verification'],
        'non_target_state_sha256' => $result['non_target_state_sha256'],
        'server_write_count' => 0,
        'database_write_count' => $result['written_count'],
        'write_state' => 'committed_verified',
    ]);
} catch (Throwable $throwable) {
    emitRepair([
        'status' => 'FAIL_CLOSED',
        'mode' => in_array($mode, ['preflight', 'repair'], true) ? $mode : null,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'failure_category' => in_array($throwable->getMessage(), [
            'INVALID_INPUT',
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'MANIFEST_DRIFT',
            'MANIFEST_CONTRACT_DRIFT',
            'DATABASE_GUARD_UNAVAILABLE',
            'DATABASE_WRITE_BLOCKED',
            'NON_TARGET_DATABASE_WRITE_BLOCKED',
            'WORKBOOK_TARGET_DUPLICATE',
            'WORKBOOK_TARGET_INCOMPLETE',
            'OCCUPATION_IDENTITY_DRIFT',
            'CROSSWALK_AUTHORITY_DRIFT',
            'REPAIR_TARGET_STATE_DRIFT',
            'REVIEWED_WORKBOOK_ROW_INVALID',
            'PREFLIGHT_PLAN_DRIFT',
            'REPAIR_WRITE_COUNT_INVALID',
            'POST_WRITE_SURFACE_VERIFICATION_FAILED',
            'NON_TARGET_STATE_DRIFT',
        ], true) ? $throwable->getMessage() : 'unexpected',
        'server_write_count' => 0,
        'database_write_count' => 0,
        'write_state' => 'none_or_transaction_rolled_back',
    ], 1);
}
