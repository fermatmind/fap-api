<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use DomainException;

/**
 * Validates a frozen W4 package without treating the currently checked-in SHA
 * as a permanent authorization token. The exact SHA is supplied by the trusted
 * promotion context and must reproduce the external evidence payload chain.
 */
final class RiasecEnglishPackageImporter
{
    // Kept for the legacy dry-run command and its fixture tests. Promotion code
    // deliberately does not use these values as a future-package allowlist.
    public const PACKAGE_SHA256 = 'f3f2463fadd827e586d39d42ecd9e6418b7cb7f36a0697eb06dcead8292f54eb';

    public const W9_REPORT_SHA256 = 'f2c0f83871ecae1ed76bd742f0ddcf20de71f7980c012bc5cd1affe72dd46882';

    /** @var array<string, int> */
    private const GROUPS = [
        'W4-G01' => 24, 'W4-G02' => 18, 'W4-G03' => 15, 'W4-G04' => 20,
        'W4-G05' => 720, 'W4-G06' => 126, 'W4-G07' => 7, 'W4-G08' => 24,
        'W4-G09' => 70, 'W4-G10' => 45, 'W4-G11' => 474, 'W4-G12' => 3,
        'W4-G13' => 2, 'W4-G14' => 2,
    ];

    public static function defaultPackageDirectory(): string
    {
        return base_path('content_assets/en-content-parity/W4-riasec');
    }

    /** @return array<string, mixed> */
    public function plan(string $packageDirectory, string $confirmedPackageSha256): array
    {
        $confirmedPackageSha256 = strtolower(trim($confirmedPackageSha256));
        if (preg_match('/\\A[a-f0-9]{64}\\z/', $confirmedPackageSha256) !== 1) {
            $this->fail('confirmed_package_sha256_invalid', 'The confirmed W4 package SHA-256 is invalid.');
        }

        $evidence = $this->decodeJson($this->readImmutableFile($packageDirectory, 'external_package_evidence.json'));
        $payloads = $this->validateEvidence($evidence, $confirmedPackageSha256);
        $documents = [];
        $chain = '';
        foreach ($payloads as $file => $expectedSha) {
            $bytes = $this->readImmutableFile($packageDirectory, $file);
            if (! hash_equals($expectedSha, hash('sha256', $bytes))) {
                $this->fail('payload_sha256_mismatch', 'An immutable W4 payload no longer matches its declared SHA-256.');
            }
            $documents[$file] = in_array($file, ['assets.jsonl', 'handoff.md'], true) ? $bytes : $this->decodeJson($bytes);
            $chain .= ($chain === '' ? '' : "\n").$file.':'.$expectedSha;
        }
        if (! hash_equals($confirmedPackageSha256, hash('sha256', $chain))) {
            $this->fail('computed_package_sha256_mismatch', 'The immutable payload chain does not reproduce the confirmed W4 package SHA-256.');
        }

        $this->validateTopLevelDocuments($documents, (string) ($evidence['package_id'] ?? ''));
        $rows = $this->validateAndRedactRows(
            (array) $documents['translation_map.json'],
            (string) $documents['assets.jsonl'],
            (array) $documents['source_ledger.json'],
        );

        return [
            'artifact' => 'EN-PARITY-W4-RIASEC-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.riasec_import_dry_run_receipt.v2',
            'status' => 'pass', 'ok' => true, 'mode' => 'dry_run', 'dry_run_only' => true,
            'write_supported_in_this_pr' => false, 'writes_committed' => false,
            'database_write_attempted' => false, 'cms_write_attempted' => false,
            'runtime_write_attempted' => false, 'activation_attempted' => false,
            'publish_attempted' => false, 'indexability_attempted' => false,
            'private_payload_read_attempted' => false, 'attempt_or_report_accessed' => false,
            'package' => [
                'package_sha256' => $confirmedPackageSha256,
                'package_id' => (string) $evidence['package_id'],
                'logical_group_count' => 14, 'atomic_row_count' => 1550,
                'normalized_unordered_pair_count' => 15,
                'safe_surface_counts' => ['share' => 3, 'pdf' => 2, 'history' => 2],
                'reader_copy_in_receipt' => false, 'local_path_in_receipt' => false,
            ],
            'control' => [
                'status' => 'qa_pass',
                'w9_report_sha256' => (string) data_get($evidence, 'control_acceptance.qa_report_sha256'),
                'w9_aggregate_sha256' => (string) data_get($evidence, 'control_acceptance.w9_aggregate_sha256'),
                'qa_pass_authorized_by_w9_report' => false,
            ],
            'target_contract' => [
                'authority' => 'backend RIASEC result content authority', 'locale' => 'en',
                'target_state' => 'inactive_exact_english_release', 'existing_runtime_modified' => false,
            ],
            'row_count' => count($rows), 'rows' => $rows, 'errors' => [], 'warnings' => [],
        ];
    }

    /** @param array<string, mixed> $evidence @return array<string,string> */
    private function validateEvidence(array $evidence, string $confirmedPackageSha256): array
    {
        if (! hash_equals($confirmedPackageSha256, strtolower((string) ($evidence['producer']['package_sha256'] ?? '')))
            || ! hash_equals($confirmedPackageSha256, strtolower((string) ($evidence['control_acceptance']['package_sha256'] ?? '')))) {
            $this->fail('confirmed_package_sha256_mismatch', 'The confirmed SHA-256 is not bound by the W4 producer and CONTROL evidence.');
        }
        if (($evidence['schema_version'] ?? null) !== 'fermatmind.en_content_parity_external_package_evidence.v1'
            || ($evidence['lane_id'] ?? null) !== 'W4'
            || ! is_string($evidence['package_id'] ?? null)
            || ($evidence['producer']['source_repository'] ?? null) !== 'fap-web'
            || ($evidence['control_acceptance']['lane_id'] ?? null) !== 'W4'
            || ($evidence['control_acceptance']['status'] ?? null) !== 'qa_pass'
            || preg_match('/\\A[a-f0-9]{64}\\z/', (string) ($evidence['control_acceptance']['qa_report_sha256'] ?? '')) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/', (string) ($evidence['control_acceptance']['w9_aggregate_sha256'] ?? '')) !== 1) {
            $this->fail('external_evidence_mismatch', 'The external producer, CONTROL, or W9 evidence is not bound to the confirmed W4 package.');
        }
        foreach ((array) ($evidence['permissions'] ?? []) as $permission) {
            if ($permission !== false) {
                $this->fail('permission_open', 'Every external evidence permission must remain false.');
            }
        }
        $payloads = [];
        foreach ((array) ($evidence['immutable_payloads'] ?? []) as $entry) {
            $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';
            $sha = strtolower(is_array($entry) ? (string) ($entry['sha256'] ?? '') : '');
            if ($path === '' || basename($path) !== $path || isset($payloads[$path]) || preg_match('/\\A[a-f0-9]{64}\\z/', $sha) !== 1) {
                $this->fail('external_payload_chain_invalid', 'The external evidence payload chain is invalid.');
            }
            $payloads[$path] = $sha;
        }
        $required = ['scope_manifest.json', 'assets.jsonl', 'translation_map.json', 'source_ledger.json', 'claim_boundary_report.json', 'editorial_review.json', 'dry_run_readiness.json', 'handoff.md'];
        if (array_keys($payloads) !== $required) {
            $this->fail('external_payload_chain_invalid', 'The external evidence must declare the exact eight immutable W4 payloads.');
        }

        return $payloads;
    }

    /** @param array<string, mixed> $documents */
    private function validateTopLevelDocuments(array $documents, string $packageId): void
    {
        foreach (['scope_manifest.json', 'translation_map.json', 'source_ledger.json', 'claim_boundary_report.json', 'editorial_review.json', 'dry_run_readiness.json'] as $file) {
            $document = (array) $documents[$file];
            if (($document['lane_id'] ?? null) !== 'W4' || ($document['package_id'] ?? null) !== $packageId) {
                $this->fail('package_identity_mismatch', 'Every immutable W4 document must retain its lane and package identity.');
            }
            foreach ((array) ($document['permissions'] ?? []) as $permission) {
                if ($permission !== false) {
                    $this->fail('package_permission_open', 'Every producer package permission must remain false.');
                }
            }
        }
        if (($documents['scope_manifest.json']['status'] ?? null) !== 'package_frozen'
            || count($documents['translation_map.json']['logical_groups'] ?? []) !== 14
            || count($documents['source_ledger.json']['rows'] ?? []) !== 14
            || ($documents['claim_boundary_report.json']['verdict'] ?? null) !== 'PASS'
            || ($documents['editorial_review.json']['verdict'] ?? null) !== 'PASS') {
            $this->fail('package_contract_mismatch', 'The frozen W4 package must retain its 14-group producer contract.');
        }
    }

    /** @param array<string,mixed> $translation @param array<string,mixed> $sourceLedger @return list<array<string,mixed>> */
    private function validateAndRedactRows(array $translation, string $assetsJsonl, array $sourceLedger): array
    {
        $groups = $translation['logical_groups'] ?? null;
        $atomicRows = $translation['atomic_rows'] ?? null;
        if (! is_array($groups) || ! is_array($atomicRows) || count($groups) !== 14 || count($atomicRows) !== 1550) {
            $this->fail('inventory_count_mismatch', 'The W4 inventory must contain exactly 14 groups and 1550 atomic rows.');
        }
        $supportedForms = [];
        foreach ((array) ($sourceLedger['rows'] ?? []) as $ledgerRow) {
            $group = is_array($ledgerRow) ? (string) ($ledgerRow['group_id'] ?? '') : '';
            $forms = is_array($ledgerRow) ? array_values(array_filter(array_map('strval', (array) ($ledgerRow['supported_forms'] ?? [])))) : [];
            sort($forms, SORT_STRING);
            if (! isset(self::GROUPS[$group]) || isset($supportedForms[$group]) || $forms === [] || array_diff($forms, ['riasec_60', 'riasec_140']) !== []) {
                $this->fail('form_scope_contract_invalid', 'Each W4 group must declare an exact supported RIASEC form scope.');
            }
            if (in_array($group, ['W4-G06', 'W4-G07'], true) && $forms !== ['riasec_140']) {
                $this->fail('form_scope_contract_invalid', '140Q-only content cannot be declared available to 60Q.');
            }
            $supportedForms[$group] = $forms;
        }
        if (count($supportedForms) !== 14) {
            $this->fail('form_scope_contract_invalid', 'The W4 source ledger must cover every group.');
        }
        $seenGroups = [];
        foreach ($groups as $group) {
            $id = is_array($group) ? (string) ($group['group_id'] ?? '') : '';
            if (! isset(self::GROUPS[$id]) || isset($seenGroups[$id]) || ($group['expected_expanded_rows'] ?? null) !== self::GROUPS[$id]) {
                $this->fail('logical_group_mismatch', 'The W4 logical group contract drifted.');
            }
            $seenGroups[$id] = true;
        }
        $assetLines = array_values(array_filter(explode("\n", trim($assetsJsonl)), static fn (string $line): bool => $line !== ''));
        if (count($assetLines) !== 1) {
            $this->fail('assets_contract_mismatch', 'The W4 asset manifest must retain its single logical master asset.');
        }
        $asset = $this->decodeJson($assetLines[0]);
        if (($asset['asset_id'] ?? null) !== 'ENPARITY-W4-RIASEC-DEEP-ASSETS' || ($asset['expected_en_count'] ?? null) !== 14) {
            $this->fail('assets_contract_mismatch', 'The W4 master asset contract drifted.');
        }
        $seenRows = [];
        $counts = array_fill_keys(array_keys(self::GROUPS), 0);
        $pairs = [];
        $surfaceCounts = ['share' => 0, 'pdf' => 0, 'history' => 0];
        $plans = [];
        foreach ($atomicRows as $position => $row) {
            if (! is_array($row)) {
                $this->fail('atomic_row_invalid', 'Every W4 atomic row must be an object.');
            }
            $id = trim((string) ($row['row_id'] ?? ''));
            $stable = trim((string) ($row['stable_asset_identity'] ?? ''));
            $group = $this->rowGroupId($row);
            if ($id === '' || $stable === '' || isset($seenRows[$id]) || ! isset($counts[$group])
                || ($row['locale'] ?? null) !== 'en' || ($row['runtime_ready'] ?? null) !== false) {
                $this->fail('atomic_identity_mismatch', 'Atomic row identities, locale, and non-runtime state must remain exact.');
            }
            $seenRows[$id] = true;
            $counts[$group]++;
            if ($group === 'W4-G03') {
                if (isset($pairs[$stable])) {
                    $this->fail('pair_identity_duplicate', 'The normalized W4 unordered pairs must be unique.');
                }
                $pairs[$stable] = true;
            }
            if ($group === 'W4-G12') {
                $surfaceCounts['share']++;
            }
            if ($group === 'W4-G13') {
                $surfaceCounts['pdf']++;
            }
            if ($group === 'W4-G14') {
                $surfaceCounts['history']++;
            }
            $plans[] = [
                'position' => $position + 1, 'row_id' => $id, 'stable_asset_identity' => $stable,
                'translation_group' => (string) ($row['translation_group'] ?? ''), 'group_id' => $group,
                'asset_kind' => (string) ($row['asset_kind'] ?? ''), 'locale' => 'en',
                'supported_form_codes' => $supportedForms[$group],
                'action' => 'would_stage_inactive_english_candidate', 'write_executed' => false,
                'reader_copy_in_plan' => false,
            ];
        }
        if ($counts !== self::GROUPS || count($pairs) !== 15 || $surfaceCounts !== ['share' => 3, 'pdf' => 2, 'history' => 2]) {
            $this->fail('inventory_reconciliation_mismatch', 'The 14 / 1550, 15-pair, or 3 / 2 / 2 W4 reconciliation drifted.');
        }

        return $plans;
    }

    /** @param array<string,mixed> $row */
    private function rowGroupId(array $row): string
    {
        $declared = $row['group_id'] ?? null;
        if (is_string($declared) && isset(self::GROUPS[$declared])) {
            return $declared;
        }
        if (preg_match('/(?:W4-)?G(0[3-9]|1[0-4])(?:-|$)/', (string) ($row['row_id'] ?? ''), $matches) === 1) {
            return 'W4-G'.$matches[1];
        }

        return '';
    }

    private function readImmutableFile(string $directory, string $filename): string
    {
        if ($filename !== basename($filename) || str_contains($filename, '..')) {
            $this->fail('unsafe_package_path', 'Only safe package-root filenames are accepted.');
        }
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            $this->fail('package_directory_invalid', 'The frozen package directory is unavailable.');
        }
        $path = $root.DIRECTORY_SEPARATOR.$filename;
        $stat = @lstat($path);
        if ($stat === false || ! is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1) {
            $this->fail('unsafe_package_file', 'Immutable package files must be regular single-link files.');
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            $this->fail('package_file_unreadable', 'A required immutable package file cannot be read.');
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('invalid_json', 'A required immutable JSON document is invalid.');
        }
        if (! is_array($value)) {
            $this->fail('invalid_json_shape', 'A required immutable JSON document must be an object.');
        }

        return $value;
    }

    private function fail(string $code, string $message): never
    {
        throw new DomainException($code.': '.$message);
    }
}
