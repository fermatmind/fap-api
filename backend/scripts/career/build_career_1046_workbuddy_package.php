<?php

declare(strict_types=1);

/**
 * Build the deterministic Career 1046 WorkBuddy display-section package.
 *
 * Usage:
 *   php backend/scripts/career/build_career_1046_workbuddy_package.php \
 *     /absolute/path/to/generated/gap-analysis \
 *     backend/content_assets/career/workbuddy-1046-display-v1
 */
const WAVE_COUNTS = [
    'w1-s3-output' => 49,
    'w2-s3-output' => 28,
    'w3-s3-output' => 40,
    'w6-s3-output' => 55,
    'w7-s3-output' => 235,
    'w8-s3-output' => 235,
    'w9-s3-output' => 235,
    'w10-s3-output' => 85,
    'w11-s3-output' => 84,
];

const EXPECTED_CAREERS = 1046;
const EXPECTED_LOCALE_ROWS = 2092;
const EXPECTED_BLOCKS = 4184;

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function canonicalJson(array $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return $encoded;
}

function canonicalize(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map(canonicalize(...), $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = canonicalize($item);
    }

    return $value;
}

function hashValue(mixed $value): string
{
    return hash('sha256', json_encode(
        canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));
}

function containsNumericRating(string $value): bool
{
    return preg_match('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', $value) === 1;
}

function removeNumericRatingStatements(string $value, string $locale): string
{
    $keptLines = [];
    foreach (preg_split('/\R/u', $value) ?: [] as $line) {
        if (! containsNumericRating($line)) {
            $keptLines[] = $line;

            continue;
        }

        $segments = preg_split('/(?<=[.!?。！？])\s*/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => ! containsNumericRating($segment),
        ));
        $normalized = trim(implode($locale === 'en' ? ' ' : '', $kept));
        if ($normalized !== '') {
            $keptLines[] = $normalized;
        }
    }

    $normalized = trim(implode("\n", $keptLines));
    $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? '';
    if ($normalized === '' || containsNumericRating($normalized)) {
        fail('AI body normalization left an empty body or numeric rating statement.');
    }

    return $normalized;
}

/** @param list<string> $values */
function setHash(array $values): string
{
    $values = array_values(array_unique(array_map('strval', $values)));
    sort($values, SORT_STRING);

    return hash('sha256', implode("\n", $values)."\n");
}

function requireNonEmptyString(mixed $value, string $field, string $path): string
{
    if (! is_string($value) || trim($value) === '') {
        fail("{$path}: {$field} must be a non-empty string");
    }

    return trim($value);
}

function normalizeLocaleCell(mixed $value, string $locale, string $field, string $path): string
{
    if (is_array($value) && count($value) === 2) {
        $value = $locale === 'en' ? ($value[1] ?? null) : ($value[0] ?? null);
    }

    return requireNonEmptyString($value, $field, $path);
}

/** @return array<string, mixed> */
function readSource(string $path, string $slug, string $locale, string $block): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        fail("{$path}: root must be an object");
    }

    $meta = $decoded['meta'] ?? null;
    $section = $decoded['display_section'] ?? null;
    if (! is_array($meta) || ! is_array($section)) {
        fail("{$path}: meta and display_section are required");
    }

    if (($meta['slug'] ?? null) !== $slug || ($meta['locale'] ?? null) !== $locale) {
        fail("{$path}: filename and meta identity differ");
    }

    $expectedVersion = $block.'_v1';
    if (($meta['block_version'] ?? null) !== $expectedVersion) {
        fail("{$path}: unexpected block_version");
    }

    $expectedComponent = $block === '2a' ? 'CareerAiDescriptionBlock' : 'CareerPathBlock';
    if (($section['component'] ?? null) !== $expectedComponent) {
        fail("{$path}: unexpected display component");
    }

    requireNonEmptyString($section['heading'] ?? null, 'heading', $path);
    if ($block === '2a') {
        $body = $section['body'] ?? null;
        if (! is_array($body) || $body === []) {
            fail("{$path}: 2a body must be non-empty");
        }
        foreach ($body as $index => $paragraph) {
            $section['body'][$index] = removeNumericRatingStatements(
                requireNonEmptyString($paragraph, "body.{$index}", $path),
                $locale,
            );
        }
    } else {
        $rows = $section['rows'] ?? null;
        if (! is_array($rows) || count($rows) !== 4) {
            fail("{$path}: 2b rows must contain exactly four career levels");
        }
        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row) || count($row) !== 4) {
                fail("{$path}: 2b row {$rowIndex} must contain four cells");
            }
            foreach ($row as $cellIndex => $cell) {
                $section['rows'][$rowIndex][$cellIndex] = normalizeLocaleCell(
                    $cell,
                    $locale,
                    "rows.{$rowIndex}.{$cellIndex}",
                    $path,
                );
            }
        }
        requireNonEmptyString($section['caveat'] ?? null, 'caveat', $path);
        requireNonEmptyString($section['source_key'] ?? null, 'source_key', $path);
    }

    return $section;
}

$sourceRoot = isset($argv[1]) ? rtrim($argv[1], '/') : '';
$destination = isset($argv[2]) ? rtrim($argv[2], '/') : '';
if ($sourceRoot === '' || $destination === '' || ! is_dir($sourceRoot)) {
    fail('A valid source root and destination are required.');
}

$rows = [];
$waveSummary = [];
$sourceFiles = [];
$blockHashes = [
    'career_ai_description_block' => [],
    'career_path_block' => [],
];
foreach (WAVE_COUNTS as $wave => $expectedCareers) {
    $waveRoot = $sourceRoot.'/'.$wave;
    $paths = glob($waveRoot.'/*_2?_*.json') ?: [];
    sort($paths, SORT_STRING);
    $waveSlugs = [];

    foreach ($paths as $path) {
        $filename = basename($path);
        if (preg_match('/\A(.+)_(2a|2b)_(en|zh-CN)\.json\z/', $filename, $matches) !== 1) {
            continue;
        }

        [, $slug, $block, $locale] = $matches;
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            fail("{$path}: invalid slug");
        }

        $identity = $slug.'|'.$locale;
        $blockKey = $block === '2a' ? 'career_ai_description_block' : 'career_path_block';
        if (isset($rows[$identity][$blockKey])) {
            fail("duplicate canonical input: {$identity}|{$blockKey}");
        }

        $relativePath = $wave.'/'.$filename;
        $section = readSource($path, $slug, $locale, $block);
        $rows[$identity] ??= [
            'slug' => $slug,
            'locale' => $locale,
            'blocks' => [],
            'sources' => [],
        ];
        $rows[$identity]['blocks'][$blockKey] = $section;
        $rows[$identity]['sources'][$blockKey] = [
            'relative_path' => $relativePath,
            'sha256' => hash_file('sha256', $path),
        ];
        $waveSlugs[$slug] = true;
        $sourceFiles[] = $relativePath.'|'.hash_file('sha256', $path);
    }

    if (count($waveSlugs) !== $expectedCareers) {
        fail("{$wave}: expected {$expectedCareers} careers, found ".count($waveSlugs));
    }
    $waveSummary[$wave] = [
        'career_count' => count($waveSlugs),
        'block_file_count' => count($waveSlugs) * 4,
    ];
}

ksort($rows, SORT_STRING);
$slugs = [];
foreach ($rows as $identity => $row) {
    if (array_keys($row['blocks']) !== ['career_ai_description_block', 'career_path_block']) {
        ksort($row['blocks'], SORT_STRING);
        $rows[$identity]['blocks'] = $row['blocks'];
    }
    ksort($rows[$identity]['blocks'], SORT_STRING);
    ksort($rows[$identity]['sources'], SORT_STRING);
    if (count($rows[$identity]['blocks']) !== 2 || count($rows[$identity]['sources']) !== 2) {
        fail("{$identity}: both 2a and 2b blocks are required");
    }
    foreach (array_keys($blockHashes) as $blockKey) {
        $blockHashes[$blockKey][] = $identity.'|'.hashValue($rows[$identity]['blocks'][$blockKey]);
    }
    $slugs[] = $row['slug'];
}

$slugs = array_values(array_unique($slugs));
sort($slugs, SORT_STRING);
if (count($slugs) !== EXPECTED_CAREERS || count($rows) !== EXPECTED_LOCALE_ROWS) {
    fail('Canonical package count mismatch.');
}

if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
    fail('Unable to create destination directory.');
}

$assetsPath = $destination.'/assets.jsonl';
$handle = fopen($assetsPath, 'wb');
if ($handle === false) {
    fail('Unable to create assets.jsonl.');
}
foreach ($rows as $row) {
    fwrite($handle, canonicalJson($row)."\n");
}
fclose($handle);

sort($sourceFiles, SORT_STRING);
$packageSha = hash_file('sha256', $assetsPath);
$deliveryReportSource = $sourceRoot.'/w12-s3-output/w12_s3_delivery_report.json';
$deliveryReportPath = $destination.'/w12_s3_delivery_report.json';
if (! is_file($deliveryReportSource) || ! copy($deliveryReportSource, $deliveryReportPath)) {
    fail('Unable to freeze the WorkBuddy delivery report.');
}
$deliveryReportSha = hash_file('sha256', $deliveryReportPath);
$manifest = [
    'contract_version' => 'career.workbuddy_1046_display_asset_package.v2',
    'package_id' => 'career-workbuddy-1046-display-v1',
    'source_delivery_report' => [
        'path' => 'w12_s3_delivery_report.json',
        'source_relative_path' => 'w12-s3-output/w12_s3_delivery_report.json',
        'sha256' => $deliveryReportSha,
    ],
    'counts' => [
        'careers' => count($slugs),
        'locale_rows' => count($rows),
        'content_blocks' => count($rows) * 2,
        'locales' => ['en', 'zh-CN'],
    ],
    'sets' => [
        'slug_set_sha256' => setHash($slugs),
        'identity_set_sha256' => setHash(array_keys($rows)),
        'source_file_chain_sha256' => hash('sha256', implode("\n", $sourceFiles)."\n"),
        'career_ai_description_block_sha256' => setHash($blockHashes['career_ai_description_block']),
        'career_path_block_sha256' => setHash($blockHashes['career_path_block']),
        'display_block_aggregate_sha256' => setHash(array_merge(
            $blockHashes['career_ai_description_block'],
            $blockHashes['career_path_block'],
        )),
    ],
    'files' => [
        [
            'path' => 'assets.jsonl',
            'sha256' => $packageSha,
            'row_count' => count($rows),
        ],
        [
            'path' => 'w12_s3_delivery_report.json',
            'sha256' => $deliveryReportSha,
        ],
    ],
    'waves' => $waveSummary,
    'mapping' => [
        '2a' => 'career_ai_description_block',
        '2b' => 'career_path_block',
        'source_field' => 'display_section',
        'numeric_rating_authority' => 'existing_ai_impact_table',
        'numeric_rating_statement_residue_count' => 0,
    ],
    'negative_guarantees' => [
        'content_regeneration' => false,
        'seo_payload_change' => false,
        'structured_data_change' => false,
        'discoverability_change' => false,
        'search_submission' => false,
    ],
];

file_put_contents(
    $destination.'/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

fwrite(STDOUT, canonicalJson([
    'status' => 'PASS',
    'package_sha256' => $packageSha,
    'counts' => $manifest['counts'],
])."\n");
