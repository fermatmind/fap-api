<?php

declare(strict_types=1);

namespace App\Services\Personality\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter
{
    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @return array<string, mixed>
     */
    public function preflight(string $framework, string $packageSha256, array $descriptors): array
    {
        return $this->publicPlan($this->buildPlan($framework, $packageSha256, $descriptors, false));
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @return array<string, mixed>
     */
    public function write(
        string $framework,
        string $packageSha256,
        array $descriptors,
        int $expectedTargetCount,
        string $expectedPreflightFingerprint,
    ): array {
        return DB::transaction(function () use (
            $framework,
            $packageSha256,
            $descriptors,
            $expectedTargetCount,
            $expectedPreflightFingerprint,
        ): array {
            $plan = $this->buildPlan($framework, $packageSha256, $descriptors, true);
            if ((int) $plan['target_count'] !== $expectedTargetCount) {
                throw new RuntimeException(sprintf(
                    'Working-revision target count changed: expected=%d observed=%d.',
                    $expectedTargetCount,
                    (int) $plan['target_count'],
                ));
            }
            if (! hash_equals((string) $plan['preflight_fingerprint'], $expectedPreflightFingerprint)) {
                throw new RuntimeException('Working-revision preflight fingerprint changed before write; transaction aborted.');
            }

            $fingerprintBefore = (string) $plan['published_primary_fingerprint'];
            $written = [];
            foreach ($plan['descriptors'] as $descriptor) {
                /** @var PersonalityPublicContentAsset $asset */
                $asset = $descriptor['asset'];
                $result = $this->createOrReuseWorkingRevision(
                    $asset,
                    $framework,
                    (string) $descriptor['asset_key'],
                    (string) $descriptor['source_package'],
                    (string) $descriptor['source_hash'],
                    $packageSha256,
                    $descriptor['snapshot'],
                    (string) $descriptor['workflow_state'],
                );
                $written[] = [
                    'asset_key' => (string) $descriptor['asset_key'],
                    'asset_id' => (int) $asset->id,
                    'revision_id' => $result['revision_id'],
                    'created' => $result['created'],
                ];
            }

            $fingerprintAfter = $this->publishedPrimaryFingerprint($plan['descriptors'], true);
            if (! hash_equals($fingerprintBefore, $fingerprintAfter)) {
                throw new RuntimeException('Published primary fingerprint changed; transaction rolled back.');
            }

            $readback = $this->readback($plan['descriptors'], $written, $packageSha256, $fingerprintBefore);
            if (($readback['ok'] ?? false) !== true) {
                throw new RuntimeException('Working-revision readback failed; transaction rolled back.');
            }

            return [
                ...$this->publicPlan($plan),
                'status' => 'PASS_COLLISION_SAFE_WORKING_REVISION_WRITE',
                'writes_committed' => true,
                'revision_created_count' => count(array_filter($written, static fn (array $row): bool => $row['created'])),
                'revision_reused_count' => count(array_filter($written, static fn (array $row): bool => ! $row['created'])),
                'primary_content_overwrite_count' => 0,
                'published_pointer_update_count' => 0,
                'public_release_count' => 0,
                'indexability_change_count' => 0,
                'sitemap_change_count' => 0,
                'llms_change_count' => 0,
                'readback' => $readback,
            ];
        }, 1);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{revision_id:int,created:bool}
     */
    public function createOrReuseWorkingRevision(
        PersonalityPublicContentAsset $asset,
        string $framework,
        string $assetKey,
        string $sourcePackage,
        string $sourceHash,
        string $packageSha256,
        array $snapshot,
        string $workflowState = PersonalityPublicContentAssetRevision::STATE_DRAFT,
    ): array {
        $this->assertFrameworkMatches($asset, $framework);
        $existing = $this->packageRevision($packageSha256, $assetKey, true);
        if ($existing instanceof PersonalityPublicContentAssetRevision) {
            if ((int) $existing->asset_id !== (int) $asset->id
                || (string) $existing->source_package !== $sourcePackage
                || (string) $existing->source_hash !== $sourceHash
                || (string) $existing->workflow_state !== $workflowState
                || ! hash_equals($this->fingerprint($existing->snapshot_json), $this->fingerprint($snapshot))
                || (int) ($asset->working_revision_id ?? 0) !== (int) $existing->id) {
                throw new RuntimeException('Existing package revision does not match its target, snapshot, or working pointer: '.$assetKey.'.');
            }

            return ['revision_id' => (int) $existing->id, 'created' => false];
        }

        if ($asset->working_revision_id !== null) {
            throw new RuntimeException('Target already has a foreign isolated working revision: '.$assetKey.'.');
        }

        $next = ((int) PersonalityPublicContentAssetRevision::query()
            ->where('asset_id', (int) $asset->id)
            ->max('revision_no')) + 1;
        $revision = PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => (int) $asset->id,
            'revision_no' => $next,
            'authority_asset_key' => $assetKey,
            'source_package' => $sourcePackage,
            'source_hash' => $sourceHash,
            'authority_package_sha256' => $packageSha256,
            'workflow_state' => $workflowState,
            'snapshot_json' => $snapshot,
            'public_runtime_fingerprint_before' => $this->recordPublicRuntimeFingerprint($asset),
        ]);

        $updated = DB::table($asset->getTable())
            ->where($asset->getKeyName(), $asset->getKey())
            ->whereNull('working_revision_id')
            ->update(['working_revision_id' => (int) $revision->id]);
        if ($updated !== 1) {
            throw new RuntimeException('Working revision pointer changed concurrently; transaction aborted.');
        }
        $asset->setAttribute('working_revision_id', (int) $revision->id);

        return ['revision_id' => (int) $revision->id, 'created' => true];
    }

    public function assertTargetCanReceiveWorkingRevision(
        PersonalityPublicContentAsset $asset,
        string $framework,
        string $assetKey,
        string $packageSha256,
    ): void {
        $this->assertFrameworkMatches($asset, $framework);
        if ((string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            || ! (bool) $asset->is_public) {
            throw new RuntimeException('Target is not the expected published/public authority record: '.$assetKey.'.');
        }

        $existing = $this->packageRevision($packageSha256, $assetKey, false);
        if ($existing instanceof PersonalityPublicContentAssetRevision) {
            if ((int) $existing->asset_id !== (int) $asset->id
                || (int) ($asset->working_revision_id ?? 0) !== (int) $existing->id) {
                throw new RuntimeException('Existing package revision collides with another target or pointer: '.$assetKey.'.');
            }

            return;
        }

        if ($asset->working_revision_id !== null) {
            throw new RuntimeException('Target already has a foreign isolated working revision: '.$assetKey.'.');
        }
    }

    public function hasPackageRevision(string $packageSha256, string $assetKey): bool
    {
        return $this->packageRevision($packageSha256, $assetKey, false) instanceof PersonalityPublicContentAssetRevision;
    }

    public function recordPublicRuntimeFingerprint(PersonalityPublicContentAsset $asset): string
    {
        return $this->fingerprint($this->publicRuntimeAttributes($asset));
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @return array<string, mixed>
     */
    private function buildPlan(string $framework, string $packageSha256, array $descriptors, bool $lock): array
    {
        $this->assertSchema();
        $this->assertPackageSha($packageSha256);
        if (! in_array($framework, PersonalityPublicContentAsset::FRAMEWORKS, true)) {
            throw new RuntimeException('Unsupported personality framework: '.$framework.'.');
        }
        if ($descriptors === []) {
            throw new RuntimeException('At least one working-revision target descriptor is required.');
        }

        $planned = [];
        $seen = [];
        foreach ($descriptors as $index => $descriptor) {
            $assetKey = trim((string) ($descriptor['asset_key'] ?? ''));
            $identity = is_array($descriptor['identity'] ?? null) ? $descriptor['identity'] : [];
            $sourcePackage = trim((string) ($descriptor['source_package'] ?? ''));
            $sourceHash = strtolower(trim((string) ($descriptor['source_hash'] ?? '')));
            $workflowState = trim((string) ($descriptor['workflow_state'] ?? PersonalityPublicContentAssetRevision::STATE_DRAFT));
            if ($assetKey === '' || isset($seen[$assetKey])) {
                throw new RuntimeException('Working-revision asset key is missing or duplicated at descriptor '.$index.'.');
            }
            $seen[$assetKey] = true;
            if ($sourcePackage === '' || preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1) {
                throw new RuntimeException('Working-revision source provenance is invalid for '.$assetKey.'.');
            }
            if (! in_array($workflowState, [PersonalityPublicContentAssetRevision::STATE_DRAFT, 'pending_manual_review'], true)) {
                throw new RuntimeException('Working-revision initial workflow state is invalid for '.$assetKey.'.');
            }

            $asset = $this->findAsset($identity, $lock);
            if (! $asset instanceof PersonalityPublicContentAsset) {
                throw new RuntimeException('Personality authority target is missing: '.$assetKey.'.');
            }
            $this->assertTargetCanReceiveWorkingRevision($asset, $framework, $assetKey, $packageSha256);
            foreach (is_array($descriptor['expected_attributes'] ?? null) ? $descriptor['expected_attributes'] : [] as $path => $expected) {
                if (data_get($asset, (string) $path) !== $expected) {
                    throw new RuntimeException('Published target attribute drifted at '.$assetKey.':'.(string) $path.'.');
                }
            }

            $snapshot = is_array($descriptor['snapshot'] ?? null)
                ? $descriptor['snapshot']
                : $this->snapshotAttributes($asset);
            if (($snapshot['framework'] ?? null) !== $framework
                || ($snapshot['entity_type'] ?? null) !== $asset->entity_type
                || ($snapshot['entity_key'] ?? null) !== $asset->entity_key
                || ($snapshot['locale'] ?? null) !== $asset->locale) {
                throw new RuntimeException('Working-revision snapshot identity mismatch: '.$assetKey.'.');
            }

            $revision = $this->packageRevision($packageSha256, $assetKey, $lock);
            if ($revision instanceof PersonalityPublicContentAssetRevision
                && ((string) $revision->source_package !== $sourcePackage
                    || (string) $revision->source_hash !== $sourceHash
                    || (string) $revision->workflow_state !== $workflowState
                    || ! hash_equals($this->fingerprint($revision->snapshot_json), $this->fingerprint($snapshot)))) {
                throw new RuntimeException('Existing package revision snapshot collision: '.$assetKey.'.');
            }

            $planned[] = [
                ...$descriptor,
                'asset_key' => $assetKey,
                'source_package' => $sourcePackage,
                'source_hash' => $sourceHash,
                'workflow_state' => $workflowState,
                'snapshot' => $snapshot,
                'asset' => $asset,
                'asset_id' => (int) $asset->id,
                'action' => $revision instanceof PersonalityPublicContentAssetRevision ? 'reuse_exact_revision' : 'create_isolated_working_revision',
                'published_revision_id_before' => $asset->published_revision_id !== null ? (int) $asset->published_revision_id : null,
            ];
        }
        usort($planned, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        $publishedFingerprint = $this->publishedPrimaryFingerprint($planned, $lock);
        $fingerprintDescriptors = array_map(fn (array $descriptor): array => [
            'asset_key' => (string) $descriptor['asset_key'],
            'identity' => $descriptor['identity'],
            'source_package' => (string) $descriptor['source_package'],
            'source_hash' => (string) $descriptor['source_hash'],
            'workflow_state' => (string) $descriptor['workflow_state'],
            'snapshot_sha256' => $this->fingerprint($descriptor['snapshot']),
        ], $planned);

        return [
            'ok' => true,
            'status' => 'PASS_COLLISION_SAFE_WORKING_REVISION_PREFLIGHT',
            'mode' => 'isolated_working_revision_no_public_primary_overwrite',
            'framework' => $framework,
            'package_sha256' => $packageSha256,
            'target_count' => count($planned),
            'new_revision_count' => count(array_filter($planned, static fn (array $descriptor): bool => $descriptor['action'] === 'create_isolated_working_revision')),
            'idempotent_reuse_count' => count(array_filter($planned, static fn (array $descriptor): bool => $descriptor['action'] === 'reuse_exact_revision')),
            'published_primary_fingerprint' => $publishedFingerprint,
            'preflight_fingerprint' => $this->fingerprint([
                'framework' => $framework,
                'package_sha256' => $packageSha256,
                'published_primary_fingerprint' => $publishedFingerprint,
                'descriptors' => $fingerprintDescriptors,
            ]),
            'writes_committed' => false,
            'primary_content_overwrite_count' => 0,
            'published_pointer_update_count' => 0,
            'public_release_count' => 0,
            'indexability_change_count' => 0,
            'sitemap_change_count' => 0,
            'llms_change_count' => 0,
            'descriptors' => $planned,
        ];
    }

    /** @param array<string, mixed> $identity */
    private function findAsset(array $identity, bool $lock): ?PersonalityPublicContentAsset
    {
        foreach (['org_id', 'framework', 'entity_type', 'entity_key', 'locale'] as $field) {
            if (! array_key_exists($field, $identity)) {
                throw new RuntimeException('Working-revision identity is missing '.$field.'.');
            }
        }
        $query = PersonalityPublicContentAsset::query()->withoutGlobalScopes();
        foreach ($identity as $field => $value) {
            $query->where($field, $value);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function packageRevision(string $packageSha256, string $assetKey, bool $lock): ?PersonalityPublicContentAssetRevision
    {
        $query = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', $packageSha256)
            ->where('authority_asset_key', $assetKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @param  list<array{asset_key:string,asset_id:int,revision_id:int,created:bool}>  $written
     * @return array<string, mixed>
     */
    private function readback(array $descriptors, array $written, string $packageSha256, string $fingerprintBefore): array
    {
        $issues = [];
        foreach ($written as $row) {
            $revision = $this->packageRevision($packageSha256, $row['asset_key'], true);
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->lockForUpdate()->find($row['asset_id']);
            if (! $revision instanceof PersonalityPublicContentAssetRevision
                || ! $asset instanceof PersonalityPublicContentAsset
                || (int) $revision->asset_id !== (int) $asset->id
                || (int) ($asset->working_revision_id ?? 0) !== (int) $revision->id) {
                $issues[] = $row['asset_key'].':revision_or_pointer_mismatch';
            }
        }
        foreach ($descriptors as $descriptor) {
            /** @var PersonalityPublicContentAsset $asset */
            $asset = $descriptor['asset'];
            $asset->refresh();
            $publishedBefore = $descriptor['published_revision_id_before'];
            $publishedAfter = $asset->published_revision_id !== null ? (int) $asset->published_revision_id : null;
            if ($publishedBefore !== $publishedAfter) {
                $issues[] = $descriptor['asset_key'].':published_pointer_changed';
            }
        }

        $fingerprintAfter = $this->publishedPrimaryFingerprint($descriptors, true);
        $revisionCount = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', $packageSha256)
            ->whereIn('asset_id', array_column($written, 'asset_id'))
            ->count();

        return [
            'ok' => $issues === []
                && $revisionCount === count($descriptors)
                && hash_equals($fingerprintBefore, $fingerprintAfter),
            'target_count' => count($descriptors),
            'revision_count' => $revisionCount,
            'published_primary_fingerprint_before' => $fingerprintBefore,
            'published_primary_fingerprint_after' => $fingerprintAfter,
            'issues' => $issues,
        ];
    }

    /** @param list<array<string, mixed>> $descriptors */
    private function publishedPrimaryFingerprint(array $descriptors, bool $lock): string
    {
        $rows = [];
        foreach ($descriptors as $descriptor) {
            $asset = $this->findAsset($descriptor['identity'], $lock);
            if (! $asset instanceof PersonalityPublicContentAsset) {
                throw new RuntimeException('Personality authority target disappeared while fingerprinting.');
            }
            $rows[] = [
                'asset_key' => (string) $descriptor['asset_key'],
                'asset_id' => (int) $asset->id,
                'runtime_attributes' => $this->publicRuntimeAttributes($asset),
            ];
        }

        return $this->fingerprint($rows);
    }

    /** @return array<string, mixed> */
    private function snapshotAttributes(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach ($asset->getFillable() as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function publicRuntimeAttributes(PersonalityPublicContentAsset $asset): array
    {
        $attributes = $asset->getAttributes();
        unset($attributes['working_revision_id']);
        ksort($attributes);

        return $attributes;
    }

    private function assertFrameworkMatches(PersonalityPublicContentAsset $asset, string $framework): void
    {
        if ((string) $asset->framework !== $framework) {
            throw new RuntimeException('Personality authority target framework mismatch.');
        }
    }

    private function assertPackageSha(string $packageSha256): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
            throw new RuntimeException('Authority package SHA-256 must be an exact lowercase SHA-256.');
        }
    }

    private function assertSchema(): void
    {
        foreach (['personality_public_content_assets', 'personality_public_content_asset_revisions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required shared personality revision table is missing: '.$table.'.');
            }
        }
        foreach ([
            'personality_public_content_assets' => ['working_revision_id', 'published_revision_id'],
            'personality_public_content_asset_revisions' => [
                'authority_asset_key',
                'authority_package_sha256',
                'snapshot_json',
                'public_runtime_fingerprint_before',
            ],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException('Required shared personality revision column is missing: '.$table.'.'.$column.'.');
                }
            }
        }
    }

    /** @param array<string, mixed>|list<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['descriptors']);

        return $plan;
    }
}
