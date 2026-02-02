<?php
declare(strict_types=1);

$defaultDir = realpath(__DIR__ . '/../../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2');
$packDir = $argv[1] ?? ($defaultDir ?: '');
if ($packDir === '' || !is_dir($packDir)) {
    fwrite(STDERR, "usage: php backend/scripts/validate_mbti_pack_v022.php <pack_dir>\n");
    exit(2);
}

function load_json(string $path, array &$errors): ?array {
    if (!is_file($path)) {
        $errors[] = "missing file: {$path}";
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $errors[] = "empty file: {$path}";
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = "invalid json: {$path}";
        return null;
    }
    return $decoded;
}

$errors = [];
$version = load_json($packDir . '/version.json', $errors);
$manifest = load_json($packDir . '/manifest.json', $errors);
$questions = load_json($packDir . '/questions.json', $errors);
$scoringSpec = load_json($packDir . '/scoring_spec.json', $errors);
$commercial = load_json($packDir . '/commercial_spec.json', $errors);
$aiSpec = load_json($packDir . '/ai_spec.json', $errors);
$telemetry = load_json($packDir . '/telemetry_spec.json', $errors);
$audit = load_json($packDir . '/audit_spec.json', $errors);

if ($version) {
    $packId = (string) ($version['pack_id'] ?? '');
    $dirVersion = (string) ($version['dir_version'] ?? '');
    $contentVersion = (string) ($version['content_pack_version'] ?? '');
    $scoringVersion = (string) ($version['scoring_spec_version'] ?? '');
    $normVersion = (string) ($version['norm_version'] ?? '');

    $dirName = basename($packDir);
    if ($dirVersion === '' || $dirVersion !== $dirName) {
        $errors[] = "version.json dir_version mismatch: {$dirVersion} (dir={$dirName})";
    }
    if ($packId !== 'MBTI.cn-mainland.zh-CN.v0.2.2') {
        $errors[] = "version.json pack_id mismatch: {$packId}";
    }
    foreach ([$contentVersion, $scoringVersion, $normVersion] as $v) {
        if ($v !== '0.2.2') {
            $errors[] = "version.json version field mismatch: {$v}";
        }
    }
}

if ($manifest) {
    $assets = $manifest['assets'] ?? null;
    if (!is_array($assets)) {
        $errors[] = 'manifest.assets missing or invalid';
        $assets = [];
    }
    $assetsSet = array_fill_keys($assets, true);
    $requiredAssets = [
        'version.json',
        'manifest.json',
        'questions.json',
        'scoring_spec.json',
        'commercial_spec.json',
        'ai_spec.json',
        'telemetry_spec.json',
        'audit_spec.json',
    ];
    foreach ($requiredAssets as $asset) {
        if (!isset($assetsSet[$asset])) {
            $errors[] = "manifest.assets missing: {$asset}";
        }
    }

    $manifestPackId = (string) ($manifest['pack_id'] ?? '');
    if ($version && $manifestPackId !== ($version['pack_id'] ?? '')) {
        $errors[] = "manifest.pack_id mismatch: {$manifestPackId}";
    }
}

if ($scoringSpec) {
    $schema = (string) ($scoringSpec['schema'] ?? '');
    $engine = (string) ($scoringSpec['engine_version'] ?? '');
    if ($schema !== 'fap.scoring_spec.v2') {
        $errors[] = "scoring_spec.schema mismatch: {$schema}";
    }
    if ($engine !== 'mbti-industrial-v2') {
        $errors[] = "scoring_spec.engine_version mismatch: {$engine}";
    }
}

if ($commercial) {
    $skuHint = $commercial['sku_hint'] ?? null;
    if (!is_array($skuHint)) {
        $errors[] = 'commercial_spec.sku_hint missing';
    } else {
        $expect = [
            'single' => ['code' => 'MBTI_REPORT_FULL_199', 'price_cents' => 199],
            'month' => ['code' => 'MBTI_PRO_MONTH_599', 'price_cents' => 599],
            'year' => ['code' => 'MBTI_PRO_YEAR_1999', 'price_cents' => 1999],
            'gift' => ['code' => 'MBTI_GIFT_BOGO_2990', 'price_cents' => 2990],
        ];
        foreach ($expect as $key => $rule) {
            $row = $skuHint[$key] ?? null;
            if (!is_array($row)) {
                $errors[] = "commercial_spec.sku_hint missing: {$key}";
                continue;
            }
            if ((string) ($row['code'] ?? '') !== $rule['code']) {
                $errors[] = "commercial_spec.sku_hint code mismatch for {$key}";
            }
            if ((int) ($row['price_cents'] ?? 0) !== $rule['price_cents']) {
                $errors[] = "commercial_spec.sku_hint price mismatch for {$key}";
            }
        }
    }
}

if ($telemetry) {
    $experiments = $telemetry['experiments'] ?? null;
    if (!is_array($experiments)) {
        $errors[] = 'telemetry_spec.experiments missing';
    } else {
        $found = false;
        foreach ($experiments as $exp) {
            if (is_array($exp) && (string) ($exp['key'] ?? '') === 'mbti_paywall_variant') {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $errors[] = 'telemetry_spec.experiments missing mbti_paywall_variant';
        }
    }
}

if ($audit) {
    $replay = $audit['replay_fields'] ?? null;
    if (!is_array($replay)) {
        $errors[] = 'audit_spec.replay_fields missing';
    } else {
        $attemptReq = $replay['attempt_snapshot_required'] ?? null;
        $resultReq = $replay['result_snapshot_required'] ?? null;
        if (!is_array($attemptReq) || !is_array($resultReq)) {
            $errors[] = 'audit_spec.replay_fields invalid';
        } else {
            $needAttempt = [
                'pack_id','dir_version','content_pack_version','scoring_spec_version','norm_version',
                'manifest_sha256','scoring_spec_sha256','questions_sha256','engine_version',
                'answers_hash','quality_flags','experiment_assignments',
            ];
            $needResult = [
                'type_code','dimension_scores','pci','facet_scores','norm_percentiles','entitlement_state','variant_id',
            ];
            foreach ($needAttempt as $field) {
                if (!in_array($field, $attemptReq, true)) {
                    $errors[] = "audit_spec.replay_fields.attempt_snapshot_required missing: {$field}";
                }
            }
            foreach ($needResult as $field) {
                if (!in_array($field, $resultReq, true)) {
                    $errors[] = "audit_spec.replay_fields.result_snapshot_required missing: {$field}";
                }
            }
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "[FAIL] validate_mbti_pack_v022\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "[OK] validate_mbti_pack_v022\n";
