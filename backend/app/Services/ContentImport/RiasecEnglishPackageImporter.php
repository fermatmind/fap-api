<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use DomainException;

final class RiasecEnglishPackageImporter
{
    public const PACKAGE_SHA256 = 'f3f2463fadd827e586d39d42ecd9e6418b7cb7f36a0697eb06dcead8292f54eb';

    public const W9_REPORT_SHA256 = 'f2c0f83871ecae1ed76bd742f0ddcf20de71f7980c012bc5cd1affe72dd46882';

    private const W9_AGGREGATE_SHA256 = 'b1472c21370893c905f12492a7b14995e25a85c46da70afadf269526000c2ff7';

    private const PACKAGE_ID = 'EN-PARITY-W4-RIASEC-PACKAGE-REFREEZE-01';

    /** @var array<string, string> */
    private const PAYLOADS = [
        'scope_manifest.json' => '6cd7f2332c1f4ce46c898ec769d017b518c974f0a2e7155461e418587b2d96b3',
        'assets.jsonl' => 'eb0a5d702e099164622f4b5e3056a3269d7f79efda3cf0d0f4d4974bab2953ca',
        'translation_map.json' => 'a57d6781ce0d6751668181b10a66ff111abba1f78da310852273beafc60e0294',
        'source_ledger.json' => 'f6c69257f9ab125c1b4885aa47aca326a76b9ec3a9c1ba603e5b7e0b191d898b',
        'claim_boundary_report.json' => '715ffd948eb71759f10f6f4691b3a27820982cfd00b052f57e4df491e348e726',
        'editorial_review.json' => 'b8e39eebd501e727fd08babbd9367548ba5417a0820c134946da9eef7409c8a5',
        'dry_run_readiness.json' => 'a68b36968035e08900be4d7adfe1647641b08f91e347b19c734b6bb106a38ccf',
        'handoff.md' => 'faf3d0195f7672befdba1009ac7acd498acf949af034bb9738359c5c709c8577',
    ];

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
        if (strtolower(trim($confirmedPackageSha256)) !== self::PACKAGE_SHA256) {
            $this->fail('confirmed_package_sha256_mismatch', 'The confirmed SHA-256 is not the CONTROL-accepted W4 package.');
        }

        $documents = [];
        $chain = '';
        foreach (self::PAYLOADS as $file => $expectedSha) {
            $bytes = $this->readImmutableFile($packageDirectory, $file);
            if (! hash_equals($expectedSha, hash('sha256', $bytes))) {
                $this->fail('payload_sha256_mismatch', 'An immutable W4 payload no longer matches its frozen SHA-256.');
            }
            $documents[$file] = in_array($file, ['assets.jsonl', 'handoff.md'], true) ? $bytes : $this->decodeJson($bytes);
            $chain .= ($chain === '' ? '' : "\n").$file.':'.$expectedSha;
        }
        if (! hash_equals(self::PACKAGE_SHA256, hash('sha256', $chain))) {
            $this->fail('computed_package_sha256_mismatch', 'The immutable payload chain does not reproduce the accepted W4 package SHA-256.');
        }

        $evidence = $this->decodeJson($this->readImmutableFile($packageDirectory, 'external_package_evidence.json'));
        $this->validateEvidence($evidence);
        $this->validateTopLevelDocuments($documents);
        $rows = $this->validateAndRedactRows($documents['translation_map.json'], (string) $documents['assets.jsonl']);

        return [
            'artifact' => 'EN-PARITY-W4-RIASEC-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.riasec_import_dry_run_receipt.v1',
            'status' => 'pass',
            'ok' => true,
            'mode' => 'dry_run',
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'database_write_attempted' => false,
            'cms_write_attempted' => false,
            'runtime_write_attempted' => false,
            'activation_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'private_payload_read_attempted' => false,
            'attempt_or_report_accessed' => false,
            'package' => [
                'package_sha256' => self::PACKAGE_SHA256,
                'logical_group_count' => 14,
                'atomic_row_count' => 1550,
                'normalized_unordered_pair_count' => 15,
                'safe_surface_counts' => ['share' => 3, 'pdf' => 2, 'history' => 2],
                'reader_copy_in_receipt' => false,
                'local_path_in_receipt' => false,
            ],
            'control' => [
                'status' => 'qa_pass',
                'w9_report_sha256' => self::W9_REPORT_SHA256,
                'w9_aggregate_sha256' => self::W9_AGGREGATE_SHA256,
                'qa_pass_authorized_by_w9_report' => false,
            ],
            'target_contract' => [
                'authority' => 'backend RIASEC result content authority',
                'locale' => 'en',
                'target_state' => 'future_inactive_draft_only',
                'existing_runtime_modified' => false,
            ],
            'row_count' => count($rows),
            'rows' => $rows,
            'errors' => [],
            'warnings' => [],
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidence(array $evidence): void
    {
        if (($evidence['lane_id'] ?? null) !== 'W4'
            || ($evidence['package_id'] ?? null) !== self::PACKAGE_ID
            || ($evidence['producer']['source_repository'] ?? null) !== 'fap-web'
            || ($evidence['producer']['source_commit_sha'] ?? null) !== 'd16333b2dc234eb2e1d08baa237346ca7d271274'
            || ($evidence['producer']['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($evidence['control_acceptance']['status'] ?? null) !== 'qa_pass'
            || ($evidence['control_acceptance']['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($evidence['control_acceptance']['qa_report_sha256'] ?? null) !== self::W9_REPORT_SHA256
            || ($evidence['control_acceptance']['w9_aggregate_sha256'] ?? null) !== self::W9_AGGREGATE_SHA256) {
            $this->fail('external_evidence_mismatch', 'The external producer, CONTROL, or W9 evidence is not the exact accepted W4 chain.');
        }
        $payloads = $evidence['immutable_payloads'] ?? null;
        if (! is_array($payloads) || count($payloads) !== count(self::PAYLOADS)) {
            $this->fail('external_payload_chain_invalid', 'The external evidence must declare the exact eight immutable payloads.');
        }
        foreach ($payloads as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)
                || ! hash_equals(self::PAYLOADS[$entry['path']] ?? '', (string) ($entry['sha256'] ?? ''))) {
                $this->fail('external_payload_chain_invalid', 'The external evidence payload chain does not bind the exact frozen snapshot.');
            }
        }
        foreach ((array) ($evidence['permissions'] ?? []) as $permission) {
            if ($permission !== false) {
                $this->fail('permission_open', 'Every external evidence permission must remain false.');
            }
        }
    }

    /** @param array<string, mixed> $documents */
    private function validateTopLevelDocuments(array $documents): void
    {
        foreach (['scope_manifest.json', 'translation_map.json', 'source_ledger.json', 'claim_boundary_report.json', 'editorial_review.json', 'dry_run_readiness.json'] as $file) {
            $document = $documents[$file];
            if (($document['lane_id'] ?? null) !== 'W4' || ($document['package_id'] ?? null) !== self::PACKAGE_ID) {
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

    /**
     * @param  array<string, mixed>  $translation
     * @return list<array<string, mixed>>
     */
    private function validateAndRedactRows(array $translation, string $assetsJsonl): array
    {
        $groups = $translation['logical_groups'] ?? null;
        $atomicRows = $translation['atomic_rows'] ?? null;
        if (! is_array($groups) || ! is_array($atomicRows) || count($groups) !== 14 || count($atomicRows) !== 1550) {
            $this->fail('inventory_count_mismatch', 'The W4 inventory must contain exactly 14 groups and 1550 atomic rows.');
        }

        $seenGroups = [];
        foreach ($groups as $group) {
            $id = is_array($group) ? (string) ($group['group_id'] ?? '') : '';
            if (! isset(self::GROUPS[$id]) || isset($seenGroups[$id])
                || ($group['expected_expanded_rows'] ?? null) !== self::GROUPS[$id]) {
                $this->fail('logical_group_mismatch', 'The W4 logical group contract drifted.');
            }
            $seenGroups[$id] = true;
        }

        $assetLines = array_values(array_filter(explode("\n", trim($assetsJsonl)), static fn (string $line): bool => $line !== ''));
        if (count($assetLines) !== 1) {
            $this->fail('assets_contract_mismatch', 'The W4 asset manifest must retain its single logical master asset.');
        }
        $asset = $this->decodeJson($assetLines[0]);
        if (($asset['asset_id'] ?? null) !== 'ENPARITY-W4-RIASEC-DEEP-ASSETS'
            || ($asset['expected_en_count'] ?? null) !== 14 || ($asset['current_en_count'] ?? null) !== 0
            || ($asset['remaining_en_count'] ?? null) !== 14) {
            $this->fail('assets_contract_mismatch', 'The W4 master asset must retain the 14 / 0 / 14 contract.');
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
                $pair = trim((string) ($row['stable_asset_identity'] ?? ''));
                if (isset($pairs[$pair])) {
                    $this->fail('pair_identity_duplicate', 'The normalized W4 unordered pairs must be unique.');
                }
                $pairs[$pair] = true;
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
                'position' => $position + 1,
                'row_id' => $id,
                'stable_asset_identity' => $stable,
                'translation_group' => (string) ($row['translation_group'] ?? ''),
                'group_id' => $group,
                'asset_kind' => (string) ($row['asset_kind'] ?? ''),
                'action' => 'would_stage_inactive_english_candidate',
                'write_executed' => false,
                'reader_copy_in_plan' => false,
            ];
        }
        if ($counts !== self::GROUPS || count($pairs) !== 15 || $surfaceCounts !== ['share' => 3, 'pdf' => 2, 'history' => 2]) {
            $this->fail('inventory_reconciliation_mismatch', 'The 14 / 1550, 15-pair, or 3 / 2 / 2 W4 reconciliation drifted.');
        }

        return $plans;
    }

    /** @param array<string, mixed> $row */
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

    /** @return array<string, mixed> */
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
