<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Illuminate\Support\Str;
use Tests\TestCase;

final class RiasecResultPageImportGateTest extends TestCase
{
    private const RELEASE_PATH = 'content_assets/riasec/result_page_v2/releases/v0_1';

    private const GATE_POLICY_PATH = 'content_assets/riasec/result_page_v2/governance/production_import_gate_v0_1';

    private const APPROVAL_PATH = 'content_assets/riasec/result_page_v2/governance/release_approval_v0_1';

    public function test_import_gate_policy_package_exists_without_runtime_enablement(): void
    {
        $manifest = $this->jsonFile(base_path(self::GATE_POLICY_PATH.'/manifest.json'));
        $policy = $this->jsonFile(base_path(self::GATE_POLICY_PATH.'/riasec_result_page_v2_production_import_gate_policy_v0_1.json'));

        $this->assertSame('riasec_result_page_v2_production_import_gate_policy', $manifest['package'] ?? null);
        $this->assertSame('import_gate_policy', $policy['mode'] ?? null);
        $this->assertSame('not_runtime', $policy['runtime_use'] ?? null);
        $this->assertFalse((bool) ($policy['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($policy['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($policy['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($policy['cms_write_performed'] ?? true));
        $this->assertTrue((bool) ($policy['fail_closed'] ?? false));
        $this->assertTrue((bool) ($policy['reject_staging_only_artifacts'] ?? false));
    }

    public function test_release_approval_evidence_requires_human_approval(): void
    {
        $approval = $this->jsonFile(base_path(self::APPROVAL_PATH.'/riasec_result_page_v2_release_approval_evidence_v0_1.json'));

        $this->assertSame('not_runtime', $approval['runtime_use'] ?? null);
        $this->assertFalse((bool) ($approval['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($approval['production_import_approved'] ?? true));
        $this->assertSame('not_approved', $approval['approval_status'] ?? null);
        $this->assertTrue((bool) ($approval['human_approval_required'] ?? false));
        $this->assertNull($approval['approver'] ?? null);
        $this->assertNull($approval['approved_at'] ?? null);
    }

    public function test_gate_rejects_current_non_production_release_snapshot(): void
    {
        $result = $this->validateImportGate(base_path(self::RELEASE_PATH));

        $this->assertFalse($result['accepted']);
        $this->assertContains('release_candidate_required', $result['reasons']);
        $this->assertContains('production_use_allowed_required', $result['reasons']);
        $this->assertContains('rendered_qa_evidence_required', $result['reasons']);
        $this->assertContains('approval_evidence_required', $result['reasons']);
        $this->assertNotContains('all_surface_pass_evidence_required', $result['reasons']);
    }

    public function test_gate_rejects_missing_snapshot_and_sha256_mismatch(): void
    {
        $dir = $this->tempReleaseDir();
        $manifest = $this->candidateManifest('missing_snapshot.json', str_repeat('0', 64));
        $this->writeJson($dir.'/manifest.json', $manifest);

        $missing = $this->validateImportGate($dir);
        $this->assertFalse($missing['accepted']);
        $this->assertContains('missing_snapshot_file', $missing['reasons']);

        $snapshot = $this->validCandidateSnapshot();
        $this->writeJson($dir.'/missing_snapshot.json', $snapshot);
        $mismatch = $this->validateImportGate($dir);

        $this->assertFalse($mismatch['accepted']);
        $this->assertContains('snapshot_sha256_mismatch', $mismatch['reasons']);
    }

    public function test_gate_rejects_staging_only_assets_and_missing_evidence(): void
    {
        $dir = $this->tempReleaseDir();
        $snapshot = $this->validCandidateSnapshot();
        $snapshot['runtime_use'] = 'staging_only';
        $snapshot['content_version_refs'] = [
            [
                'kind' => 'rendered_qa_evidence',
                'status' => 'pass',
                'sha256' => str_repeat('a', 64),
            ],
        ];
        $snapshot['snapshot_sha256'] = $this->snapshotHash($snapshot);
        $this->writeCandidatePackage($dir, $snapshot);

        $result = $this->validateImportGate($dir);

        $this->assertFalse($result['accepted']);
        $this->assertContains('staging_only_snapshot_rejected', $result['reasons']);
        $this->assertContains('all_surface_pass_evidence_required', $result['reasons']);
        $this->assertContains('approval_evidence_required', $result['reasons']);
    }

    public function test_gate_accepts_only_validated_release_candidates(): void
    {
        $dir = $this->tempReleaseDir();
        $snapshot = $this->validCandidateSnapshot();
        $snapshot['snapshot_sha256'] = $this->snapshotHash($snapshot);
        $this->writeCandidatePackage($dir, $snapshot);

        $result = $this->validateImportGate($dir);

        $this->assertTrue($result['accepted'], implode(',', $result['reasons']));
        $this->assertSame([], $result['reasons']);
        $this->assertSame([
            'release_snapshot',
            'rendered_qa_evidence',
            'all_surface_pass_evidence',
            'approval_evidence',
        ], $result['evidence']['snapshots'][0]['required_evidence'] ?? null);
    }

    public function test_sha256sums_are_reproducible(): void
    {
        foreach ([self::GATE_POLICY_PATH, self::APPROVAL_PATH, self::RELEASE_PATH] as $relativePath) {
            $entries = file(base_path($relativePath.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($entries);
            $this->assertNotSame([], $entries);

            foreach ($entries as $entry) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
                [$expectedHash, $fileName] = explode('  ', $entry, 2);
                $path = base_path($relativePath.'/'.$fileName);

                $this->assertFileExists($path);
                $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validCandidateSnapshot(): array
    {
        return [
            'schema_version' => 'riasec_result_page_v2_release_snapshot_v0_1',
            'snapshot_id' => 'test_candidate',
            'snapshot_version' => 'v_test',
            'snapshot_sha256' => '',
            'mode' => 'immutable_release_snapshot',
            'runtime_use' => 'not_runtime',
            'immutable' => true,
            'release_candidate' => true,
            'production_use_allowed' => true,
            'ready_for_production' => false,
            'production_rollout_enabled' => false,
            'cms_write_performed' => false,
            'content_version_refs' => [
                [
                    'kind' => 'rendered_qa_evidence',
                    'status' => 'pass',
                    'sha256' => str_repeat('a', 64),
                ],
                [
                    'kind' => 'all_surface_pass_evidence',
                    'status' => 'pass',
                    'sha256' => str_repeat('b', 64),
                ],
                [
                    'kind' => 'approval_evidence',
                    'status' => 'pass',
                    'sha256' => str_repeat('c', 64),
                ],
            ],
        ];
    }

    /**
     * @return array{accepted:bool,reasons:list<string>,evidence:array<string,mixed>}
     */
    private function validateImportGate(string $releasePackageDir): array
    {
        $reasons = [];
        $manifestPath = rtrim($releasePackageDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            return $this->rejected(['missing_manifest']);
        }

        $manifest = $this->jsonFile($manifestPath);
        if ($this->isStagingOnly($manifest)) {
            $reasons[] = 'staging_only_manifest_rejected';
        }
        if (($manifest['cms_write_performed'] ?? false) === true) {
            $reasons[] = 'cms_write_detected';
        }

        $snapshotEvidence = [];
        $snapshots = (array) ($manifest['snapshots'] ?? []);
        if ($snapshots === []) {
            $reasons[] = 'missing_snapshot';
        }

        foreach ($snapshots as $snapshotRef) {
            if (! is_array($snapshotRef)) {
                $reasons[] = 'invalid_snapshot_reference';

                continue;
            }

            $snapshotResult = $this->validateSnapshotRef($releasePackageDir, $snapshotRef);
            $reasons = array_merge($reasons, $snapshotResult['reasons']);
            $snapshotEvidence[] = $snapshotResult['evidence'];
        }

        $evidence = [
            'manifest' => [
                'package' => (string) ($manifest['package'] ?? ''),
                'version' => (string) ($manifest['version'] ?? ''),
                'mode' => (string) ($manifest['mode'] ?? ''),
            ],
            'snapshots' => $snapshotEvidence,
        ];

        return $reasons === []
            ? ['accepted' => true, 'reasons' => [], 'evidence' => $evidence]
            : $this->rejected($reasons, $evidence);
    }

    /**
     * @param  array<string,mixed>  $snapshotRef
     * @return array{reasons:list<string>,evidence:array<string,mixed>}
     */
    private function validateSnapshotRef(string $releasePackageDir, array $snapshotRef): array
    {
        $reasons = [];
        $fileName = (string) ($snapshotRef['file'] ?? '');
        $expectedFileHash = (string) ($snapshotRef['sha256'] ?? '');
        if ($fileName === '' || $expectedFileHash === '') {
            return [
                'reasons' => ['invalid_snapshot_reference'],
                'evidence' => ['snapshot_id' => (string) ($snapshotRef['snapshot_id'] ?? '')],
            ];
        }

        $snapshotPath = rtrim($releasePackageDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;
        if (! is_file($snapshotPath)) {
            return [
                'reasons' => ['missing_snapshot_file'],
                'evidence' => ['file' => $fileName],
            ];
        }

        $actualFileHash = (string) hash_file('sha256', $snapshotPath);
        if (! hash_equals($expectedFileHash, $actualFileHash)) {
            $reasons[] = 'snapshot_sha256_mismatch';
        }

        $snapshot = $this->jsonFile($snapshotPath);
        if (! $this->snapshotHasStableHash($snapshot)) {
            $reasons[] = 'release_snapshot_hash_mismatch';
        }
        if (($snapshot['immutable'] ?? false) !== true) {
            $reasons[] = 'release_snapshot_not_immutable';
        }
        if (($snapshot['release_candidate'] ?? false) !== true) {
            $reasons[] = 'release_candidate_required';
        }
        if (($snapshot['production_use_allowed'] ?? false) !== true) {
            $reasons[] = 'production_use_allowed_required';
        }
        if (($snapshot['cms_write_performed'] ?? false) === true) {
            $reasons[] = 'cms_write_detected';
        }
        if (($snapshot['production_rollout_enabled'] ?? false) === true) {
            $reasons[] = 'production_rollout_enabled';
        }
        if ($this->isStagingOnly($snapshot)) {
            $reasons[] = 'staging_only_snapshot_rejected';
        }

        foreach ([
            'rendered_qa_evidence',
            'all_surface_pass_evidence',
            'approval_evidence',
        ] as $kind) {
            if (! $this->hasPassingEvidence((array) ($snapshot['content_version_refs'] ?? []), $kind)) {
                $reasons[] = $kind.'_required';
            }
        }

        return [
            'reasons' => $reasons,
            'evidence' => [
                'snapshot_id' => (string) ($snapshot['snapshot_id'] ?? ''),
                'snapshot_version' => (string) ($snapshot['snapshot_version'] ?? ''),
                'file_sha256' => $actualFileHash,
                'snapshot_sha256' => (string) ($snapshot['snapshot_sha256'] ?? ''),
                'required_evidence' => [
                    'release_snapshot',
                    'rendered_qa_evidence',
                    'all_surface_pass_evidence',
                    'approval_evidence',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $evidence
     * @return array{accepted:bool,reasons:list<string>,evidence:array<string,mixed>}
     */
    private function rejected(array $reasons, array $evidence = []): array
    {
        return [
            'accepted' => false,
            'reasons' => array_values(array_unique($reasons)),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isStagingOnly(array $payload): bool
    {
        return ($payload['runtime_use'] ?? null) === 'staging_only'
            || ($payload['asset_stage'] ?? null) === 'staging_only'
            || ($payload['staging_only'] ?? false) === true;
    }

    /**
     * @param  array<int,mixed>  $refs
     */
    private function hasPassingEvidence(array $refs, string $kind): bool
    {
        foreach ($refs as $ref) {
            if (! is_array($ref) || ($ref['kind'] ?? null) !== $kind) {
                continue;
            }

            return ($ref['status'] ?? null) === 'pass'
                && (string) ($ref['sha256'] ?? '') !== '';
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function writeCandidatePackage(string $dir, array $snapshot): void
    {
        $file = 'candidate_snapshot.json';
        $snapshotPath = $dir.'/'.$file;
        $this->writeJson($snapshotPath, $snapshot);
        $this->writeJson($dir.'/manifest.json', $this->candidateManifest($file, hash_file('sha256', $snapshotPath)));
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateManifest(string $file, string $sha256): array
    {
        return [
            'package' => 'test_import_candidate',
            'version' => 'v_test',
            'mode' => 'immutable_snapshot_manifest',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'ready_for_production' => false,
            'production_rollout_enabled' => false,
            'cms_write_performed' => false,
            'snapshots' => [
                [
                    'snapshot_id' => 'test_candidate',
                    'snapshot_version' => 'v_test',
                    'file' => $file,
                    'sha256' => $sha256,
                    'immutable' => true,
                ],
            ],
        ];
    }

    private function tempReleaseDir(): string
    {
        $dir = storage_path('framework/testing/riasec-import-gate-'.Str::uuid()->toString());
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function snapshotHasStableHash(array $snapshot): bool
    {
        return hash_equals((string) ($snapshot['snapshot_sha256'] ?? ''), $this->snapshotHash($snapshot));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function snapshotHash(array $snapshot): string
    {
        unset($snapshot['snapshot_sha256']);

        return hash('sha256', $this->canonicalJson($snapshot));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        return json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }

        return $value;
    }
}
