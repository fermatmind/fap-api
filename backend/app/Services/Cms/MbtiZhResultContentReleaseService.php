<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\Models\PersonalityProfileVariantRevision;
use App\PersonalityCms\DesktopClone\MbtiZhResultContentPackage;
use App\Support\Idempotency\IdempotencyKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiZhResultContentReleaseService
{
    public function __construct(private readonly MbtiZhResultContentPackage $package) {}

    /** @return array<string, mixed> */
    public function dryRun(): array
    {
        $state = $this->resolveState(false);

        return $this->publicPlan($state);
    }

    /** @return array<string, mixed> */
    public function writeDraft(string $expectedPackageHash, string $expectedPreStateHash, int $adminUserId): array
    {
        $this->assertSha($expectedPackageHash, 'package_hash');
        $this->assertSha($expectedPreStateHash, 'pre_state_hash');
        if ($adminUserId < 1) {
            throw new RuntimeException('A positive --admin-user-id is required.');
        }

        return DB::transaction(function () use ($expectedPackageHash, $expectedPreStateHash, $adminUserId): array {
            $state = $this->resolveState(true);
            $this->assertPlanIdentity($state, $expectedPackageHash, $expectedPreStateHash);

            $candidateRevisions = [];
            $rollbackRevisions = [];
            foreach ($state['targets'] as $target) {
                $variant = $target['variant'];
                $record = $target['record'];
                $row = $target['row'];
                $rollback = $this->findRevision($variant, 'rollback_pre_state', $expectedPreStateHash, null)
                    ?? $this->createRevision($variant, [
                        'role' => 'rollback_pre_state',
                        'pre_state_hash' => $expectedPreStateHash,
                        'full_code' => $row['full_code'],
                        'template_key' => $record->template_key,
                        'schema_version' => $record->schema_version,
                        'status' => $record->status,
                        'content_json' => $record->content_json,
                        'asset_slots_json' => $record->asset_slots_json,
                        'meta_json' => $record->meta_json,
                        'published_at' => $record->published_at?->toISOString(),
                    ], 'MBTI zh result rollback snapshot', $adminUserId);
                $candidate = $this->findRevision($variant, 'candidate', $expectedPreStateHash, $expectedPackageHash)
                    ?? $this->createRevision($variant, [
                        'role' => 'candidate',
                        'pre_state_hash' => $expectedPreStateHash,
                        'package_id' => $state['package']['package_id'],
                        'package_hash' => $expectedPackageHash,
                        'source_hash' => $row['source_hash'],
                        'full_code' => $row['full_code'],
                        'template_key' => $row['template_key'],
                        'schema_version' => $row['schema_version'],
                        'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
                        'content_json' => $row['content_json'],
                        'asset_slots_json' => $row['asset_slots_json'],
                        'meta_json' => $row['meta_json'],
                    ], 'MBTI zh result candidate '.$state['package']['package_id'], $adminUserId);

                $candidateRevisions[] = $this->revisionDescriptor($row['full_code'], $candidate, (string) $row['source_hash']);
                $rollbackRevisions[] = $this->revisionDescriptor($row['full_code'], $rollback, IdempotencyKey::hashPayload($rollback->snapshot_json));
            }

            return [
                ...$this->publicPlan($state),
                'stage' => 'draft_write',
                'candidate_revision_set_hash' => IdempotencyKey::hashPayload($candidateRevisions),
                'rollback_revision_set_hash' => IdempotencyKey::hashPayload($rollbackRevisions),
                'candidate_revisions' => $candidateRevisions,
                'rollback_revisions' => $rollbackRevisions,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function promotionDryRun(): array
    {
        $state = $this->resolveState(false);
        $revisions = $this->resolveRevisionSets($state);
        $receipt = [
            'schema' => 'mbti_zh_result_promotion_receipt.v1',
            'package_hash' => $state['package']['package_hash'],
            'pre_state_hash' => $state['pre_state_hash'],
            'candidate_revision_set_hash' => $revisions['candidate_revision_set_hash'],
            'rollback_revision_set_hash' => $revisions['rollback_revision_set_hash'],
            'record_count' => 32,
        ];

        return [
            ...$this->publicPlan($state),
            'stage' => 'promotion_dry_run',
            'candidate_revision_set_hash' => $revisions['candidate_revision_set_hash'],
            'rollback_revision_set_hash' => $revisions['rollback_revision_set_hash'],
            'candidate_revisions' => $revisions['candidate_revisions'],
            'rollback_revisions' => $revisions['rollback_revisions'],
            'receipt_hash' => IdempotencyKey::hashPayload($receipt),
        ];
    }

    /** @return array<string, mixed> */
    public function promote(string $expectedPackageHash, string $expectedPreStateHash, string $expectedRevisionSetHash): array
    {
        foreach (['package_hash' => $expectedPackageHash, 'pre_state_hash' => $expectedPreStateHash, 'revision_set_hash' => $expectedRevisionSetHash] as $name => $hash) {
            $this->assertSha($hash, $name);
        }

        return DB::transaction(function () use ($expectedPackageHash, $expectedPreStateHash, $expectedRevisionSetHash): array {
            $state = $this->resolveState(true);
            $this->assertPlanIdentity($state, $expectedPackageHash, $expectedPreStateHash);
            $sets = $this->resolveRevisionSets($state);
            if (! hash_equals($expectedRevisionSetHash, $sets['candidate_revision_set_hash'])) {
                throw new RuntimeException('Candidate revision set hash changed.');
            }

            foreach ($state['targets'] as $target) {
                $row = $target['row'];
                $record = $target['record'];
                $revision = $sets['candidate_models'][$row['full_code']];
                $meta = array_merge((array) $row['meta_json'], ['revision_no' => (int) $revision->revision_no]);
                $record->forceFill([
                    'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
                    'schema_version' => $row['schema_version'],
                    'content_json' => $row['content_json'],
                    'asset_slots_json' => $row['asset_slots_json'],
                    'meta_json' => $meta,
                    'published_at' => now(),
                ])->save();
            }

            return [
                'stage' => 'promotion_apply',
                'package_hash' => $expectedPackageHash,
                'record_count' => 32,
                'candidate_revision_set_hash' => $expectedRevisionSetHash,
                'rollback_revision_set_hash' => $sets['rollback_revision_set_hash'],
                'committed' => true,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function readback(string $expectedPackageHash): array
    {
        $this->assertSha($expectedPackageHash, 'package_hash');
        $state = $this->resolveState(false);
        $failures = [];
        $revisionNos = [];
        foreach ($state['targets'] as $target) {
            $record = $target['record'];
            $row = $target['row'];
            $meta = is_array($record->meta_json) ? $record->meta_json : [];
            if (($meta['package_hash'] ?? null) !== $expectedPackageHash
                || ($meta['source_hash'] ?? null) !== $row['source_hash']
                || count((array) $record->asset_slots_json) !== 7) {
                $failures[] = $row['full_code'];
            }
            foreach ((array) $record->asset_slots_json as $slot) {
                if (($slot['status'] ?? null) !== 'disabled' || ($slot['asset_ref'] ?? null) !== null) {
                    $failures[] = $row['full_code'];
                    break;
                }
            }
            $revisionNos[$row['full_code']] = (int) ($meta['revision_no'] ?? 0);
        }
        if ($failures !== []) {
            throw new RuntimeException('Readback failed for: '.implode(',', array_values(array_unique($failures))));
        }

        return [
            'stage' => 'readback',
            'package_hash' => $expectedPackageHash,
            'record_count' => 32,
            'disabled_slot_count' => 224,
            'revision_nos' => $revisionNos,
            'ok' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function rollback(string $expectedCurrentPackageHash, string $targetPreStateHash, string $expectedRollbackSetHash): array
    {
        foreach ([$expectedCurrentPackageHash, $targetPreStateHash, $expectedRollbackSetHash] as $hash) {
            $this->assertSha($hash, 'rollback identity hash');
        }

        return DB::transaction(function () use ($expectedCurrentPackageHash, $targetPreStateHash, $expectedRollbackSetHash): array {
            $state = $this->resolveState(true);
            foreach ($state['targets'] as $target) {
                if (($target['record']->meta_json['package_hash'] ?? null) !== $expectedCurrentPackageHash) {
                    throw new RuntimeException('Current package changed before rollback.');
                }
            }
            $descriptors = [];
            $models = [];
            foreach ($state['targets'] as $target) {
                $row = $target['row'];
                $revision = $this->findRevision($target['variant'], 'rollback_pre_state', $targetPreStateHash, null);
                if (! $revision) {
                    throw new RuntimeException('Rollback revision missing for '.$row['full_code']);
                }
                $models[$row['full_code']] = $revision;
                $descriptors[] = $this->revisionDescriptor($row['full_code'], $revision, IdempotencyKey::hashPayload($revision->snapshot_json));
            }
            if (! hash_equals($expectedRollbackSetHash, IdempotencyKey::hashPayload($descriptors))) {
                throw new RuntimeException('Rollback revision set hash changed.');
            }
            foreach ($state['targets'] as $target) {
                $snapshot = (array) data_get($models[$target['row']['full_code']]->snapshot_json, MbtiZhResultContentPackage::SNAPSHOT_KEY, []);
                $target['record']->forceFill([
                    'status' => $snapshot['status'],
                    'schema_version' => $snapshot['schema_version'],
                    'content_json' => $snapshot['content_json'],
                    'asset_slots_json' => $snapshot['asset_slots_json'],
                    'meta_json' => $snapshot['meta_json'],
                    'published_at' => $snapshot['published_at'] ?? now(),
                ])->save();
            }

            return ['stage' => 'rollback', 'record_count' => 32, 'restored_pre_state_hash' => $targetPreStateHash, 'committed' => true];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function resolveState(bool $lock): array
    {
        $package = $this->package->compile();
        $targets = [];
        foreach ($package['rows'] as $row) {
            $variantQuery = PersonalityProfileVariant::query()
                ->where('runtime_type_code', $row['full_code'])
                ->whereHas('profile', function (Builder $query): void {
                    $query->withoutGlobalScopes()->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)->where('locale', 'zh-CN');
                });
            $variant = $lock ? $variantQuery->lockForUpdate()->first() : $variantQuery->first();
            if (! $variant) {
                throw new RuntimeException('Variant missing for '.$row['full_code']);
            }
            $recordQuery = PersonalityProfileVariantCloneContent::query()
                ->where('personality_profile_variant_id', $variant->id)
                ->where('template_key', $package['template_key']);
            $record = $lock ? $recordQuery->lockForUpdate()->first() : $recordQuery->first();
            if (! $record) {
                throw new RuntimeException('Current clone record missing for '.$row['full_code']);
            }
            if ($record->status !== PersonalityProfileVariantCloneContent::STATUS_PUBLISHED) {
                throw new RuntimeException('Current clone record must already be published for '.$row['full_code'].'.');
            }
            $targets[] = ['row' => $row, 'variant' => $variant, 'record' => $record];
        }
        $preState = array_map(static fn (array $target): array => [
            'full_code' => $target['row']['full_code'],
            'status' => $target['record']->status,
            'schema_version' => $target['record']->schema_version,
            'content_json' => $target['record']->content_json,
            'asset_slots_json' => $target['record']->asset_slots_json,
            'meta_json' => $target['record']->meta_json,
        ], $targets);

        return ['package' => $package, 'targets' => $targets, 'pre_state_hash' => IdempotencyKey::hashPayload($preState)];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function publicPlan(array $state): array
    {
        return [
            'stage' => 'draft_dry_run',
            'package_id' => $state['package']['package_id'],
            'package_hash' => $state['package']['package_hash'],
            'pre_state_hash' => $state['pre_state_hash'],
            'record_count' => 32,
            'source_manifest' => $state['package']['source_manifest'],
            'writes' => false,
            'discoverability_changes' => false,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function resolveRevisionSets(array $state): array
    {
        $candidateDescriptors = [];
        $rollbackDescriptors = [];
        $candidateModels = [];
        foreach ($state['targets'] as $target) {
            $row = $target['row'];
            $candidate = $this->findRevision($target['variant'], 'candidate', $state['pre_state_hash'], $state['package']['package_hash']);
            $rollback = $this->findRevision($target['variant'], 'rollback_pre_state', $state['pre_state_hash'], null);
            if (! $candidate || ! $rollback) {
                throw new RuntimeException('Draft revision set is incomplete for '.$row['full_code']);
            }
            $candidateModels[$row['full_code']] = $candidate;
            $candidateDescriptors[] = $this->revisionDescriptor($row['full_code'], $candidate, $row['source_hash']);
            $rollbackDescriptors[] = $this->revisionDescriptor($row['full_code'], $rollback, IdempotencyKey::hashPayload($rollback->snapshot_json));
        }

        return [
            'candidate_revision_set_hash' => IdempotencyKey::hashPayload($candidateDescriptors),
            'rollback_revision_set_hash' => IdempotencyKey::hashPayload($rollbackDescriptors),
            'candidate_revisions' => $candidateDescriptors,
            'rollback_revisions' => $rollbackDescriptors,
            'candidate_models' => $candidateModels,
        ];
    }

    private function findRevision(PersonalityProfileVariant $variant, string $role, ?string $preStateHash, ?string $packageHash): ?PersonalityProfileVariantRevision
    {
        foreach (PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $variant->id)->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = (array) data_get($revision->snapshot_json, MbtiZhResultContentPackage::SNAPSHOT_KEY, []);
            if (($snapshot['role'] ?? null) === $role
                && ($preStateHash === null || ($snapshot['pre_state_hash'] ?? null) === $preStateHash)
                && ($packageHash === null || ($snapshot['package_hash'] ?? null) === $packageHash)) {
                return $revision;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $snapshot */
    private function createRevision(PersonalityProfileVariant $variant, array $snapshot, string $note, int $adminUserId): PersonalityProfileVariantRevision
    {
        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $variant->id,
            'revision_no' => ((int) PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $variant->id)->max('revision_no')) + 1,
            'snapshot_json' => [MbtiZhResultContentPackage::SNAPSHOT_KEY => $snapshot],
            'note' => $note,
            'created_by_admin_user_id' => $adminUserId,
            'created_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function revisionDescriptor(string $fullCode, PersonalityProfileVariantRevision $revision, string $sourceHash): array
    {
        return ['full_code' => $fullCode, 'revision_no' => (int) $revision->revision_no, 'revision_id' => (int) $revision->id, 'source_hash' => $sourceHash];
    }

    /** @param array<string,mixed> $state */
    private function assertPlanIdentity(array $state, string $packageHash, string $preStateHash): void
    {
        if (! hash_equals($packageHash, (string) $state['package']['package_hash']) || ! hash_equals($preStateHash, (string) $state['pre_state_hash'])) {
            throw new RuntimeException('Package or production pre-state changed.');
        }
    }

    private function assertSha(string $hash, string $name): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', strtolower(trim($hash))) !== 1) {
            throw new RuntimeException($name.' must be a SHA-256 hex value.');
        }
    }
}
