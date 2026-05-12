#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_svg_provenance_lib.php';

/**
 * @return array<int, string>
 */
function iqVerifyRequestedPackDirs(): array
{
    $options = getopt('', ['pack-dir::', 'json']);
    $packDirs = $options['pack-dir'] ?? [];

    if (is_string($packDirs) && $packDirs !== '') {
        $packDirs = [$packDirs];
    }

    if (! is_array($packDirs) || $packDirs === []) {
        $packDirs = iqSvgDefaultPackDirs();
    }

    return array_values(array_unique(array_map(
        static fn (string $path): string => rtrim($path, '/'),
        $packDirs
    )));
}

function iqVerifyFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[iq-svg-verify] {$message}\n");
    exit($code);
}

/**
 * @return array<string, array<string, mixed>>
 */
function iqManifestItemsByQuestionId(array $manifest): array
{
    $items = is_array($manifest['items'] ?? null) ? $manifest['items'] : [];
    $indexed = [];

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        $questionId = (string) ($item['question_id'] ?? '');
        if ($questionId === '') {
            continue;
        }

        $indexed[$questionId] = $item;
    }

    return $indexed;
}

/**
 * @return array<string, string>
 */
function iqOptionHashes(array $item): array
{
    $hashes = [];
    $options = is_array($item['option_assets'] ?? null) ? $item['option_assets'] : [];

    foreach ($options as $option) {
        if (! is_array($option)) {
            continue;
        }

        $code = (string) ($option['code'] ?? '');
        $hash = (string) ($option['sha256'] ?? '');
        if ($code === '') {
            continue;
        }

        $hashes[$code] = $hash;
    }

    ksort($hashes, SORT_STRING);

    return $hashes;
}

/**
 * @return list<string>
 */
function iqCollectManifestViolations(array $recorded, array $expected): array
{
    $violations = [];

    if (($recorded['item_count'] ?? null) !== ($expected['item_count'] ?? null)) {
        $violations[] = 'item_count mismatch';
    }

    foreach ([
        'questions_json_sha256',
        'scoring_spec_json_sha256',
        'manifest_json_sha256',
        'landing_json_sha256',
        'version_json_sha256',
        'builder_script_sha256',
    ] as $hashKey) {
        $recordedHash = (string) ($recorded['source_hashes'][$hashKey] ?? '');
        $expectedHash = (string) ($expected['source_hashes'][$hashKey] ?? '');
        if ($recordedHash !== $expectedHash) {
            $violations[] = 'source hash mismatch for '.$hashKey;
        }
    }

    $recordedItems = iqManifestItemsByQuestionId($recorded);
    $expectedItems = iqManifestItemsByQuestionId($expected);

    foreach ($expectedItems as $questionId => $expectedItem) {
        $recordedItem = $recordedItems[$questionId] ?? null;
        if (! is_array($recordedItem)) {
            $violations[] = 'missing question provenance entry for '.$questionId;

            continue;
        }

        if ((string) ($recordedItem['question_sha256'] ?? '') !== (string) ($expectedItem['question_sha256'] ?? '')) {
            $violations[] = 'question payload hash mismatch for '.$questionId;
        }

        if ((string) ($recordedItem['stem_asset']['sha256'] ?? '') !== (string) ($expectedItem['stem_asset']['sha256'] ?? '')) {
            $violations[] = 'asset hash mismatch for '.$questionId.' stem';
        }

        $recordedOptionHashes = iqOptionHashes($recordedItem);
        $expectedOptionHashes = iqOptionHashes($expectedItem);
        foreach ($expectedOptionHashes as $code => $expectedHash) {
            $recordedHash = $recordedOptionHashes[$code] ?? '';
            if ($recordedHash !== $expectedHash) {
                $violations[] = 'asset hash mismatch for '.$questionId.' option '.$code;
            }
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function iqCollectProductionContractViolations(string $packDir, array $manifest, array $scoring): array
{
    $violations = [];
    $lifecycleStatus = strtolower(trim((string) ($manifest['lifecycle']['status'] ?? '')));
    $scaleCode = strtoupper(trim((string) ($manifest['scale_code'] ?? '')));
    $landingPath = rtrim($packDir, '/').'/meta/landing.json';
    $landing = iqSvgLoadJsonFile($landingPath);
    $canonicalPath = (string) ($landing['landing']['canonical_path'] ?? '');

    if ($lifecycleStatus !== 'legacy_demo' && $scaleCode === 'IQ_RAVEN') {
        $violations[] = 'production IQ metadata uses legacy scale_code IQ_RAVEN outside allowlist';
    }

    if ($lifecycleStatus !== 'legacy_demo' && str_contains($canonicalPath, '/test/iq-raven-demo')) {
        $violations[] = 'production IQ metadata still exposes /test/iq-raven-demo as canonical path';
    }

    $bankStatus = strtolower(trim((string) ($scoring['item_bank']['status'] ?? '')));
    $items = is_array($scoring['items'] ?? null) ? $scoring['items'] : [];
    $productionItemsChecked = 0;

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        $itemStatus = strtolower(trim((string) ($item['status'] ?? '')));
        $isLegacyDemo = $bankStatus === 'legacy_demo' || $itemStatus === 'legacy_demo';
        if ($isLegacyDemo) {
            continue;
        }

        $productionItemsChecked++;
        $itemId = (string) ($item['item_id'] ?? 'missing_item_id');

        foreach (['correct_answer', 'solution_rule', 'distractor_logic'] as $requiredField) {
            $value = $item[$requiredField] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $violations[] = 'production item missing '.$requiredField.': '.$itemId;
            }
        }

        if (! is_array($item['asset_hashes'] ?? null) || ($item['asset_hashes'] ?? []) === []) {
            $violations[] = 'production item missing asset_hashes: '.$itemId;
        }

        if (! is_array($item['generator_metadata'] ?? null) || ($item['generator_metadata'] ?? []) === []) {
            $violations[] = 'production item missing generator_metadata: '.$itemId;
        }
    }

    $manifest['production_items_checked'] = $productionItemsChecked;

    return $violations;
}

$options = getopt('', ['pack-dir::', 'json']);
$packDirs = iqVerifyRequestedPackDirs();
$emitJson = array_key_exists('json', $options);

$summary = [
    'schema_version' => 'fm.iq.svg_provenance.verify.v1',
    'ok' => true,
    'packs' => [],
];

try {
    foreach ($packDirs as $packDir) {
        $manifestPath = iqSvgDefaultManifestPath($packDir);
        $recorded = iqSvgLoadJsonFile($manifestPath);
        $expected = iqSvgBuildLegacyProvenanceManifest($packDir);
        $scoring = iqSvgLoadJsonFile(rtrim($packDir, '/').'/scoring_spec.json');
        $violations = array_merge(
            iqCollectManifestViolations($recorded, $expected),
            iqCollectProductionContractViolations($packDir, $recorded, $scoring)
        );

        if ($violations !== []) {
            iqVerifyFail(iqSvgRelativePath($packDir).' -> '.implode('; ', $violations), 20);
        }

        $summary['packs'][] = [
            'pack_dir' => iqSvgRelativePath($packDir),
            'scale_code' => (string) ($recorded['scale_code'] ?? ''),
            'lifecycle_status' => (string) ($recorded['lifecycle']['status'] ?? ''),
            'item_count' => (int) ($recorded['item_count'] ?? 0),
            'legacy_demo_allowlisted' => (bool) ($recorded['lifecycle']['legacy_demo_allowlisted'] ?? false),
            'scored_items_declared' => (int) ($recorded['scoring_contract_snapshot']['scored_items_declared'] ?? 0),
        ];
    }

    if ($emitJson) {
        fwrite(STDOUT, iqSvgPrettyJson($summary).PHP_EOL);
    } else {
        foreach ($summary['packs'] as $packSummary) {
            if (! is_array($packSummary)) {
                continue;
            }

            fwrite(
                STDOUT,
                sprintf(
                    "[iq-svg-verify] ok pack=%s scale_code=%s lifecycle=%s items=%d scored_items=%d\n",
                    $packSummary['pack_dir'],
                    $packSummary['scale_code'],
                    $packSummary['lifecycle_status'],
                    $packSummary['item_count'],
                    $packSummary['scored_items_declared']
                )
            );
        }
    }
} catch (Throwable $throwable) {
    iqVerifyFail($throwable->getMessage(), 10);
}
