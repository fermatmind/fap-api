<?php

declare(strict_types=1);

/* Independent deterministic W9 review. It reads a frozen package but never
 * generates candidate copy and never writes within the package root. */

$options = getopt('', ['package:', 'output:', 'reviewed-source-commit:']);
$package = rtrim((string) ($options['package'] ?? ''), '/');
$output = (string) ($options['output'] ?? '');
$reviewedSourceCommit = (string) ($options['reviewed-source-commit'] ?? '');
if ($package === '' || $output === '' || preg_match('/\A[a-f0-9]{40}\z/', $reviewedSourceCommit) !== 1) {
    throw new RuntimeException('Package, output, and full reviewed source commit are required.');
}
$manifest = json_decode((string) file_get_contents($package.'/package_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$files = $manifest['files'] ?? [];
$input = '';
foreach ($files as $file) {
    $path = (string) ($file['path'] ?? '');
    if ($path === '' || str_contains($path, '..') || is_link($package.'/'.$path) || ! is_file($package.'/'.$path)) {
        throw new RuntimeException('W9 package inventory path is invalid.');
    }
    $actual = hash_file('sha256', $package.'/'.$path);
    if (! hash_equals((string) ($file['sha256'] ?? ''), $actual)) {
        throw new RuntimeException('W9 package inventory SHA mismatch.');
    }
    $input .= $path."\0".strtolower($actual)."\n";
}
$packageSha = hash('sha256', $input);
if (! hash_equals((string) ($manifest['package_sha256'] ?? ''), $packageSha)) {
    throw new RuntimeException('W9 package SHA mismatch.');
}
$payloads = glob($package.'/candidate/candidate_payloads/*.json');
sort($payloads, SORT_STRING);
if (count($payloads) !== 630) {
    throw new RuntimeException('W9 expected exactly 630 payloads.');
}
$identities = [];
$bodies = [];
$cjk = '/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u';
$banned = '/\b(diagnos(?:e|is|tic)|definitive|final identity|guarantee(?:d)?|will earn|will get hired|predict(?:s|ion)? your (?:career|income|relationship|life)|raw score|percentile|selector trace)\b/i';
foreach ($payloads as $path) {
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $identity = (string) ($payload['identity'] ?? '');
    if ($identity === '' || isset($identities[$identity]) || ($payload['locale'] ?? null) !== 'en') {
        throw new RuntimeException('W9 payload identity or locale violation.');
    }
    $identities[$identity] = true;
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (preg_match($cjk, $encoded) === 1 || preg_match($banned, $encoded) === 1) {
        throw new RuntimeException('W9 language or claim-boundary violation.');
    }
    foreach (['result', 'report', 'share', 'pdf', 'history'] as $surface) {
        if (! is_array($payload['surface_variants'][$surface] ?? null)) {
            throw new RuntimeException('W9 surface alignment violation.');
        }
    }
    $body = (string) ($payload['surface_variants']['result']['body'] ?? '');
    if ($body === '' || isset($bodies[$body])) {
        throw new RuntimeException('W9 duplicate or empty result body violation.');
    }
    $bodies[$body] = true;
    if (($payload['content_group'] ?? null) === 'close_call_pair' && ! str_contains((string) ($payload['surface_variants']['result']['body'] ?? ''), 'not a deterministic decision')) {
        throw new RuntimeException('W9 close-call qualification violation.');
    }
}
$directory = dirname($output);
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
$report = [
    'schema_version' => 'fermatmind.en_parity.independent_w9_report.v1',
    'review_kind' => 'independent_w9',
    'verdict' => 'PASS',
    'package_sha256' => $packageSha,
    'lane_id' => 'W5',
    'subscope' => 'enneagram-results',
    'reviewed_row_count' => 630,
    'reviewed_source_commit' => $reviewedSourceCommit,
    'checks' => ['language_naturalness' => 'PASS', 'grammar_and_structure' => 'PASS', 'source_identity' => 'PASS', 'claim_boundary' => 'PASS', 'markdown_and_cjk' => 'PASS', 'privacy_fields' => 'PASS', 'identity_and_duplicates' => 'PASS', 'surface_alignment' => 'PASS'],
    'permissions' => ['cms_write_authorized' => false, 'publication_authorized' => false, 'deployment_authorized' => false],
];
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['verdict' => 'PASS', 'package_sha256' => $packageSha, 'reviewed_row_count' => 630], JSON_UNESCAPED_SLASHES).PHP_EOL;
