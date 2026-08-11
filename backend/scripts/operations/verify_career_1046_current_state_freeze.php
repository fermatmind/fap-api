<?php

declare(strict_types=1);

const EXPECTED_FREEZE = [
    'schema_version' => 'career.1046.current_state_freeze.v1',
    'evidence.workflow_run_id' => 31515295236,
    'evidence.workflow_run_attempt' => 1,
    'evidence.receipt_sha256' => '59d992de3043af791343eb0d025e33377a9d2b8db6b56504adfd41c2538a57cb',
    'evidence.artifact_digest' => 'sha256:c4dc998f211bb73fb8585ba8e2b5e5fc48ced7e1e1b44f12f49794dc3a26fd8f',
    'evidence.observed_active_sha' => '42c35458ff24f00e3b22b4e0c8bc0bff98a40a5d',
    'evidence.observed_release' => 'standard-42c35458ff24-31514804713-1',
    'evidence.read_only' => true,
    'evidence.writes' => 0,
    'target_authority.frozen_manifest_sha256' => 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e',
    'target_authority.baseline_count' => 30,
    'target_authority.delta_count' => 1016,
    'target_authority.target_count' => 1046,
    'target_authority.target_locale_row_count' => 2092,
    'target_authority.baseline_set_sha256' => '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060',
    'target_authority.delta_receipt_set_sha256' => '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f',
    'target_authority.target_set_sha256' => '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18',
    'target_authority.target_locale_row_set_sha256' => 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e',
    'receipt_and_database_state.authentic_successful_receipt_slugs' => 1016,
    'receipt_and_database_state.receipt_missing' => 0,
    'receipt_and_database_state.receipt_outside_target' => 0,
    'receipt_and_database_state.database_matching_latest_index_state' => 0,
    'receipt_and_database_state.database_missing_latest_index_state' => 1016,
    'receipt_and_database_state.receipts_prove_database_state_present' => false,
    'current_public_state.directory_en_count' => 30,
    'current_public_state.directory_zh_count' => 30,
    'current_public_state.sitemap_career_detail_url_count' => 60,
    'current_materialized_authority.projection_sha256' => '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6',
    'current_materialized_authority.ledger_sha256' => '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e',
    'current_materialized_authority.slug_set_sha256' => '8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5',
    'current_materialized_authority.locale_row_set_sha256' => '607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697',
    'current_materialized_authority.slug_count' => 342,
    'current_materialized_authority.locale_row_count' => 684,
    'current_materialized_authority.target_structural_row_count' => 680,
    'current_materialized_authority.target_missing_row_count' => 1412,
    'regenerated_output.slug_count' => 1048,
    'regenerated_output.locale_row_count' => 2096,
    'regenerated_output.forbidden_outside_target_slugs.0' => 'database-administrators-and-architects',
    'regenerated_output.forbidden_outside_target_slugs.1' => 'software-developers',
    'regenerated_output.exact_target_only' => false,
    'regenerated_output.direct_activation_allowed' => false,
    'display_asset_state.raw_display_asset_row_count' => 1035,
    'display_asset_state.target_missing_raw_display_slug_count' => 12,
    'display_asset_state.target_missing_union_detail_slug_count' => 0,
    'display_asset_state.display_asset_gap_blocks_target_detail' => false,
    'authority_boundary.old_1046_artifact_is_public_authority' => false,
    'authority_boundary.production_apply_allowed' => false,
    'authority_boundary.pointer_bootstrap_allowed' => false,
    'authority_boundary.database_reconciliation_apply_allowed' => false,
    'authority_boundary.candidate_generation_allowed' => false,
    'authority_boundary.activation_allowed' => false,
    'authority_boundary.discoverability_release_allowed' => false,
    'authority_boundary.search_submission_allowed' => false,
    'write_guarantees.database_writes' => 0,
    'write_guarantees.cms_writes' => 0,
    'write_guarantees.cache_writes' => 0,
    'write_guarantees.artifact_writes' => 0,
    'write_guarantees.publication_writes' => 0,
    'write_guarantees.sitemap_writes' => 0,
    'write_guarantees.llms_writes' => 0,
    'write_guarantees.search_submissions' => 0,
    'write_guarantees.deployments' => 0,
    'write_guarantees.migrations' => 0,
];

$contractPath = $argv[1] ?? dirname(__DIR__, 2).'/docs/career/contracts/career-1046-current-state-freeze.v1.json';

try {
    $raw = file_get_contents($contractPath);
    if (! is_string($raw)) {
        throw new RuntimeException('contract_unreadable');
    }

    $contract = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($contract) || ! is_array($contract['payload'] ?? null)) {
        throw new RuntimeException('contract_shape_invalid');
    }

    if (($contract['schema_version'] ?? null) !== EXPECTED_FREEZE['schema_version']) {
        throw new RuntimeException('schema_version_mismatch');
    }

    $canonicalPayload = canonicalJson($contract['payload']);
    $actualPayloadHash = hash('sha256', $canonicalPayload);
    if (! hash_equals((string) ($contract['payload_sha256'] ?? ''), $actualPayloadHash)) {
        throw new RuntimeException('payload_sha256_mismatch');
    }

    foreach (EXPECTED_FREEZE as $path => $expected) {
        if ($path === 'schema_version') {
            continue;
        }

        $actual = valueAt($contract['payload'], $path);
        if ($actual !== $expected) {
            throw new RuntimeException('frozen_value_mismatch:'.$path);
        }
    }

    fwrite(STDOUT, json_encode([
        'schema_version' => 'career.1046.current_state_freeze.verify.v1',
        'status' => 'PASS_FROZEN_ZERO_WRITE_CONTRACT',
        'payload_sha256' => $actualPayloadHash,
        'receipt_coverage' => 1016,
        'database_latest_index_missing' => 1016,
        'writes' => 0,
        'production_apply_allowed' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema_version' => 'career.1046.current_state_freeze.verify.v1',
        'status' => 'FAIL_CLOSED',
        'reason' => $exception->getMessage(),
        'writes' => 0,
        'production_apply_allowed' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $value */
function canonicalJson(array $value): string
{
    $sort = function (mixed $item) use (&$sort): mixed {
        if (! is_array($item)) {
            return $item;
        }

        if (! array_is_list($item)) {
            ksort($item, SORT_STRING);
        }

        foreach ($item as $key => $child) {
            $item[$key] = $sort($child);
        }

        return $item;
    };

    return json_encode(
        $sort($value),
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

/** @param array<string, mixed> $root */
function valueAt(array $root, string $path): mixed
{
    $value = $root;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            throw new RuntimeException('frozen_value_missing:'.$path);
        }

        $value = $value[$segment];
    }

    return $value;
}
