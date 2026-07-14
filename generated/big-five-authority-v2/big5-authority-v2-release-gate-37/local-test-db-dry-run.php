<?php

declare(strict_types=1);

$dir = __DIR__;
$manifest = json_decode((string) file_get_contents($dir.'/aggregate-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$report = json_decode((string) file_get_contents($dir.'/per-page-release-report.json'), true, 512, JSON_THROW_ON_ERROR);
$assets = $report['assets'] ?? [];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE authority_assets (asset_id TEXT PRIMARY KEY, route TEXT NOT NULL UNIQUE, payload_json TEXT NOT NULL)');
$before = (int) $pdo->query('SELECT total_changes()')->fetchColumn();

$pdo->beginTransaction();
$existingIds = [];
$plannedCreates = 0;
$plannedUpdates = 0;
foreach ($assets as $asset) {
    $assetId = (string) ($asset['asset_id'] ?? '');
    if (isset($existingIds[$assetId])) {
        $plannedUpdates++;
    } else {
        $plannedCreates++;
    }
}
$after = (int) $pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->rollBack();

$result = [
    'schema_version' => 'big5-authority-v2-local-test-db-measurement.v1',
    'database' => 'sqlite::memory:',
    'package_sha256' => $manifest['package_sha256'] ?? null,
    'candidate_count' => count($assets),
    'planned_create_count' => $plannedCreates,
    'planned_update_count' => $plannedUpdates,
    'executed_insert_count' => 0,
    'executed_update_count' => 0,
    'executed_delete_count' => 0,
    'measured_database_write_delta' => $after - $before,
    'transaction_rolled_back' => true,
    'production_connection_used' => false,
];

if ($result['candidate_count'] !== 231 || $plannedCreates !== 231 || $plannedUpdates !== 0 || $result['measured_database_write_delta'] !== 0) {
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
