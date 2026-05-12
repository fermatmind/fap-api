#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_showcase12_bank_lib.php';

function iqShowcase12VerifyFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[iq-showcase12-verify] {$message}\n");
    exit($code);
}

/**
 * @return list<string>
 */
function iqShowcase12CollectViolations(array $manifest, array $itemsPayload, array $answerKey, array $scoringSpec): array
{
    $violations = [];
    $items = is_array($itemsPayload['items'] ?? null) ? $itemsPayload['items'] : [];
    $answerEntries = is_array($answerKey['items'] ?? null) ? $answerKey['items'] : [];
    $scoringItems = is_array($scoringSpec['items'] ?? null) ? $scoringSpec['items'] : [];

    if ((string) ($manifest['scale_code'] ?? '') !== 'IQ_INTELLIGENCE_QUOTIENT') {
        $violations[] = 'manifest scale_code must be IQ_INTELLIGENCE_QUOTIENT';
    }

    if ((string) ($manifest['bank_id'] ?? '') !== IQ_SHOWCASE12_BANK_ID) {
        $violations[] = 'manifest bank_id mismatch';
    }

    if ((string) ($manifest['status'] ?? '') !== 'beta') {
        $violations[] = 'manifest status must stay beta';
    }

    if ((bool) ($manifest['notes']['beta_50_imported'] ?? true) !== false) {
        $violations[] = 'beta_50_imported must remain false for showcase-only import';
    }

    if (count($items) !== 12) {
        $violations[] = 'expected 12 showcase items';
    }

    if (count($answerEntries) !== count($items)) {
        $violations[] = 'answer_key item count mismatch';
    }

    if (count($scoringItems) !== count($items)) {
        $violations[] = 'scoring_spec item count mismatch';
    }

    $dimensionCounts = [];
    $answerDistribution = [];

    foreach ($items as $item) {
        if (! is_array($item)) {
            $violations[] = 'invalid item payload entry';

            continue;
        }

        $itemId = (string) ($item['item_id'] ?? 'missing_item_id');
        $dimension = (string) ($item['dimension'] ?? '');
        $dimensionCounts[$dimension] = ($dimensionCounts[$dimension] ?? 0) + 1;
        $answer = (string) ($item['correct_answer'] ?? '');
        $answerDistribution[$answer] = ($answerDistribution[$answer] ?? 0) + 1;

        foreach (['correct_answer', 'solution_rule', 'distractor_logic'] as $field) {
            $value = $item[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $violations[] = 'missing '.$field.' for '.$itemId;
            }
        }

        if ((string) ($item['scale_code'] ?? '') !== 'IQ_INTELLIGENCE_QUOTIENT') {
            $violations[] = 'non-canonical scale_code on '.$itemId;
        }

        if ((string) ($item['status'] ?? '') !== 'beta') {
            $violations[] = 'item status must stay beta for '.$itemId;
        }

        $assetHashes = is_array($item['asset_hashes'] ?? null) ? $item['asset_hashes'] : [];
        if ($assetHashes === []) {
            $violations[] = 'missing asset_hashes for '.$itemId;
        }

        $generatorMetadata = is_array($item['generator_metadata'] ?? null) ? $item['generator_metadata'] : [];
        if ($generatorMetadata === []) {
            $violations[] = 'missing generator_metadata for '.$itemId;
        }

        $stemAsset = $item['assets']['stem'] ?? null;
        if (! is_array($stemAsset) || (string) ($assetHashes['stem'] ?? '') !== iqSvgSha256Json($stemAsset)) {
            $violations[] = 'stem asset hash mismatch for '.$itemId;
        }

        $options = is_array($item['assets']['options'] ?? null) ? $item['assets']['options'] : [];
        if (count($options) !== 4) {
            $violations[] = 'option count must be 4 for '.$itemId;
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                $violations[] = 'invalid option payload for '.$itemId;

                continue;
            }

            $code = (string) ($option['code'] ?? '');
            $asset = $option['asset'] ?? null;
            if ($code === '' || ! is_array($asset)) {
                $violations[] = 'invalid option asset for '.$itemId;

                continue;
            }

            if ((string) (($assetHashes['options'] ?? [])[$code] ?? '') !== iqSvgSha256Json($asset)) {
                $violations[] = 'option asset hash mismatch for '.$itemId.' '.$code;
            }
        }
    }

    foreach (['VSPR' => 4, 'VSI' => 4, 'NPR' => 4] as $dimension => $expectedCount) {
        if (($dimensionCounts[$dimension] ?? 0) !== $expectedCount) {
            $violations[] = 'dimension coverage mismatch for '.$dimension;
        }
    }

    foreach (['A' => 3, 'B' => 3, 'C' => 3, 'D' => 3] as $answer => $expectedCount) {
        if (($answerDistribution[$answer] ?? 0) !== $expectedCount) {
            $violations[] = 'answer distribution mismatch for '.$answer;
        }
    }

    return $violations;
}

$fileMap = iqShowcase12FileMap();

try {
    $expected = iqShowcase12BankPayloads();
    $recorded = [
        'manifest' => iqSvgLoadJsonFile($fileMap['manifest']),
        'items' => iqSvgLoadJsonFile($fileMap['items']),
        'answer_key' => iqSvgLoadJsonFile($fileMap['answer_key']),
        'scoring_spec' => iqSvgLoadJsonFile($fileMap['scoring_spec']),
    ];

    foreach ($expected as $key => $payload) {
        $encoded = iqSvgPrettyJson($payload).PHP_EOL;
        $existing = file_get_contents($fileMap[$key]);
        if (! is_string($existing) || $existing !== $encoded) {
            iqShowcase12VerifyFail('artifact drift detected: '.iqSvgRelativePath($fileMap[$key]), 20);
        }
    }

    $violations = iqShowcase12CollectViolations(
        $recorded['manifest'],
        $recorded['items'],
        $recorded['answer_key'],
        $recorded['scoring_spec']
    );
    if ($violations !== []) {
        iqShowcase12VerifyFail(implode('; ', $violations), 21);
    }

    fwrite(STDOUT, iqSvgPrettyJson([
        'ok' => true,
        'bank_id' => IQ_SHOWCASE12_BANK_ID,
        'bank_dir' => iqSvgRelativePath(iqShowcase12BankDir()),
        'item_count' => 12,
        'dimensions' => ['VSPR' => 4, 'VSI' => 4, 'NPR' => 4],
        'answer_distribution' => ['A' => 3, 'B' => 3, 'C' => 3, 'D' => 3],
        'beta_50_imported' => false,
    ]).PHP_EOL);
} catch (Throwable $throwable) {
    iqShowcase12VerifyFail($throwable->getMessage(), 10);
}
