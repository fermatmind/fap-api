#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_beta30_original_bank_lib.php';

function failBeta30(string $message): never
{
    fwrite(STDERR, "IQ_BETA_30_ORIGINAL verification failed: {$message}\n");
    exit(1);
}

$files = iqBeta30FileMap();
foreach ($files as $path) {
    if (! is_file($path)) {
        failBeta30('Missing artifact '.$path);
    }
}

$expectedPayloads = iqBeta30BankPayloads();
foreach ($expectedPayloads as $key => $expectedPayload) {
    $actual = iqBeta30LoadJson($files[$key]);
    if ($actual !== $expectedPayload) {
        failBeta30($key.' does not match deterministic generator output');
    }
}

$manifest = iqBeta30LoadJson($files['manifest']);
$itemsPayload = iqBeta30LoadJson($files['items']);
$answerKey = iqBeta30LoadJson($files['answer_key']);
$scoring = iqBeta30LoadJson($files['scoring_spec']);

if (($manifest['bank_id'] ?? null) !== iqBeta30BankId() || ($itemsPayload['bank_id'] ?? null) !== iqBeta30BankId()) {
    failBeta30('Bank id mismatch');
}
if (($manifest['runtime_bound'] ?? true) !== false || (($scoring['runtime_binding']['enabled'] ?? true) !== false)) {
    failBeta30('Bank must remain runtime-unbound in IQ-BANK-30-02');
}
if (($manifest['copyright_policy']['copied_from_third_party'] ?? true) !== false || ($manifest['copyright_policy']['traced_from_third_party'] ?? true) !== false) {
    failBeta30('Copyright policy must declare original non-copied assets');
}

$items = $itemsPayload['items'] ?? [];
if (count($items) !== 30) {
    failBeta30('Expected 30 items');
}

$dimensionCounts = [];
$familyCounts = [];
$answerCounts = array_fill_keys(iqBeta30OptionCodes(), 0);
$ids = [];
foreach ($items as $item) {
    $id = $item['item_id'] ?? '';
    if ($id === '' || isset($ids[$id])) {
        failBeta30('Missing or duplicate item id '.$id);
    }
    $ids[$id] = true;

    $dimension = $item['dimension'] ?? '';
    $family = $item['item_family'] ?? '';
    $dimensionCounts[$dimension] = ($dimensionCounts[$dimension] ?? 0) + 1;
    $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;

    if (($item['option_count'] ?? null) !== 6) {
        failBeta30($id.' must have six options');
    }
    if (count($item['assets']['options'] ?? []) !== 6) {
        failBeta30($id.' must provide six option assets');
    }

    $answer = $item['correct_answer'] ?? '';
    if (! array_key_exists($answer, $answerCounts)) {
        failBeta30($id.' has invalid answer '.$answer);
    }
    $answerCounts[$answer]++;

    if (($answerKey['answers'][$id]['correct_answer'] ?? null) !== $answer) {
        failBeta30($id.' answer key mismatch');
    }
    if (($item['generator_metadata']['source_mode'] ?? null) !== 'repo_generated_original') {
        failBeta30($id.' must declare repo-generated original provenance');
    }
    if (($item['generator_metadata']['copied_from_third_party'] ?? true) !== false || ($item['generator_metadata']['traced_from_third_party'] ?? true) !== false) {
        failBeta30($id.' has invalid third-party provenance flags');
    }
    if (($item['assets']['stem']['kind'] ?? null) !== 'inline_svg_markup' || ($item['assets']['stem']['mime_type'] ?? null) !== 'image/svg+xml') {
        failBeta30($id.' stem must be inline SVG markup asset');
    }
    if (($item['asset_hashes']['stem'] ?? null) !== iqBeta30Sha256Json($item['assets']['stem'])) {
        failBeta30($id.' stem hash mismatch');
    }
    foreach ($item['assets']['options'] as $option) {
        $code = $option['code'] ?? '';
        if (! in_array($code, iqBeta30OptionCodes(), true)) {
            failBeta30($id.' invalid option code '.$code);
        }
        if (($option['asset']['kind'] ?? null) !== 'inline_svg_markup' || ($option['asset']['mime_type'] ?? null) !== 'image/svg+xml') {
            failBeta30($id.' option '.$code.' must be inline SVG markup asset');
        }
        if (($item['asset_hashes']['options'][$code] ?? null) !== iqBeta30Sha256Json($option['asset'])) {
            failBeta30($id.' option '.$code.' hash mismatch');
        }
    }
}

$expectedDimensionCounts = iqBeta30ExpectedDimensionCounts();
$expectedFamilyCounts = iqBeta30ExpectedFamilyCounts();
ksort($dimensionCounts);
ksort($familyCounts);
ksort($expectedDimensionCounts);
ksort($expectedFamilyCounts);
if ($dimensionCounts !== $expectedDimensionCounts) {
    failBeta30('Dimension counts mismatch: '.json_encode($dimensionCounts));
}
if ($familyCounts !== $expectedFamilyCounts) {
    failBeta30('Family counts mismatch: '.json_encode($familyCounts));
}
if ($answerCounts !== array_fill_keys(iqBeta30OptionCodes(), 5)) {
    failBeta30('Answers must be balanced across A-F');
}
if (($answerKey['public_payload'] ?? true) !== false || ($answerKey['storage_policy'] ?? '') !== 'backend_only_never_emit_to_public_api') {
    failBeta30('Answer key must remain backend-only');
}
if (($manifest['public_payload_policy']['may_emit_answer_key'] ?? true) !== false || ($manifest['public_payload_policy']['may_emit_solution_rule'] ?? true) !== false) {
    failBeta30('Public payload policy must hide answer key and solution rules');
}

echo "IQ_BETA_30_ORIGINAL verification passed.\n";
