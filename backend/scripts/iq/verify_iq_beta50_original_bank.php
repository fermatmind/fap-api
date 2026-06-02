#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_beta50_original_bank_lib.php';

function failBeta50(string $message): never
{
    fwrite(STDERR, "IQ_BETA_50_ORIGINAL verification failed: {$message}\n");
    exit(1);
}

$files = iqBeta50FileMap();
foreach ($files as $path) {
    if (! is_file($path)) {
        failBeta50('Missing artifact '.$path);
    }
}

$expectedPayloads = iqBeta50BankPayloads();
foreach ($expectedPayloads as $key => $expectedPayload) {
    $actual = iqBeta50LoadJson($files[$key]);
    if ($actual !== $expectedPayload) {
        failBeta50($key.' does not match deterministic generator output');
    }
}

$manifest = iqBeta50LoadJson($files['manifest']);
$itemsPayload = iqBeta50LoadJson($files['items']);
$answerKey = iqBeta50LoadJson($files['answer_key']);
$scoring = iqBeta50LoadJson($files['scoring_spec']);

if (($manifest['bank_id'] ?? null) !== iqBeta50BankId() || ($itemsPayload['bank_id'] ?? null) !== iqBeta50BankId()) {
    failBeta50('Bank id mismatch');
}
if (($manifest['runtime_bound'] ?? true) !== false || ($manifest['public_take_enabled'] ?? true) !== false || (($scoring['runtime_binding']['enabled'] ?? true) !== false)) {
    failBeta50('Beta50 must remain runtime-unbound and public-take disabled');
}
if (($manifest['item_count_target'] ?? null) !== 50 || ($manifest['item_count_imported'] ?? null) !== 50) {
    failBeta50('Expected 50 imported beta50 items');
}

$items = $itemsPayload['items'] ?? [];
if (count($items) !== 50) {
    failBeta50('Expected 50 items');
}

$dimensionCounts = [];
$familyCounts = [];
$answerCounts = array_fill_keys(iqBeta30OptionCodes(), 0);
$ids = [];
foreach ($items as $item) {
    $id = $item['item_id'] ?? '';
    if ($id === '' || isset($ids[$id])) {
        failBeta50('Missing or duplicate item id '.$id);
    }
    $ids[$id] = true;

    $dimension = $item['dimension'] ?? '';
    $family = $item['item_family'] ?? '';
    $dimensionCounts[$dimension] = ($dimensionCounts[$dimension] ?? 0) + 1;
    $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;

    if (($item['option_count'] ?? null) !== 6 || count($item['assets']['options'] ?? []) !== 6) {
        failBeta50($id.' must have six options');
    }

    $answer = $item['correct_answer'] ?? '';
    if (! array_key_exists($answer, $answerCounts)) {
        failBeta50($id.' has invalid answer '.$answer);
    }
    $answerCounts[$answer]++;

    if (($answerKey['answers'][$id]['correct_answer'] ?? null) !== $answer) {
        failBeta50($id.' answer key mismatch');
    }
    if (($item['generator_metadata']['source_mode'] ?? null) !== 'repo_generated_original') {
        failBeta50($id.' must declare repo-generated original provenance');
    }
    if (($item['generator_metadata']['copied_from_third_party'] ?? true) !== false || ($item['generator_metadata']['traced_from_third_party'] ?? true) !== false) {
        failBeta50($id.' has invalid third-party provenance flags');
    }
    foreach (['seed', 'rule', 'difficulty', 'reviewer'] as $requiredMetadata) {
        if (! array_key_exists($requiredMetadata, $item['generator_metadata'] ?? [])) {
            failBeta50($id.' missing generator metadata '.$requiredMetadata);
        }
    }
    if (($item['asset_hashes']['stem'] ?? null) !== iqBeta30Sha256Json($item['assets']['stem'])) {
        failBeta50($id.' stem hash mismatch');
    }
    foreach ($item['assets']['options'] as $option) {
        $code = $option['code'] ?? '';
        if (($item['asset_hashes']['options'][$code] ?? null) !== iqBeta30Sha256Json($option['asset'])) {
            failBeta50($id.' option '.$code.' hash mismatch');
        }
    }
}

$expectedDimensionCounts = iqBeta50ExpectedDimensionCounts();
$expectedFamilyCounts = iqBeta50ExpectedFamilyCounts();
ksort($dimensionCounts);
ksort($familyCounts);
ksort($expectedDimensionCounts);
ksort($expectedFamilyCounts);
if ($dimensionCounts !== $expectedDimensionCounts) {
    failBeta50('Dimension counts mismatch: '.json_encode($dimensionCounts));
}
if ($familyCounts !== $expectedFamilyCounts) {
    failBeta50('Family counts mismatch: '.json_encode($familyCounts));
}
if (max($answerCounts) - min($answerCounts) > 1) {
    failBeta50('Answers must be near-balanced across A-F');
}
if (($answerKey['public_payload'] ?? true) !== false || ($answerKey['storage_policy'] ?? '') !== 'backend_only_never_emit_to_public_api') {
    failBeta50('Answer key must remain backend-only');
}
if (($manifest['public_payload_policy']['may_emit_items'] ?? true) !== false || ($manifest['public_payload_policy']['may_emit_answer_key'] ?? true) !== false || ($manifest['public_payload_policy']['may_emit_solution_rule'] ?? true) !== false || ($manifest['public_payload_policy']['may_emit_generator_metadata'] ?? true) !== false) {
    failBeta50('Public payload policy must hide beta50 items, answer key, solution rules, and generator metadata');
}

echo "IQ_BETA_50_ORIGINAL verification passed.\n";
