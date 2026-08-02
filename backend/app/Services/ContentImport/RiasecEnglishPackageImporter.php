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

    /** @var array<string,int> */
    private const SEGMENTS = [
        'dimension-core' => 42,
        'pair' => 15,
        'top3' => 20,
        'activity-examples' => 720,
        '140q-context-structural' => 133,
        'quality-reading-state' => 24,
        'aspirations-disagree' => 115,
        'feedback-action-lab' => 474,
        'share-pdf-history' => 7,
    ];

    public static function defaultPackageDirectory(): string
    {
        return base_path('content_assets/en-content-parity/W4-riasec');
    }

    /** @return array<string, mixed> */
    public function plan(string $packageDirectory, string $confirmedPackageSha256): array
    {
        return $this->buildPlan($packageDirectory, $confirmedPackageSha256, false);
    }

    /**
     * Returns the private authority plan consumed only by the promotion
     * adapter. Unlike plan(), it carries the exact reader payload for every
     * immutable row and must never be rendered as a command receipt.
     *
     * @return array<string,mixed>
     */
    public function authorityPlan(string $packageDirectory, string $confirmedPackageSha256): array
    {
        return $this->buildPlan($packageDirectory, $confirmedPackageSha256, true);
    }

    /** @return array<string,mixed> */
    private function buildPlan(string $packageDirectory, string $confirmedPackageSha256, bool $includeAuthorityRows): array
    {
        $confirmedPackageSha256 = strtolower(trim($confirmedPackageSha256));
        if (preg_match('/\\A[a-f0-9]{64}\\z/', $confirmedPackageSha256) !== 1) {
            $this->fail('confirmed_package_sha256_invalid', 'The confirmed W4 package SHA-256 is invalid.');
        }

        $evidence = $this->decodeJson($this->readImmutableFile($packageDirectory, 'external_package_evidence.json'));
        $payloads = $this->validateEvidence($evidence, $confirmedPackageSha256);
        $segmentPayloads = $this->validateSegmentPayloads($evidence, $packageDirectory);
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

        $plan = [
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
        if ($includeAuthorityRows) {
            $plan['authority_rows'] = $this->hydrateAuthorityRows($rows, $segmentPayloads);
        }

        return $plan;
    }

    /** @param array<string, mixed> $evidence @return array<string,string> */
    private function validateEvidence(array $evidence, string $confirmedPackageSha256): array
    {
        if (! hash_equals($confirmedPackageSha256, strtolower((string) ($evidence['producer']['package_sha256'] ?? '')))
            || ! hash_equals($confirmedPackageSha256, strtolower((string) ($evidence['control_acceptance']['package_sha256'] ?? '')))) {
            $this->fail('confirmed_package_sha256_mismatch', 'The confirmed SHA-256 is not bound by the W4 producer and CONTROL evidence.');
        }
        if (($evidence['schema_version'] ?? null) !== 'fermatmind.en_content_parity_external_package_evidence.v2'
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

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,array{sha256:string,rows:list<array<string,mixed>>,line_sha256:array<string,string>}>
     */
    private function validateSegmentPayloads(array $evidence, string $packageDirectory): array
    {
        $snapshot = (array) ($evidence['authority_snapshot'] ?? []);
        if (($snapshot['backend_package_path'] ?? null) !== 'backend/content_assets/en-content-parity/W4-riasec'
            || ($snapshot['source_repository'] ?? null) !== 'fap-web'
            || ! is_string($snapshot['source_commit_sha'] ?? null)
            || preg_match('/\A[a-f0-9]{40}\z/', (string) $snapshot['source_commit_sha']) !== 1
            || ($snapshot['logical_group_count'] ?? null) !== 14
            || ($snapshot['promotion_row_count'] ?? null) !== 1550) {
            $this->fail('authority_snapshot_contract_invalid', 'The backend W4 authority snapshot contract is invalid.');
        }
        $segments = [];
        foreach ((array) ($snapshot['segment_payloads'] ?? []) as $entry) {
            $segment = is_array($entry) ? (string) ($entry['segment'] ?? '') : '';
            $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';
            $sha = strtolower(is_array($entry) ? (string) ($entry['sha256'] ?? '') : '');
            $rowCount = is_array($entry) ? ($entry['row_count'] ?? null) : null;
            if (! isset(self::SEGMENTS[$segment]) || isset($segments[$segment])
                || $path !== 'payloads/'.$segment.'.jsonl' || $rowCount !== self::SEGMENTS[$segment]
                || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                $this->fail('authority_snapshot_segment_invalid', 'The W4 authority snapshot must declare the exact nine segment files.');
            }
            $bytes = $this->readImmutablePath($packageDirectory, $path);
            if (! hash_equals($sha, hash('sha256', $bytes))) {
                $this->fail('authority_snapshot_payload_sha256_mismatch', 'A W4 authority snapshot segment no longer matches its declared SHA-256.');
            }
            $rows = [];
            $lineSha256 = [];
            $lines = array_values(array_filter(explode("\n", trim($bytes)), static fn (string $line): bool => $line !== ''));
            if (count($lines) !== self::SEGMENTS[$segment]) {
                $this->fail('authority_snapshot_segment_row_count_invalid', 'A W4 authority snapshot segment has an unexpected row count.');
            }
            foreach ($lines as $line) {
                $row = $this->decodeJson($line);
                $assetId = trim((string) ($row['asset_id'] ?? ''));
                if ($assetId === '' || isset($rows[$assetId])) {
                    $this->fail('authority_snapshot_identity_invalid', 'Every W4 authority snapshot row must have one unique asset identity.');
                }
                $rows[$assetId] = $row;
                $lineSha256[$assetId] = hash('sha256', $line);
            }
            $segments[$segment] = ['sha256' => $sha, 'rows' => $rows, 'line_sha256' => $lineSha256];
        }
        if (array_keys($segments) !== array_keys(self::SEGMENTS)
            || array_sum(array_map(static fn (array $segment): int => count($segment['rows']), $segments)) !== 1550) {
            $this->fail('authority_snapshot_inventory_invalid', 'The W4 authority snapshot must contain exactly 1550 rows in the nine required segments.');
        }

        return $segments;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,array{sha256:string,rows:list<array<string,mixed>>,line_sha256:array<string,string>}>  $segments
     * @return list<array<string,mixed>>
     */
    private function hydrateAuthorityRows(array $rows, array $segments): array
    {
        $authorityRows = [];
        foreach ($rows as $row) {
            $segment = (string) ($row['segment'] ?? '');
            $rowId = (string) ($row['row_id'] ?? '');
            $source = $segments[$segment]['rows'][$rowId] ?? null;
            if (! is_array($source)) {
                $this->fail('authority_snapshot_row_missing', 'A frozen W4 identity is absent from its authority snapshot segment.');
            }
            $readerPayload = $this->readerPayload($source);
            $canonicalPayload = \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($readerPayload);
            if ($readerPayload === [] || preg_match('/[\x{3400}-\x{9fff}]/u', $canonicalPayload) === 1) {
                $this->fail('reader_visible_cjk_leakage', 'English W4 reader payloads must not contain CJK text.');
            }
            $authorityRows[] = $row + [
                'snapshot_segment' => $segment,
                'segment_payload_sha256' => $segments[$segment]['sha256'],
                'source_line_sha256' => $segments[$segment]['line_sha256'][$rowId],
                'reader_payload' => $readerPayload,
                'reader_payload_sha256' => hash('sha256', $canonicalPayload),
            ];
        }
        if (count($authorityRows) !== 1550) {
            $this->fail('authority_snapshot_inventory_invalid', 'The authority plan must materialize all 1550 frozen W4 rows.');
        }

        return $authorityRows;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function readerPayload(array $source): array
    {
        foreach (['asset_id', 'translation_group', 'source_identity', 'locale', 'source_locale', 'status', 'review_status', 'runtime_ready', 'translation_method', 'permissions'] as $key) {
            unset($source[$key]);
        }

        return $source;
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
                'asset_kind' => (string) ($row['asset_kind'] ?? ''), 'segment' => (string) ($row['segment'] ?? ''), 'locale' => 'en',
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
        if ($filename !== basename($filename)) {
            $this->fail('unsafe_package_path', 'Only safe package-root filenames are accepted.');
        }

        return $this->readImmutablePath($directory, $filename);
    }

    private function readImmutablePath(string $directory, string $relativePath): string
    {
        $parts = explode('/', $relativePath);
        if ($relativePath === '' || str_starts_with($relativePath, '/') || array_filter($parts, static fn (string $part): bool => $part === '' || $part === '.' || $part === '..') !== []) {
            $this->fail('unsafe_package_path', 'Only safe relative package paths are accepted.');
        }
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            $this->fail('package_directory_invalid', 'The frozen package directory is unavailable.');
        }
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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
