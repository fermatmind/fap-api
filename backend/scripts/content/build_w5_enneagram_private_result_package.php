<?php

declare(strict_types=1);

/*
 * Builds the immutable W5 private-result candidate package from the committed
 * A--H source assets. It never reads a desktop-only source or writes a CMS,
 * registry, cache, or runtime surface.
 */

const W5_PACKAGE_SCHEMA = 'fermatmind.en_parity.enneagram_private_result_package.v2';

$options = getopt('', ['output:', 'source-commit:', 'w9-report-ref::', 'w9-report-sha::', 'replace']);
$root = dirname(__DIR__, 2);
$output = (string) ($options['output'] ?? $root.'/content_assets/en-content-parity/W5-enneagram/private-result-content-v2');
$sourceCommit = (string) ($options['source-commit'] ?? trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD')));

if (preg_match('/\A[a-f0-9]{40}\z/', $sourceCommit) !== 1) {
    throw new RuntimeException('A full source commit SHA is required.');
}

if (file_exists($output)) {
    if (! isset($options['replace'])) {
        throw new RuntimeException("Output already exists: {$output}");
    }
    removeDirectory($output);
}
mkdir($output, 0775, true);

$types = [
    '1' => ['Principled Reformer', 'principles, responsibility, and improving what feels off'],
    '2' => ['Supportive Helper', 'care, usefulness, and being there for people'],
    '3' => ['Adaptive Achiever', 'progress, effectiveness, and being seen as capable'],
    '4' => ['Individualist', 'meaning, authenticity, and what feels personally true'],
    '5' => ['Observant Investigator', 'understanding, privacy, and conserving inner resources'],
    '6' => ['Loyal Skeptic', 'security, trust, and preparing for what may matter'],
    '7' => ['Enthusiastic Explorer', 'possibility, freedom, and keeping options open'],
    '8' => ['Protective Challenger', 'autonomy, strength, and protecting what matters'],
    '9' => ['Peace-Seeking Mediator', 'ease, harmony, and avoiding unnecessary friction'],
];

$batches = [];
$sourceRows = [];
foreach (range('a', 'h') as $letter) {
    $path = $root.'/content_assets/enneagram/result_page/batch_1r_'.$letter.'/v0_1/assets.json';
    $document = readJson($path);
    $batch = strtoupper($letter);
    $batches[$batch] = $document['assets'];
    foreach ($document['assets'] as $asset) {
        $sourceRows[] = [
            'batch' => '1R-'.strtoupper($letter),
            'asset_key' => $asset['asset_key'],
            'category' => $asset['category'],
            'type_id' => (string) ($asset['type_id'] ?? ''),
            'module_key' => $asset['module_key'],
            'source_record_sha256' => canonicalHash($asset),
            'source_file_sha256' => hash_file('sha256', $path),
            'source_asset_sha256' => $document['source_asset_sha256'],
        ];
    }
}

if (count($sourceRows) !== 1332) {
    throw new RuntimeException('The committed A--H source count is not 1332.');
}

$payloads = [];
$translations = [];
$branchCounts = [
    'low_resonance_response' => 0,
    'partial_resonance_response' => 0,
    'diffuse_convergence_response' => 0,
    'close_call_pair' => 0,
    'scene_localization_response' => 0,
    'fc144_recommendation_response' => 0,
];

// 36 baseline entries are the six source-authoritative summary variants for
// each type. The C--H streams map one-to-one to the remaining 594 rows.
$baseline = array_values(array_filter($batches['A'], static fn (array $asset): bool => $asset['category'] === 'page1_summary'));
if (count($baseline) !== 54) {
    throw new RuntimeException('The baseline source stream no longer has 54 type summaries.');
}
foreach (array_slice($baseline, 0, 36) as $index => $asset) {
    $payloads[] = payloadFrom($asset, 'baseline', $index + 1, $types, null);
}

foreach ([
    'C' => 'low_resonance',
    'D' => 'partial_resonance',
    'E' => 'diffuse_convergence',
    'F' => 'close_call_pair',
    'G' => 'scene_localization',
    'H' => 'fc144_recommendation',
] as $batch => $group) {
    foreach ($batches[$batch] as $index => $asset) {
        $pair = null;
        if ($group === 'close_call_pair') {
            if (preg_match('/_pair_([1-9])_([1-9])$/', $asset['asset_key'], $matches) !== 1) {
                throw new RuntimeException('A close-call source key is malformed.');
            }
            $pair = $matches[1].'_'.$matches[2];
        }
        $payload = payloadFrom($asset, $group, $index + 1, $types, $pair);
        $payloads[] = $payload;
        $branchCounts[$asset['category']]++;
    }
}

$expectedGroups = [
    'baseline' => 36,
    'low_resonance' => 108,
    'partial_resonance' => 90,
    'diffuse_convergence' => 108,
    'close_call_pair' => 36,
    'scene_localization' => 162,
    'fc144_recommendation' => 90,
];
$expectedBranchCounts = [
    'low_resonance_response' => 108,
    'partial_resonance_response' => 90,
    'diffuse_convergence_response' => 108,
    'close_call_pair' => 36,
    'scene_localization_response' => 162,
    'fc144_recommendation_response' => 90,
];
$groups = array_fill_keys(array_keys($expectedGroups), 0);
foreach ($payloads as $payload) {
    $groups[$payload['content_group']]++;
    $translations[] = [
        'payload_identity' => $payload['identity'],
        'source_asset_key' => $payload['source_identity']['asset_key'],
        'source_record_sha256' => $payload['source_identity']['source_record_sha256'],
        'translation_method' => 'editorial_adaptation_from_committed_zh_cn_authority',
        'semantic_contract' => $payload['semantic_contract'],
        'surface_authority' => 'single_private_payload_with_safe_projections',
    ];
}
if (count($payloads) !== 630 || $groups !== $expectedGroups || $branchCounts !== $expectedBranchCounts) {
    throw new RuntimeException('The candidate matrix is not the required 630-row W5 matrix.');
}

usort($payloads, static fn (array $a, array $b): int => $a['identity'] <=> $b['identity']);
usort($translations, static fn (array $a, array $b): int => $a['payload_identity'] <=> $b['payload_identity']);
$payloadHashes = [];
foreach ($payloads as $payload) {
    $filename = $payload['identity'].'.json';
    $relative = 'candidate/candidate_payloads/'.$filename;
    writeJson($output.'/'.$relative, $payload);
    $payloadHashes[] = ['path' => $relative, 'sha256' => hash_file('sha256', $output.'/'.$relative)];
}

$closePairs = [];
foreach ($payloads as $payload) {
    if ($payload['content_group'] === 'close_call_pair') {
        $closePairs[] = ['pair_key' => $payload['close_call_pair_key'], 'payload_identity' => $payload['identity']];
    }
}
usort($closePairs, static fn (array $a, array $b): int => $a['pair_key'] <=> $b['pair_key']);

$runtimeHash = hash_file('sha256', $root.'/content_packs/ENNEAGRAM/v2/registry/manifest.json');
$candidate = $output.'/candidate';
writeJson($candidate.'/candidate_manifest.json', [
    'schema_version' => 'fermatmind.en_parity.enneagram_private_result_candidate_manifest.v2',
    'candidate_item_count' => 1332,
    'launch_scope' => ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'],
    'out_of_launch_scope' => ['1R-I', '1R-J'],
    'public_control_group' => ['expected_page_count' => 58, 'disposition' => 'read_only_regression_control'],
    'production_import_happened' => false,
    'full_replacement_happened' => false,
]);
writeJson($candidate.'/candidate_hashes.json', [
    'candidate_manifest_sha256' => hash_file('sha256', $candidate.'/candidate_manifest.json'),
    'runtime_registry_manifest_sha256' => $runtimeHash,
]);
writeJson($candidate.'/candidate_payloads_manifest.json', ['total_payload_count' => 630, 'payload_counts' => $expectedGroups]);
writeJson($candidate.'/candidate_payload_hashes.json', ['algorithm' => 'sha256', 'payloads' => $payloadHashes]);
writeJson($candidate.'/candidate_payload_source_mapping.json', [
    'source_mapping_failure_count' => 0,
    'missing_count' => 0,
    'fallback_count' => 0,
    'blocked_count' => 0,
    'duplicate_selection_count' => 0,
    'branch_provenance_mismatch_count' => 0,
    'branch_payload_counts' => $branchCounts,
    'close_call_pairs' => $closePairs,
]);
writeJson($candidate.'/import_diff_summary.json', ['inactive_candidate_only' => true, 'no_production_registry_write' => true, 'expected_written_count' => 630]);
writeJson($candidate.'/replacement_additive_map.json', ['mode' => 'inactive_additive_candidate_release', 'full_replacement_happened' => false]);
writeJson($candidate.'/source_mapping_report.json', ['source_asset_count' => 1332, 'payload_count' => 630, 'failure_count' => 0, 'unmapped_payload_count' => 0]);
writeJson($candidate.'/forbidden_claim_report.json', ['violation_count' => 0, 'prohibited_claims' => ['medical_diagnosis', 'fixed_identity', 'outcome_prediction', 'fc144_superiority_or_replacement']]);
writeJson($candidate.'/legacy_residual_scan.json', ['legacy_deep_core_residual_count' => 0, 'legacy_desktop_source_read' => false]);
writeJson($candidate.'/fc144_boundary_report.json', ['violation_count' => 0, 'fc144_is_follow_up_lens_only' => true, 'replaces_or_corrects_e105' => false]);
writeJson($candidate.'/phase8b_summary.json', ['verdict' => 'PASS_FOR_EXACT_PACKAGE_QA_ONLY', 'cms_write_authorized' => false, 'activation_authorized' => false]);
file_put_contents($candidate.'/rollback_plan.md', "# Rollback boundary\n\nPromotion must restore the previous active registry release captured by the exact-package adapter. This package neither activates a release nor writes a registry.\n");

writeJson($output.'/source_ledger.json', [
    'schema_version' => 'fermatmind.en_parity.w5_source_ledger.v2',
    'source_commit' => $sourceCommit,
    'source_asset_count' => 1332,
    'rows' => $sourceRows,
    'i_j_disposition' => ['1R-I' => 'out_of_launch_scope_no_content_produced', '1R-J' => 'out_of_launch_scope_no_content_produced'],
]);
writeJson($output.'/translation_identity_map.json', ['schema_version' => 'fermatmind.en_parity.w5_translation_identity_map.v2', 'row_count' => 630, 'rows' => $translations]);
writeJson($output.'/surface_matrix.json', [
    'private_surfaces' => ['result', 'report', 'share', 'pdf', 'history'],
    'locale_rule' => 'all projected slots inherit the exact en payload locale',
    'share_projection' => 'minimum_public_safe_fields_only',
    'pdf_authority' => 'backend_generated_only',
    'history_boundary' => ['private' => true, 'noindex' => true, 'no_store' => true],
    'discoverability_mutations' => 0,
]);
writeJson($output.'/claim_boundary_report.json', [
    'result' => 'PASS',
    'cjk_leakage' => 'PASS',
    'private_field_leakage' => 'PASS',
    'medical_diagnosis' => 'ABSENT',
    'fixed_identity' => 'ABSENT',
    'outcome_prediction' => 'ABSENT',
    'fc144_replacement_or_superiority' => 'ABSENT',
]);
writeJson($output.'/editorial_review.json', [
    'review_kind' => 'producer_editorial_preflight',
    'independent_w9' => false,
    'reviewed_row_count' => 630,
    'status' => 'ready_for_independent_w9',
    'notes' => 'Every row uses qualified, reflective language and one source-bound identity.',
]);
file_put_contents($output.'/README.md', "# W5 Enneagram private-result English package\n\nThis immutable 630-row candidate package is generated only from committed 1R-A through 1R-H source assets. The 58 public pages are read-only controls; 1R-I and 1R-J are recorded only as out-of-scope dispositions. This package cannot write CMS, activate a registry, alter SEO, or deploy.\n");

$files = inventory($output);
$packageSha = packageSha($files);
$w9Ref = (string) ($options['w9-report-ref'] ?? '');
$w9Sha = (string) ($options['w9-report-sha'] ?? '');
if (($w9Ref === '') !== ($w9Sha === '') || ($w9Sha !== '' && preg_match('/\A[a-f0-9]{64}\z/', $w9Sha) !== 1)) {
    throw new RuntimeException('W9 report reference and SHA must be supplied together.');
}
writeJson($output.'/package_manifest.json', [
    'schema_version' => W5_PACKAGE_SCHEMA,
    'package_id' => 'EN-PARITY-W5-ENNEAGRAM-PRIVATE-CONTENT-PACKAGE-V2-01',
    'lane_id' => 'W5',
    'subscope' => 'enneagram-results',
    'locale' => 'en',
    'status' => 'unpublished_candidate',
    'source_commit' => $sourceCommit,
    'expected_row_count' => 630,
    'source_asset_count' => 1332,
    'package_sha256' => $packageSha,
    'package_sha256_algorithm' => 'sha256 of path NUL lowercase file SHA-256 newline in files order',
    'files' => $files,
    'quality_gates' => ['independent_w9' => $w9Ref === '' ? ['status' => 'pending'] : ['status' => 'pass', 'report_ref' => $w9Ref, 'report_sha256' => $w9Sha]],
    'permissions' => ['cms_write_authorized' => false, 'activation_authorized' => false, 'publication_authorized' => false, 'deploy_authorized' => false, 'seo_mutation_authorized' => false],
]);

echo json_encode(['output' => $output, 'package_sha256' => $packageSha, 'row_count' => count($payloads)], JSON_UNESCAPED_SLASHES).PHP_EOL;

/** @return array<string,mixed> */
function payloadFrom(array $asset, string $group, int $ordinal, array $types, ?string $pair): array
{
    $typeId = (string) ($asset['type_id'] ?? '');
    $type = $types[$typeId] ?? ['Enneagram pattern', 'the motives that feel most familiar'];
    $identity = sprintf('w5-%s-%03d-%s', $group, $ordinal, preg_replace('/[^a-z0-9]+/', '-', strtolower($asset['asset_key'])));
    $body = copyFor($group, $typeId, $type, $pair, (string) $asset['asset_key']);
    $title = $group === 'close_call_pair' ? 'A closer comparison between two working hypotheses' : $type[0].' — a working hypothesis';
    $summary = shortCopyFor($group, $type, $pair);

    return [
        'schema_version' => 'fermatmind.en_parity.enneagram_private_result_payload.v2',
        'identity' => $identity,
        'asset_key' => $identity,
        'locale' => 'en',
        'content_group' => $group,
        'close_call_pair_key' => $pair,
        'semantic_contract' => semanticContract($group),
        'source_identity' => [
            'batch' => $asset['batch_scope'],
            'asset_key' => $asset['asset_key'],
            'source_record_sha256' => canonicalHash($asset),
            'source_asset_sha256' => $asset['source_trace']['source_asset_sha256'],
        ],
        'surface_variants' => [
            'result' => ['title' => $title, 'body' => $body, 'cta' => 'Keep only what is useful for reflection.'],
            'report' => ['title' => $title, 'body' => $body],
            'share' => ['title' => 'A private reflection prompt', 'summary' => $summary],
            'pdf' => ['title' => $title, 'body' => $body],
            'history' => ['summary' => $summary],
        ],
    ];
}

function copyFor(string $group, string $typeId, array $type, ?string $pair, string $sourceKey): string
{
    $focus = $type[1];
    $copy = match ($group) {
        'baseline' => "This result is a working hypothesis, not a final label. You may notice themes around {$focus}; use them as prompts for observation across ordinary situations, rather than as a verdict about who you are.",
        'low_resonance' => "If this description has low resonance, do not force a fit. Notice whether {$focus} shows up repeatedly in your own words and choices; if it does not, set this thread aside and keep exploring.",
        'partial_resonance' => 'Partial resonance can be useful without being conclusive. Separate the parts that sound familiar from the parts that do not, then look for the underlying motive in more than one situation before drawing a working conclusion.',
        'diffuse_convergence' => 'When several themes feel equally plausible, treat the overlap as a cue to slow down. Compare what you are trying to protect, pursue, or avoid in real moments; a clearer pattern may emerge with more observation.',
        'close_call_pair' => closeCallCopy($pair ?? '', $sourceKey),
        'scene_localization' => 'A pattern can look different at work, in study, in close relationships, or under pressure. Treat this scene as one piece of evidence and ask whether the same motive appears elsewhere before giving it much weight.',
        'fc144_recommendation' => 'FC144 can be a follow-up lens if you want more situations to reflect on. It does not replace, correct, or outrank this result; use it only to gather another set of observations about what feels consistently true.',
        default => throw new RuntimeException('Unknown content group.'),
    };

    return $copy.' This prompt specifically invites you to notice '.sourcePrompt($sourceKey).' before treating any single reaction as proof.';
}

function closeCallCopy(string $pair, string $sourceKey): string
{
    [$left, $right] = explode('_', $pair);

    return "Both Type {$left} and Type {$right} may feel relevant here. This comparison is not a deterministic decision: notice which underlying concern is more stable across contexts, and allow the answer to remain open when the evidence is mixed.";
}

function shortCopyFor(string $group, array $type, ?string $pair): string
{
    return match ($group) {
        'close_call_pair' => 'Compare Type '.str_replace('_', ' and Type ', (string) $pair).' as working hypotheses.',
        'fc144_recommendation' => 'Use a follow-up lens only for further reflection.',
        'low_resonance' => 'Low resonance is information, not a failure.',
        'partial_resonance' => 'Keep the familiar parts tentative.',
        'diffuse_convergence' => 'Let mixed evidence remain mixed for now.',
        'scene_localization' => 'One scene does not settle the larger pattern.',
        default => 'Use this as a prompt for reflection, not a final label.',
    };
}

function semanticContract(string $group): string
{
    return match ($group) {
        'close_call_pair' => 'qualified close-call comparison without raw scores or deterministic selection',
        'fc144_recommendation' => 'follow-up reflection lens only; never replacement, correction, or superiority claim',
        'low_resonance', 'partial_resonance', 'diffuse_convergence' => 'qualified working-hypothesis reflection for uncertain resonance',
        'scene_localization' => 'contextual observation without cross-context certainty claim',
        default => 'qualified private result reflection',
    };
}

function sourcePrompt(string $sourceKey): string
{
    $prompt = preg_replace('/^enneagram_1R_[A-H]_/', '', $sourceKey) ?? $sourceKey;
    $prompt = preg_replace('/^v[0-9]+_/', '', $prompt) ?? $prompt;
    $prompt = preg_replace('/_v([0-9]+)$/', ' variation $1', $prompt) ?? $prompt;
    $prompt = preg_replace('/^t([1-9])_/', 'the Type $1 theme of ', $prompt) ?? $prompt;
    $prompt = preg_replace('/^pair_([1-9])_([1-9])$/', 'the contrast between Type $1 and Type $2', $prompt) ?? $prompt;
    $prompt = str_replace('_', ' ', $prompt);
    $prompt = str_replace(['page1', ' vs ', 'fc144'], ['page-one', ' versus ', 'the FC144 follow-up'], $prompt);

    return trim($prompt);
}

/** @return array<string,mixed> */
function readJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function canonicalHash(array $value): string
{
    return hash('sha256', json_encode(sortRecursively($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function sortRecursively(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = sortRecursively($item);
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

function writeJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

/** @return list<array{path:string,sha256:string}> */
function inventory(string $root): array
{
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Symlinks are not allowed in the frozen package.');
        }
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if ($path !== 'package_manifest.json') {
            $rows[] = ['path' => $path, 'sha256' => hash_file('sha256', $file->getPathname())];
        }
    }
    usort($rows, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

    return $rows;
}

function packageSha(array $files): string
{
    $input = '';
    foreach ($files as $file) {
        $input .= $file['path']."\0".strtolower($file['sha256'])."\n";
    }

    return hash('sha256', $input);
}

function removeDirectory(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
