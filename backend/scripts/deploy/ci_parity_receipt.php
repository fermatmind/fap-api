#!/usr/bin/env php
<?php

declare(strict_types=1);

const RECEIPT_SCHEMA = 'fermatmind.api.ci-parity-receipt.v1';
const TOPOLOGY_IDENTITY = 'mysql:8.0|redis:6-alpine';
const MATRIX_IDENTITY = 'staging-parity:legacy+v2+bigfive+enneagram';
const CONFIG_PATHS = [
    'backend/config/cache.php',
    'backend/config/content_packs.php',
    'backend/config/database.php',
    'backend/config/fap_attempts.php',
    'backend/config/queue.php',
    'backend/phpunit.xml',
];
const RECEIPT_KEYS = [
    'ci_verify_mbti_sha256',
    'ci_workflow_run_attempt',
    'ci_workflow_run_id',
    'composer_lock_sha256',
    'config_fingerprint',
    'generated_at',
    'matrix_identity',
    'repo',
    'result',
    'schema_version',
    'source_sha',
    'topology_identity',
];

function fail(string $message): never
{
    fwrite(STDERR, "ci parity receipt error: {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function parseOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            fail("unexpected argument: {$argument}");
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '' || array_key_exists($name, $options)) {
            fail("invalid option: {$argument}");
        }
        $options[$name] = $value;
    }

    return $options;
}

/** @param array<string, string> $options */
function requireOption(array $options, string $name): string
{
    $value = $options[$name] ?? '';
    if ($value === '') {
        fail("missing required option: --{$name}=...");
    }

    return $value;
}

function assertPattern(string $value, string $pattern, string $label): void
{
    if (preg_match($pattern, $value) !== 1) {
        fail("{$label} is malformed");
    }
}

function repoRoot(): string
{
    return dirname(__DIR__, 3);
}

function requiredFileHash(string $relativePath): string
{
    $path = repoRoot().'/'.$relativePath;
    if (! is_file($path)) {
        fail("required source file is missing: {$relativePath}");
    }

    $digest = hash_file('sha256', $path);
    if (! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
        fail("could not hash required source file: {$relativePath}");
    }

    return $digest;
}

function configFingerprint(): string
{
    $files = [];
    foreach (CONFIG_PATHS as $relativePath) {
        $files[$relativePath] = requiredFileHash($relativePath);
    }

    $canonical = json_encode([
        'topology_identity' => TOPOLOGY_IDENTITY,
        'matrix_identity' => MATRIX_IDENTITY,
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return hash('sha256', $canonical);
}

/** @return array<string, string> */
function expectedIdentity(): array
{
    return [
        'topology_identity' => TOPOLOGY_IDENTITY,
        'matrix_identity' => MATRIX_IDENTITY,
        'ci_verify_mbti_sha256' => requiredFileHash('backend/scripts/ci_verify_mbti.sh'),
        'composer_lock_sha256' => requiredFileHash('backend/composer.lock'),
        'config_fingerprint' => configFingerprint(),
    ];
}

/** @param array<string, string> $options */
function createReceipt(array $options): void
{
    $output = requireOption($options, 'output');
    $repo = requireOption($options, 'repo');
    $sha = requireOption($options, 'sha');
    $runId = requireOption($options, 'run-id');
    $runAttempt = requireOption($options, 'run-attempt');
    assertPattern($repo, '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', 'repository identity');
    assertPattern($sha, '/\A[a-f0-9]{40}\z/', 'source SHA');
    assertPattern($runId, '/\A[1-9][0-9]*\z/', 'CI workflow run ID');
    assertPattern($runAttempt, '/\A[1-9][0-9]*\z/', 'CI workflow run attempt');

    $parent = dirname($output);
    if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
        fail('could not create receipt directory');
    }

    $receipt = [
        'schema_version' => RECEIPT_SCHEMA,
        'repo' => $repo,
        'source_sha' => $sha,
        'ci_workflow_run_id' => $runId,
        'ci_workflow_run_attempt' => $runAttempt,
        ...expectedIdentity(),
        'result' => 'success',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($output, $json, LOCK_EX) === false || chmod($output, 0444) === false) {
        fail('could not write immutable receipt');
    }
}

/** @return array<string, mixed> */
function readReceipt(string $path): array
{
    $json = @file_get_contents($path);
    if (! is_string($json)) {
        fail('receipt file is missing');
    }

    try {
        $receipt = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('receipt is not valid JSON');
    }
    if (! is_array($receipt) || array_is_list($receipt)) {
        fail('receipt must be a JSON object');
    }

    $keys = array_keys($receipt);
    sort($keys);
    $expectedKeys = RECEIPT_KEYS;
    sort($expectedKeys);
    if ($keys !== $expectedKeys) {
        fail('receipt fields do not match the supported fail-closed schema');
    }

    return $receipt;
}

/** @param array<string, string> $options */
function verifyReceipt(array $options): void
{
    $receipt = readReceipt(requireOption($options, 'receipt'));
    $expectedRepo = requireOption($options, 'expected-repo');
    $expectedSha = requireOption($options, 'expected-sha');
    $expectedRunId = requireOption($options, 'expected-run-id');
    $expectedRunAttempt = requireOption($options, 'expected-run-attempt');
    assertPattern($expectedRepo, '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', 'expected repository identity');
    assertPattern($expectedSha, '/\A[a-f0-9]{40}\z/', 'expected source SHA');
    assertPattern($expectedRunId, '/\A[1-9][0-9]*\z/', 'expected CI workflow run ID');
    assertPattern($expectedRunAttempt, '/\A[1-9][0-9]*\z/', 'expected CI workflow run attempt');

    $expected = [
        'schema_version' => RECEIPT_SCHEMA,
        'repo' => $expectedRepo,
        'source_sha' => $expectedSha,
        'ci_workflow_run_id' => $expectedRunId,
        'ci_workflow_run_attempt' => $expectedRunAttempt,
        ...expectedIdentity(),
        'result' => 'success',
    ];
    foreach ($expected as $field => $value) {
        if (! is_string($receipt[$field] ?? null) || ! hash_equals($value, $receipt[$field])) {
            fail("receipt {$field} does not match the exact verified CI parity identity");
        }
    }

    $generatedAt = $receipt['generated_at'] ?? null;
    if (
        ! is_string($generatedAt)
        || preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $generatedAt) !== 1
        || strtotime($generatedAt) === false
    ) {
        fail('receipt generated_at is malformed');
    }

    fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

$command = $argv[1] ?? '';
$options = parseOptions(array_slice($argv, 2));
match ($command) {
    'create' => createReceipt($options),
    'verify' => verifyReceipt($options),
    default => fail('usage: ci_parity_receipt.php <create|verify> --option=value'),
};
