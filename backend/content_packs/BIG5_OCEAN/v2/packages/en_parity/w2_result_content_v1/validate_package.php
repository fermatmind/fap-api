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
