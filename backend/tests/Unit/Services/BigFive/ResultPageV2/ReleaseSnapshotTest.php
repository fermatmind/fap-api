<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class ReleaseSnapshotTest extends TestCase
{
    private const RELEASE_PATH = 'content_assets/big5/result_page_v2/releases/v0_1';

    private const FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/release_snapshots/immutable_snapshot_fixture_v0_1.json';

    public function test_fixture_snapshot_is_immutable_reproducible_versioned_and_traceable(): void
    {
        $snapshot = $this->jsonFile(self::FIXTURE_PATH);

        $this->assertSame('fixture_big5_result_page_v2_rc_0_1', $snapshot['snapshot_id'] ?? null);
        $this->assertSame('v0_1', $snapshot['snapshot_version'] ?? null);
        $this->assertTrue(($snapshot['immutable'] ?? false) === true);
        $this->assertTrue($this->snapshotHasStableHash($snapshot));
        $this->assertFalse($this->isProductionEnabled($snapshot));
        $this->assertGreaterThanOrEqual(2, count((array) ($snapshot['content_version_refs'] ?? [])));

        $refKinds = array_column((array) ($snapshot['content_version_refs'] ?? []), 'kind');
        $this->assertContains('production_governance_policy', $refKinds);
        $this->assertContains('release_snapshot_package', $refKinds);
    }

    public function test_content_asset_snapshot_manifest_is_stable_and_not_runtime(): void
    {
        $manifest = $this->jsonFile(self::RELEASE_PATH.'/manifest.json');
        $snapshot = $this->jsonFile(self::RELEASE_PATH.'/big5_v2_release_snapshot_rc_0_1.json');

        $this->assertFalse($this->isProductionEnabled($manifest));
        $this->assertFalse($this->isProductionEnabled($snapshot));
        $this->assertTrue(($snapshot['immutable'] ?? false) === true);
        $this->assertTrue($this->snapshotHasStableHash($snapshot));
        $this->assertTrue($this->manifestHasValidSnapshotHashes($manifest));

        $snapshots = (array) ($manifest['snapshots'] ?? []);
        $this->assertCount(1, $snapshots);
        $this->assertSame($snapshot['snapshot_id'] ?? null, $snapshots[0]['snapshot_id'] ?? null);
        $this->assertSame($snapshot['snapshot_version'] ?? null, $snapshots[0]['snapshot_version'] ?? null);
    }

    public function test_snapshot_hash_rejects_mutable_payload_changes(): void
    {
        $snapshot = $this->jsonFile(self::FIXTURE_PATH);
        $mutated = $snapshot;
        $mutated['content_version_refs'][] = [
            'kind' => 'unexpected_mutation',
            'path' => 'content_assets/big5/result_page_v2/untracked.json',
            'version' => 'mutable',
        ];

        $this->assertFalse($this->snapshotHasStableHash($mutated));
        $this->assertNotSame($this->computedSnapshotHash($snapshot), $this->computedSnapshotHash($mutated));
    }

    public function test_release_snapshot_package_has_no_mutable_overwrite_path(): void
    {
        $manifest = $this->jsonFile(self::RELEASE_PATH.'/manifest.json');
        $snapshot = $this->jsonFile(self::RELEASE_PATH.'/big5_v2_release_snapshot_rc_0_1.json');

        $this->assertSame('big5_v2_release_snapshots', $manifest['package'] ?? null);
        $this->assertSame('immutable_release_snapshot', $snapshot['mode'] ?? null);
        $this->assertFalse((bool) ($snapshot['immutability_contract']['overwrite_allowed'] ?? true));
        $this->assertTrue((bool) ($snapshot['immutability_contract']['append_only_versions'] ?? false));
        $this->assertArrayNotHasKey('overwrite_path', $manifest);
        $this->assertArrayNotHasKey('mutable_write_path', $snapshot);
        $this->assertArrayNotHasKey('production_enable_path', $snapshot);
    }

    public function test_rollback_is_supported_by_snapshot_revert_without_runtime_enablement(): void
    {
        $snapshot = $this->jsonFile(self::RELEASE_PATH.'/big5_v2_release_snapshot_rc_0_1.json');
        $rollback = (array) ($snapshot['rollback'] ?? []);

        $this->assertSame('revert_to_previous_immutable_snapshot', $rollback['strategy'] ?? null);
        $this->assertTrue((bool) ($rollback['release_disable_supported'] ?? false));
        $this->assertTrue((bool) ($rollback['emergency_disable_supported'] ?? false));
        $this->assertFalse($this->isProductionEnabled($snapshot));
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::RELEASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::RELEASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isProductionEnabled(array $payload): bool
    {
        return ($payload['production_use_allowed'] ?? false) === true
            || ($payload['ready_for_production'] ?? false) === true
            || ($payload['production_rollout_enabled'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function snapshotHasStableHash(array $snapshot): bool
    {
        return hash_equals((string) ($snapshot['snapshot_sha256'] ?? ''), $this->computedSnapshotHash($snapshot));
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function computedSnapshotHash(array $snapshot): string
    {
        unset($snapshot['snapshot_sha256']);

        return hash('sha256', $this->canonicalJson($snapshot));
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function manifestHasValidSnapshotHashes(array $manifest): bool
    {
        foreach ((array) ($manifest['snapshots'] ?? []) as $snapshot) {
            $fileName = (string) ($snapshot['file'] ?? '');
            $expectedHash = (string) ($snapshot['sha256'] ?? '');
            if ($fileName === '' || $expectedHash === '') {
                return false;
            }

            $path = base_path(self::RELEASE_PATH.'/'.$fileName);
            if (! is_file($path) || hash_file('sha256', $path) !== $expectedHash) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        $normalized = $this->sortRecursive($payload);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
