<?php

declare(strict_types=1);

use App\Domain\Career\Audit\CareerDetailReadyPublicationCandidateScanner;
use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use Illuminate\Contracts\Console\Kernel;

const CAREER_1046_EVIDENCE_CONTRACT = 'career.1046_rollout.authority_evidence.v1';
const CAREER_1046_MANIFEST_FILENAME = 'detail-ready-1046-rollout-manifest.v1.json';
const CAREER_1046_PROJECTION_FILENAME = 'career-runtime-publish-projection.json';
const CAREER_1046_LEDGER_FILENAME = 'career-full-release-ledger.json';
const CAREER_1046_PUBLIC_TYPE = 'public_canonical_job';
const CAREER_1046_PUBLISHED_STATE = 'published';

/** @param list<string> $values */
function career1046SetHash(array $values): string
{
    $normalized = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): string => strtolower(trim((string) $value)),
        $values,
    ))));
    sort($normalized, SORT_STRING);

    return hash('sha256', implode("\n", $normalized)."\n");
}

/** @return list<string> */
function career1046SlugList(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException('SLUG_LIST_INVALID');
    }

    $slugs = array_values(array_unique(array_filter(array_map(
        static fn (mixed $slug): string => strtolower(trim((string) $slug)),
        $value,
    ))));
    sort($slugs, SORT_STRING);

    return $slugs;
}

/** @param list<string> $left @param list<string> $right @return list<string> */
function career1046Diff(array $left, array $right): array
{
    $result = array_values(array_diff($left, $right));
    sort($result, SORT_STRING);

    return $result;
}

/** @param list<string> $left @param list<string> $right @return list<string> */
function career1046Intersect(array $left, array $right): array
{
    $result = array_values(array_intersect($left, $right));
    sort($result, SORT_STRING);

    return $result;
}

function career1046Locale(mixed $locale): ?string
{
    $normalized = strtolower(trim((string) $locale));

    return match (true) {
        $normalized === 'en' => 'en',
        str_starts_with($normalized, 'zh') => 'zh',
        default => null,
    };
}

/** @return array{sha256:string,payload:array<string,mixed>} */
function career1046LatestArtifact(string $root, string $filename): array
{
    $directories = is_dir($root) ? glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) : false;
    if (! is_array($directories) || $directories === []) {
        throw new RuntimeException('MATERIALIZED_ARTIFACT_MISSING');
    }

    $candidates = [];
    foreach ($directories as $directory) {
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        clearstatcache(true, $directory);
        clearstatcache(true, $path);
        $candidates[] = [
            'path' => $path,
            'mtime' => is_file($path) ? ((int) (@filemtime($path) ?: 0)) : ((int) (@filemtime($directory) ?: 0)),
        ];
    }
    usort($candidates, static fn (array $left, array $right): int => ($right['mtime'] <=> $left['mtime']) ?: strcmp((string) $right['path'], (string) $left['path'])
    );

    $path = (string) ($candidates[0]['path'] ?? '');
    if (! is_file($path) || ! is_readable($path) || is_link($path)) {
        throw new RuntimeException('LATEST_MATERIALIZED_ARTIFACT_UNREADABLE');
    }
    $bytes = file_get_contents($path);
    $payload = is_string($bytes) ? json_decode($bytes, true) : null;
    if (! is_string($bytes) || ! is_array($payload)) {
        throw new RuntimeException('LATEST_MATERIALIZED_ARTIFACT_INVALID');
    }

    return ['sha256' => hash('sha256', $bytes), 'payload' => $payload];
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function career1046ProjectionSnapshot(array $payload, array $targetSlugs): array
{
    $targetSet = array_fill_keys($targetSlugs, true);
    $allSlugs = [];
    $allRows = [];
    $targetRows = [];
    $targetPublishedRows = [];
    $targetPublishedBySlug = [];
    $outsideTargetSlugs = [];

    foreach ((array) ($payload['items'] ?? []) as $item) {
        if (! is_array($item)) {
            continue;
        }
        $slug = strtolower(trim((string) ($item['slug'] ?? '')));
        $locale = career1046Locale($item['locale'] ?? null);
        if ($slug === '' || $locale === null) {
            continue;
        }

        $rowKey = $slug.'|'.$locale;
        $allSlugs[$slug] = true;
        $allRows[$rowKey] = true;
        if (! isset($targetSet[$slug])) {
            $outsideTargetSlugs[$slug] = true;

            continue;
        }
        $targetRows[$rowKey] = true;

        $published = ($item['public_resolution_type'] ?? null) === CAREER_1046_PUBLIC_TYPE
            && ($item['runtime_publish_state'] ?? null) === CAREER_1046_PUBLISHED_STATE
            && ($item['release_gate_pass'] ?? false) === true;
        if ($published) {
            $targetPublishedRows[$rowKey] = true;
            $targetPublishedBySlug[$slug][$locale] = true;
        }
    }

    $expectedRows = [];
    foreach ($targetSlugs as $slug) {
        $expectedRows[] = $slug.'|en';
        $expectedRows[] = $slug.'|zh';
    }
    $targetRowKeys = array_keys($targetRows);
    sort($targetRowKeys, SORT_STRING);
    $publishedRowKeys = array_keys($targetPublishedRows);
    sort($publishedRowKeys, SORT_STRING);
    $fullyPublishedSlugs = [];
    foreach ($targetPublishedBySlug as $slug => $locales) {
        if (isset($locales['en'], $locales['zh'])) {
            $fullyPublishedSlugs[] = $slug;
        }
    }
    sort($fullyPublishedSlugs, SORT_STRING);
    $allSlugKeys = array_keys($allSlugs);
    sort($allSlugKeys, SORT_STRING);
    $allRowKeys = array_keys($allRows);
    sort($allRowKeys, SORT_STRING);
    $outside = array_keys($outsideTargetSlugs);
    sort($outside, SORT_STRING);

    return [
        'unique_slug_count' => count($allSlugKeys),
        'locale_row_count' => count($allRowKeys),
        'slug_set_sha256' => career1046SetHash($allSlugKeys),
        'locale_row_set_sha256' => career1046SetHash($allRowKeys),
        'target_structural_row_count' => count($targetRowKeys),
        'target_structural_row_set_sha256' => career1046SetHash($targetRowKeys),
        'target_missing_rows' => career1046Diff($expectedRows, $targetRowKeys),
        'target_structural_rows_complete' => count($targetRowKeys) === count($targetSlugs) * 2
            && career1046Diff($expectedRows, $targetRowKeys) === [],
        'target_published_slug_count' => count($fullyPublishedSlugs),
        'target_published_row_count' => count($publishedRowKeys),
        'target_published_slug_set_sha256' => career1046SetHash($fullyPublishedSlugs),
        'target_published_row_set_sha256' => career1046SetHash($publishedRowKeys),
        'target_published_rows_complete' => count($fullyPublishedSlugs) === count($targetSlugs)
            && count($publishedRowKeys) === count($targetSlugs) * 2,
        'outside_target_slugs' => $outside,
        'exact_target_only' => $outside === []
            && $allSlugKeys === $targetSlugs
            && count($allRowKeys) === count($targetSlugs) * 2,
    ];
}

/** @param array<string,mixed> $ledger @return array<string,mixed> */
function career1046LedgerSnapshot(array $ledger): array
{
    $rows = data_get($ledger, 'public_resolution.rows');
    if (! is_array($rows)) {
        $rows = is_array($ledger['members'] ?? null) ? $ledger['members'] : [];
    }
    $slugs = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        $slug = strtolower(trim((string) ($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? '')));
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }
    $slugs = career1046SlugList($slugs);

    return [
        'member_slug_count' => count($slugs),
        'member_slug_set_sha256' => career1046SetHash($slugs),
    ];
}

try {
    if (($argv[1] ?? '') !== 'inspect') {
        throw new RuntimeException('MODE_INVALID');
    }

    $backendRoot = dirname(__DIR__, 2);
    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $manifestPath = $backendRoot.'/docs/seo/generated/'.CAREER_1046_MANIFEST_FILENAME;
    $manifestBytes = file_get_contents($manifestPath);
    $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
    if (! is_string($manifestBytes) || ! is_array($manifest)) {
        throw new RuntimeException('MANIFEST_INVALID');
    }
    $manifestSha = hash('sha256', $manifestBytes);
    $expectedManifestSha = strtolower(trim((string) getenv('EXPECTED_MANIFEST_SHA256')));
    if (! preg_match('/^[0-9a-f]{64}$/D', $expectedManifestSha) || ! hash_equals($expectedManifestSha, $manifestSha)) {
        throw new RuntimeException('MANIFEST_SHA_MISMATCH');
    }

    $baselineSlugs = career1046SlugList($manifest['baseline_slugs'] ?? null);
    $deltaSlugs = career1046SlugList($manifest['delta_slugs'] ?? null);
    $targetSlugs = career1046SlugList([...$baselineSlugs, ...$deltaSlugs]);
    if (count($baselineSlugs) !== 30 || count($deltaSlugs) !== 1016 || count($targetSlugs) !== 1046) {
        throw new RuntimeException('MANIFEST_COUNTS_INVALID');
    }

    $authenticSlugs = career1046SlugList($app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs());
    $authenticDelta = career1046Intersect($authenticSlugs, $deltaSlugs);
    $missingReceipts = career1046Diff($deltaSlugs, $authenticSlugs);
    $authenticOutsideTarget = career1046Diff($authenticSlugs, $targetSlugs);
    $authenticBaseline = career1046Intersect($authenticSlugs, $baselineSlugs);

    $occupations = Occupation::query()
        ->with(['indexStates' => static function ($query): void {
            $query->orderByDesc('changed_at')->orderByDesc('created_at');
        }])
        ->get(['id', 'canonical_slug']);
    $occupationSlugs = career1046SlugList($occupations->pluck('canonical_slug')->all());
    $occupationBySlug = $occupations->keyBy(static fn (Occupation $occupation): string => strtolower(trim((string) $occupation->canonical_slug))
    );

    $dbMismatches = [];
    $dbMatched = [];
    foreach ($authenticDelta as $slug) {
        /** @var Occupation|null $occupation */
        $occupation = $occupationBySlug->get($slug);
        $state = $occupation?->indexStates->first();
        $reasonCodes = is_array($state?->reason_codes) ? $state->reason_codes : [];
        $reasons = [];
        if (! $occupation instanceof Occupation) {
            $reasons[] = 'occupation_missing';
        }
        if ($state === null) {
            $reasons[] = 'latest_index_state_missing';
        } else {
            if (strtolower(trim((string) $state->index_state)) !== 'indexed') {
                $reasons[] = 'latest_index_state_not_indexed';
            }
            if ((bool) $state->index_eligible !== true) {
                $reasons[] = 'latest_index_state_not_eligible';
            }
            if (! in_array('canonical_rollout_batch_promotion', $reasonCodes, true)) {
                $reasons[] = 'latest_index_state_missing_promotion_reason';
            }
        }
        if ($reasons === []) {
            $dbMatched[] = $slug;
        } else {
            $dbMismatches[] = ['slug' => $slug, 'reasons' => $reasons];
        }
    }

    $ledgerEnvelope = $app->make(CareerFullReleaseLedgerProjectionService::class)->build();
    $generatedLedger = (array) ($ledgerEnvelope[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? []);
    $generatedProjection = $app->make(CareerRuntimePublishProjectionService::class)->buildFromLedgerArray($generatedLedger);

    $selectedProjection = career1046LatestArtifact(
        storage_path('app/private/career_runtime_publish_projection'),
        CAREER_1046_PROJECTION_FILENAME,
    );
    $selectedLedger = career1046LatestArtifact(
        storage_path('app/private/career_release_ledger'),
        CAREER_1046_LEDGER_FILENAME,
    );

    $scanner = $app->make(CareerDetailReadyPublicationCandidateScanner::class)->scan();
    $displayReady = career1046SlugList(data_get($scanner, 'sources.display_asset_ready.slugs', []));
    $unionReady = career1046SlugList(data_get($scanner, 'sources.union_detail_ready.slugs', []));
    $rawDisplaySlugs = career1046SlugList(CareerJobDisplayAsset::query()->pluck('canonical_slug')->all());
    $rawDisplayRows = CareerJobDisplayAsset::query()->count();
    $rawDisplayDuplicates = CareerJobDisplayAsset::query()
        ->selectRaw('LOWER(canonical_slug) AS normalized_slug, COUNT(*) AS aggregate')
        ->groupByRaw('LOWER(canonical_slug)')
        ->havingRaw('COUNT(*) > 1')
        ->pluck('aggregate', 'normalized_slug')
        ->map(static fn (mixed $count): int => (int) $count)
        ->all();
    ksort($rawDisplayDuplicates, SORT_STRING);

    $generatedLedgerJson = json_encode($generatedLedger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $generatedProjectionJson = json_encode($generatedProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (! is_string($generatedLedgerJson) || ! is_string($generatedProjectionJson)) {
        throw new RuntimeException('GENERATED_AUTHORITY_ENCODING_FAILED');
    }

    $payload = [
        'contract_version' => CAREER_1046_EVIDENCE_CONTRACT,
        'status' => 'PASS_AUTHORITY_EVIDENCE_CAPTURED',
        'read_only' => true,
        'writes_database' => false,
        'manifest' => [
            'sha256' => $manifestSha,
            'baseline_count' => count($baselineSlugs),
            'delta_count' => count($deltaSlugs),
            'target_count' => count($targetSlugs),
            'baseline_set_sha256' => career1046SetHash($baselineSlugs),
            'delta_set_sha256' => career1046SetHash($deltaSlugs),
            'target_set_sha256' => career1046SetHash($targetSlugs),
            'target_locale_row_set_sha256' => career1046SetHash(array_merge(
                array_map(static fn (string $slug): string => $slug.'|en', $targetSlugs),
                array_map(static fn (string $slug): string => $slug.'|zh', $targetSlugs),
            )),
        ],
        'authentic_successful_receipts' => [
            'unique_slug_count' => count($authenticSlugs),
            'unique_slug_set_sha256' => career1046SetHash($authenticSlugs),
            'delta_covered_count' => count($authenticDelta),
            'delta_covered_set_sha256' => career1046SetHash($authenticDelta),
            'missing_delta_count' => count($missingReceipts),
            'missing_delta_slugs' => $missingReceipts,
            'baseline_overlap_count' => count($authenticBaseline),
            'baseline_overlap_slugs' => $authenticBaseline,
            'outside_target_count' => count($authenticOutsideTarget),
            'outside_target_slugs' => $authenticOutsideTarget,
            'covers_all_delta' => $missingReceipts === [] && count($authenticDelta) === count($deltaSlugs),
        ],
        'database_index_state' => [
            'receipt_covered_delta_count' => count($authenticDelta),
            'matching_latest_state_count' => count($dbMatched),
            'matching_latest_state_set_sha256' => career1046SetHash($dbMatched),
            'mismatch_count' => count($dbMismatches),
            'mismatches' => $dbMismatches,
            'covered_receipts_currently_match' => count($dbMismatches) === 0,
            'full_delta_receipt_and_db_match' => $missingReceipts === []
                && count($authenticDelta) === count($deltaSlugs)
                && count($dbMatched) === count($deltaSlugs)
                && count($dbMismatches) === 0,
        ],
        'target_vs_occupations' => [
            'occupation_count' => count($occupationSlugs),
            'occupation_set_sha256' => career1046SetHash($occupationSlugs),
            'missing_target_count' => count(career1046Diff($targetSlugs, $occupationSlugs)),
            'missing_target_slugs' => career1046Diff($targetSlugs, $occupationSlugs),
            'outside_target_count' => count(career1046Diff($occupationSlugs, $targetSlugs)),
            'outside_target_slugs' => career1046Diff($occupationSlugs, $targetSlugs),
            'exact_match' => $targetSlugs === $occupationSlugs,
        ],
        'current_materialized_authority' => [
            'projection_sha256' => $selectedProjection['sha256'],
            'projection' => career1046ProjectionSnapshot($selectedProjection['payload'], $targetSlugs),
            'ledger_sha256' => $selectedLedger['sha256'],
            'ledger' => career1046LedgerSnapshot($selectedLedger['payload']),
        ],
        'regenerated_authority' => [
            'ledger_json_sha256' => hash('sha256', $generatedLedgerJson),
            'ledger' => career1046LedgerSnapshot($generatedLedger),
            'projection_json_sha256' => hash('sha256', $generatedProjectionJson),
            'projection' => career1046ProjectionSnapshot($generatedProjection, $targetSlugs),
        ],
        'detail_display_authority' => [
            'raw_display_asset_row_count' => $rawDisplayRows,
            'raw_display_asset_row_deficit_vs_target' => count($targetSlugs) - $rawDisplayRows,
            'raw_display_asset_unique_slug_count' => count($rawDisplaySlugs),
            'raw_display_asset_duplicate_slugs' => $rawDisplayDuplicates,
            'target_missing_raw_display_count' => count(career1046Diff($targetSlugs, $rawDisplaySlugs)),
            'target_missing_raw_display_slugs' => career1046Diff($targetSlugs, $rawDisplaySlugs),
            'valid_display_ready_count' => count($displayReady),
            'valid_display_ready_set_sha256' => career1046SetHash($displayReady),
            'target_missing_valid_display_count' => count(career1046Diff($targetSlugs, $displayReady)),
            'target_missing_valid_display_slugs' => career1046Diff($targetSlugs, $displayReady),
            'valid_display_gap_covered_by_alternative_detail_count' => count(career1046Intersect(
                career1046Diff($targetSlugs, $displayReady),
                $unionReady,
            )),
            'valid_display_gap_covered_by_alternative_detail_slugs' => career1046Intersect(
                career1046Diff($targetSlugs, $displayReady),
                $unionReady,
            ),
            'union_detail_ready_count' => count($unionReady),
            'union_detail_ready_set_sha256' => career1046SetHash($unionReady),
            'target_missing_union_detail_count' => count(career1046Diff($targetSlugs, $unionReady)),
            'target_missing_union_detail_slugs' => career1046Diff($targetSlugs, $unionReady),
            'display_gap_blocks_target_detail' => career1046Diff($targetSlugs, $unionReady) !== [],
        ],
        'database_write_count' => 0,
        'artifact_write_count' => 0,
        'cache_write_count' => 0,
        'publication_write_count' => 0,
        'deploy_count' => 0,
        'migration_count' => 0,
        'cms_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
    ];

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    echo json_encode([
        'contract_version' => CAREER_1046_EVIDENCE_CONTRACT,
        'status' => 'HOLD_AUTHORITY_EVIDENCE_INCOMPLETE',
        'safe_code' => $exception instanceof RuntimeException ? $exception->getMessage() : 'UNEXPECTED_EVIDENCE_FAILURE',
        'read_only' => true,
        'writes_database' => false,
        'database_write_count' => 0,
        'artifact_write_count' => 0,
        'cache_write_count' => 0,
        'publication_write_count' => 0,
        'deploy_count' => 0,
        'migration_count' => 0,
        'cms_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(1);
}
