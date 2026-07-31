<?php

declare(strict_types=1);

$root = __DIR__;
$expectedUnits = [
    'free_preview',
    'locked_result',
    'paid_full_report',
    'entitlement_levels',
    'five_dimension_explanations',
    'facet_subscale_explanations',
    'score_range_boundary_copy',
    'action_growth_advice',
    'workplace_relationship_copy',
    'share_public_summary',
    'pdf_reader_content',
    'history_account_reentry',
    'result_report_cta',
    'empty_error_expired_access_denied',
    'mobile_desktop_consumption',
    'analytics_reader_labels',
];

$decode = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("Expected an object or array in {$path}");
    }

    return $decoded;
};

$manifest = $decode($root.'/package_manifest.json');
if (($manifest['inventory_units'] ?? null) !== $expectedUnits || ($manifest['inventory_unit_count'] ?? null) !== 16) {
    throw new RuntimeException('Frozen inventory units do not match.');
}

$expectedInventoryEvidence = [
    'inventory_package_sha256' => '0f50f4108af14656442ef7d57d410b2e74f8dffced6ed3db372bf848ea051292',
    'source_ledger_sha256' => 'facbf57a362a430cdc8b5f6545db4a227e1268d285e2c27beed3c935ea9cf6e2',
    'row_id_set_sha256' => '347bd92a3db93d873b44e27c88327e860ee5915e9e7eafb9adb2227e75fef8b7',
    'row_count' => 118,
    'cohort_counts' => [
        'public_profile_control' => 52,
        'english_historical_revision_verification' => 50,
        'result_content' => 16,
    ],
    'row_reconciliation_file' => 'inventory_row_reconciliation.json',
];
foreach ($expectedInventoryEvidence as $field => $expected) {
    if (($manifest['source_inventory'][$field] ?? null) !== $expected) {
        throw new RuntimeException("Frozen source inventory evidence does not match: {$field}");
    }
}

$assets = [];
foreach (file($root.'/content_assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $assets[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
}

$units = array_column($assets, 'unit');
if (count($assets) !== 16 || count(array_unique($units)) !== 16 || $units !== $expectedUnits) {
    throw new RuntimeException('Content assets must cover the 16 frozen units exactly once and in order.');
}

$assetIds = array_column($assets, 'asset_id');
if (count(array_unique($assetIds)) !== 16) {
    throw new RuntimeException('Content asset IDs must be unique.');
}

$reconciliation = $decode($root.'/inventory_row_reconciliation.json');
$publicRows = $reconciliation['public_profile_control_rows'] ?? [];
$historicalRows = $reconciliation['english_historical_revision_rows'] ?? [];
$resultRows = $reconciliation['result_content_rows'] ?? [];
$sourceRowIds = [
    ...$publicRows,
    ...$historicalRows,
    ...array_column($resultRows, 'row_id'),
];
if (
    ($reconciliation['source_inventory_package_sha256'] ?? null) !== $expectedInventoryEvidence['inventory_package_sha256']
    || ($reconciliation['source_inventory_ledger_sha256'] ?? null) !== $expectedInventoryEvidence['source_ledger_sha256']
    || ($reconciliation['source_row_id_set_sha256'] ?? null) !== $expectedInventoryEvidence['row_id_set_sha256']
    || ($reconciliation['source_row_count'] ?? null) !== 118
    || ($reconciliation['cohort_counts'] ?? null) !== $expectedInventoryEvidence['cohort_counts']
    || count($publicRows) !== 52
    || count($historicalRows) !== 50
    || count($resultRows) !== 16
    || count($sourceRowIds) !== 118
    || count(array_unique($sourceRowIds)) !== 118
    || hash('sha256', implode("\n", $sourceRowIds)."\n") !== $expectedInventoryEvidence['row_id_set_sha256']
) {
    throw new RuntimeException('Frozen 118-row inventory reconciliation does not match.');
}

$assetsByUnit = array_column($assets, null, 'unit');
foreach ($resultRows as $index => $row) {
    $unit = $expectedUnits[$index];
    $asset = $assetsByUnit[$unit] ?? null;
    if (
        ($row['row_id'] ?? null) !== 'W2-RESULT-'.$unit
        || ($row['unit'] ?? null) !== $unit
        || ($row['stable_asset_identity'] ?? null) !== 'big5.result_content.'.$unit
        || ($row['translation_identity'] ?? null) !== 'big5-result:'.$unit.':zh-CN:en'
        || ($row['asset_id'] ?? null) !== 'big5.en.w2.'.$unit
        || ($asset['asset_id'] ?? null) !== ($row['asset_id'] ?? null)
    ) {
        throw new RuntimeException("Frozen result row mapping does not match: {$unit}");
    }

    $content = $asset['content'] ?? [];
    foreach ($row['required_content_keys'] ?? [] as $key) {
        if (! array_key_exists($key, $content)) {
            throw new RuntimeException("Required content key is missing for {$unit}: {$key}");
        }
    }

    foreach ($row['required_item_identities'] ?? [] as $path => $expectedIdentities) {
        if (str_contains($path, '.')) {
            [$collection, $identityKey] = explode('.', $path, 2);
            $actualIdentities = array_column($content[$collection] ?? [], $identityKey);
        } else {
            $actualIdentities = $content[$path] ?? null;
        }
        if ($actualIdentities !== $expectedIdentities) {
            throw new RuntimeException("Required item identities do not match for {$unit}: {$path}");
        }
    }
}

$facetAnchors = $reconciliation['facet_semantic_anchors'] ?? [];
$facetsByCode = array_column(
    $assetsByUnit['facet_subscale_explanations']['content']['facets'] ?? [],
    null,
    'code'
);
if (array_keys($facetAnchors) !== array_keys($facetsByCode) || count($facetAnchors) !== 30) {
    throw new RuntimeException('Facet semantic anchor cohort does not match all 30 facets.');
}
foreach ($facetAnchors as $code => $anchors) {
    $semanticText = strtolower(
        (string) ($facetsByCode[$code]['label'] ?? '').' '.
        (string) ($facetsByCode[$code]['description'] ?? '')
    );
    foreach ($anchors as $anchor) {
        if (! str_contains($semanticText, strtolower((string) $anchor))) {
            throw new RuntimeException("Facet source semantic anchor is missing for {$code}: {$anchor}");
        }
    }
}

$encodedAssets = json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $encodedAssets) === 1) {
    throw new RuntimeException('CJK leakage detected.');
}

$forbiddenText = [
    'guaranteed career',
    'perfect career',
    'hiring fit',
    'admission decision',
    'clinical diagnosis',
    'treatment plan',
    'intelligence score',
    'moral ranking',
    'salary guarantee',
    'success guarantee',
    'relationship guarantee',
];
foreach ($forbiddenText as $term) {
    if (str_contains(strtolower($encodedAssets), $term)) {
        throw new RuntimeException("Forbidden claim detected: {$term}");
    }
}

foreach ($assets as $asset) {
    foreach ([
        'status' => 'pending_manual_review',
        'runtime_use' => 'draft_review_only',
        'ready_for_runtime' => false,
        'ready_for_production' => false,
        'production_use_allowed' => false,
    ] as $field => $expected) {
        if (($asset[$field] ?? null) !== $expected) {
            throw new RuntimeException("Invalid {$field} for {$asset['asset_id']}");
        }
    }
}

$privatePatterns = [
    '/"raw_score"\s*:\s*(?!false|null)/i',
    '/"score_vector"\s*:\s*(?!false|null)/i',
    '/"percentile"\s*:\s*(?!false|null)/i',
    '/"attempt_id"\s*:\s*(?!false|null)/i',
    '/"report_token"\s*:\s*(?!false|null)/i',
    '/https?:\/\/\S+/i',
];
foreach ($privatePatterns as $pattern) {
    if (preg_match($pattern, $encodedAssets) === 1) {
        throw new RuntimeException("Private-field or public-URL leakage detected: {$pattern}");
    }
}

$permissions = $manifest['permissions'] ?? [];
foreach (['cms_write', 'database_write', 'public_release', 'indexability_change', 'search_submission', 'deploy'] as $permission) {
    if (($permissions[$permission] ?? null) !== false) {
        throw new RuntimeException("Permission must remain false: {$permission}");
    }
}

$shaManifest = $decode($root.'/sha256_manifest.json');
$manifestedPackageFiles = array_column($shaManifest['files'] ?? [], 'path');
$expectedPackageFiles = [...$manifestedPackageFiles, 'sha256_manifest.json'];
$actualPackageFiles = array_values(array_filter(
    scandir($root) ?: [],
    static fn (string $file): bool => $file !== '.' && $file !== '..' && is_file($root.'/'.$file)
));
sort($expectedPackageFiles);
sort($actualPackageFiles);
if ($actualPackageFiles !== $expectedPackageFiles) {
    throw new RuntimeException('Package directory contains missing or unmanifested files.');
}

$canonical = [];
foreach ($shaManifest['files'] ?? [] as $file) {
    $path = $root.'/'.$file['path'];
    $actual = hash_file('sha256', $path);
    if (! hash_equals((string) $file['sha256'], $actual)) {
        throw new RuntimeException("SHA mismatch for {$file['path']}");
    }
    $canonical[] = $file['path'].':'.$actual;
}

$packageSha = hash('sha256', implode("\n", $canonical));
if (! hash_equals((string) ($shaManifest['package_sha256'] ?? ''), $packageSha)) {
    throw new RuntimeException('Package SHA mismatch.');
}

echo json_encode([
    'ok' => true,
    'unit_count' => count($units),
    'asset_count' => count($assets),
    'package_sha256' => $packageSha,
    'runtime_use' => 'draft_review_only',
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
