<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\ContentPackRelease;
use App\Services\ContentImport\RiasecEnglishPackageImporter;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Release-backed authority for W4 English RIASEC deep-result content.
 *
 * It never writes the question bank, scoring contract, Chinese registry, or
 * runtime activation table. A published release is an exact English content
 * revision; normal runtime rollout remains a separately controlled concern.
 */
final class RiasecContentPromotionAuthority
{
    public const RELEASE_ACTION = 'riasec_english_exact_promotion';

    private const PACK_ID = 'RIASEC';

    private const PACK_VERSION = 'w4-english-exact-v2';

    public function __construct(
        private readonly RiasecEnglishPackageImporter $importer,
        private readonly ContentReleaseManifestCatalogService $manifestCatalog,
    ) {}

    /** @return array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'W4' || $context->subscope !== 'riasec') {
            throw new DomainException('riasec_promotion_context_invalid');
        }
        $plan = $this->importer->authorityPlan($context->packageDirectory, $context->packageSha256);
        if (($plan['ok'] ?? false) !== true || (int) ($plan['row_count'] ?? -1) !== $context->expectedRowCount) {
            throw new DomainException('riasec_promotion_inventory_invalid');
        }
        $targets = [];
        foreach ((array) ($plan['authority_rows'] ?? []) as $row) {
            if (! is_array($row) || ($row['locale'] ?? null) !== 'en') {
                throw new DomainException('riasec_promotion_locale_invalid');
            }
            $forms = array_values(array_filter(array_map('strval', (array) ($row['supported_form_codes'] ?? []))));
            sort($forms, SORT_STRING);
            if ($forms === [] || array_diff($forms, ['riasec_60', 'riasec_140']) !== []
                || (in_array((string) ($row['group_id'] ?? ''), ['W4-G06', 'W4-G07'], true) && $forms !== ['riasec_140'])) {
                throw new DomainException('riasec_promotion_form_boundary_invalid');
            }
            $identity = [
                'row_id' => (string) ($row['row_id'] ?? ''),
                'stable_asset_identity' => (string) ($row['stable_asset_identity'] ?? ''),
                'group_id' => (string) ($row['group_id'] ?? ''),
                'locale' => 'en',
                'form_scope' => implode(',', $forms),
            ];
            if ($identity['row_id'] === '' || $identity['stable_asset_identity'] === '' || $identity['group_id'] === '') {
                throw new DomainException('riasec_promotion_target_identity_invalid');
            }
            $readerPayload = (array) ($row['reader_payload'] ?? []);
            $readerPayloadSha256 = (string) ($row['reader_payload_sha256'] ?? '');
            $sourceLineSha256 = (string) ($row['source_line_sha256'] ?? '');
            $segmentPayloadSha256 = (string) ($row['segment_payload_sha256'] ?? '');
            $snapshotSegment = (string) ($row['snapshot_segment'] ?? '');
            if ($readerPayload === [] || $snapshotSegment === ''
                || preg_match('/\A[a-f0-9]{64}\z/', $readerPayloadSha256) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $sourceLineSha256) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $segmentPayloadSha256) !== 1) {
                throw new DomainException('riasec_promotion_reader_copy_invalid');
            }
            $targets[] = [
                'identity' => $identity,
                'asset_key' => $identity['row_id'],
                'row' => $row,
                'supported_form_codes' => $forms,
                'snapshot_segment' => $snapshotSegment,
                'segment_payload_sha256' => $segmentPayloadSha256,
                'source_line_sha256' => $sourceLineSha256,
                'reader_payload' => $readerPayload,
                'reader_payload_sha256' => $readerPayloadSha256,
            ];
        }
        $targets = array_values($targets);
        usort($targets, static fn (array $a, array $b): int => $a['asset_key'] <=> $b['asset_key']);
        if (count($targets) !== $context->expectedRowCount || count(array_unique(array_column($targets, 'asset_key'))) !== count($targets)) {
            throw new DomainException('riasec_promotion_target_count_invalid');
        }
        $manifest = [
            'schema_version' => 'fermatmind.riasec_content_promotion_release.v2',
            'authority' => 'backend_riasec_result_content_release',
            'lane' => 'W4', 'subscope' => 'riasec', 'locale' => 'en',
            'package_sha256' => $context->packageSha256,
            'source_commit' => $context->sourceCommit,
            'expected_row_count' => $context->expectedRowCount,
            'logical_group_count' => (int) data_get($plan, 'package.logical_group_count'),
            'normalized_unordered_pair_count' => (int) data_get($plan, 'package.normalized_unordered_pair_count'),
            'safe_surface_counts' => (array) data_get($plan, 'package.safe_surface_counts'),
            'targets' => array_map(static fn (array $target): array => [
                'identity' => $target['identity'],
                'snapshot_segment' => $target['snapshot_segment'],
                'segment_payload_sha256' => $target['segment_payload_sha256'],
                'source_line_sha256' => $target['source_line_sha256'],
                'reader_payload' => $target['reader_payload'],
                'reader_payload_sha256' => $target['reader_payload_sha256'],
            ], $targets),
            'runtime_activation' => false,
            'indexability_mutation' => false,
        ];

        return ['targets' => $targets, 'manifest' => $manifest, 'manifest_hash' => hash('sha256', PromotionContextFactory::canonicalJson($manifest))];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package): array {
            $release = $this->release($context, true, $package);
            if ($release instanceof ContentPackRelease) {
                $this->assertRelease($release, $context, $package, 'draft');

                return ['created_count' => 0, 'unchanged_count' => count($package['targets']), 'readback_count' => count($package['targets'])];
            }
            $release = ContentPackRelease::query()->create([
                'id' => (string) Str::orderedUuid(), 'action' => self::RELEASE_ACTION,
                'region' => 'GLOBAL', 'locale' => 'en', 'dir_alias' => self::PACK_VERSION,
                'to_pack_id' => self::PACK_ID, 'status' => 'draft',
                'message' => 'Exact W4 English content revision staged inactive; runtime activation remains disabled.',
                'created_by' => 'content-promotion-v2', 'manifest_hash' => $package['manifest_hash'],
                'compiled_hash' => $context->executorReleaseSha256, 'content_hash' => $context->packageSha256,
                'pack_version' => self::PACK_VERSION, 'manifest_json' => $package['manifest'],
                'source_commit' => $context->sourceCommit,
            ]);
            $this->manifestCatalog->upsertManifest([
                'content_pack_release_id' => (string) $release->getKey(), 'manifest_hash' => $package['manifest_hash'],
                'schema_version' => 'fermatmind.riasec_content_promotion_release.v2', 'storage_disk' => 'database',
                'storage_path' => 'content_pack_releases/'.$release->getKey(), 'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION, 'compiled_hash' => $context->executorReleaseSha256,
                'content_hash' => $context->packageSha256, 'source_commit' => $context->sourceCommit,
                'payload_json' => $package['manifest'],
            ]);
            $this->assertRelease($release, $context, $package, 'draft');

            return ['created_count' => count($package['targets']), 'unchanged_count' => 0, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    public function publish(PromotionContext $context, ?string $expectedPreviousReleaseId): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package, $expectedPreviousReleaseId): array {
            $release = $this->release($context, true, $package);
            if (! $release instanceof ContentPackRelease) {
                throw new DomainException('riasec_promotion_draft_missing');
            }
            $this->assertRelease($release, $context, $package, 'draft_or_published');
            if ((string) $release->status === 'published') {
                $this->assertRelease($release, $context, $package, 'published');

                return ['changed_count' => 0, 'unchanged_count' => count($package['targets']), 'readback_count' => count($package['targets'])];
            }
            $previous = $this->activeRelease($context->packageSha256, true);
            if (($previous?->getKey() ?? null) !== $expectedPreviousReleaseId) {
                throw new DomainException('riasec_promotion_previous_release_drift');
            }
            if ($previous instanceof ContentPackRelease) {
                $previous->forceFill(['status' => 'superseded', 'message' => 'Superseded by exact W4 English content release '.$release->getKey().'.'])->saveQuietly();
            }
            $release->forceFill(['status' => 'published', 'message' => 'Exact W4 English content release published; runtime activation remains disabled.'])->saveQuietly();
            $this->assertRelease($release, $context, $package, 'published');

            return ['changed_count' => count($package['targets']), 'unchanged_count' => 0, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $release = $this->release($context, false, $package);
        if (! $release instanceof ContentPackRelease) {
            throw new DomainException('riasec_promotion_release_missing');
        }
        $this->assertRelease($release, $context, $package, 'published');
        $this->assertSyntheticProjection($package, 'riasec_60');
        $this->assertSyntheticProjection($package, 'riasec_140');

        return ['readback_count' => count($package['targets'])];
    }

    public function rollback(PromotionContext $context, ?string $previousReleaseId): void
    {
        $package = $this->inspect($context);
        DB::transaction(function () use ($context, $package, $previousReleaseId): void {
            $release = $this->release($context, true, $package);
            if (! $release instanceof ContentPackRelease || (string) $release->status !== 'published') {
                throw new DomainException('riasec_promotion_rollback_current_release_invalid');
            }
            $previous = $previousReleaseId === null ? null : ContentPackRelease::query()->lockForUpdate()->find($previousReleaseId);
            if ($previousReleaseId !== null && (! $previous instanceof ContentPackRelease || (string) $previous->action !== self::RELEASE_ACTION || (string) $previous->locale !== 'en' || (string) $previous->status !== 'superseded')) {
                throw new DomainException('riasec_promotion_rollback_previous_release_invalid');
            }
            $release->forceFill(['status' => 'rolled_back', 'message' => 'Rolled back to the previous exact RIASEC English content release.'])->saveQuietly();
            if ($previous instanceof ContentPackRelease) {
                $previous->forceFill(['status' => 'published', 'message' => 'Restored by exact W4 English content rollback.'])->saveQuietly();
            }
        }, 3);
    }

    public function activeReleaseId(string $packageSha256): ?string
    {
        return $this->activeRelease($packageSha256, false)?->getKey();
    }

    /** @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} $package */
    private function release(PromotionContext $context, bool $lock, array $package): ?ContentPackRelease
    {
        $query = ContentPackRelease::query()->where('action', self::RELEASE_ACTION)->where('content_hash', $context->packageSha256);
        if ($lock) {
            $query->lockForUpdate();
        }
        $release = $query->first();
        if ($release instanceof ContentPackRelease && ! hash_equals((string) $release->manifest_hash, $package['manifest_hash'])) {
            throw new DomainException('riasec_promotion_release_manifest_collision');
        }

        return $release;
    }

    private function activeRelease(string $excludingPackageSha256, bool $lock): ?ContentPackRelease
    {
        $query = ContentPackRelease::query()->where('action', self::RELEASE_ACTION)->where('locale', 'en')->where('status', 'published')->where('content_hash', '!=', $excludingPackageSha256)->orderByDesc('created_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} $package */
    private function assertRelease(ContentPackRelease $release, PromotionContext $context, array $package, string $state): void
    {
        if ((string) $release->action !== self::RELEASE_ACTION || (string) $release->locale !== 'en'
            || (string) $release->to_pack_id !== self::PACK_ID || ! hash_equals((string) $release->manifest_hash, $package['manifest_hash'])
            || ! hash_equals((string) $release->content_hash, $context->packageSha256)
            || ! in_array((string) $release->status, match ($state) {
                'draft' => ['draft'], 'published' => ['published'], default => ['draft', 'published']
            }, true)) {
            throw new DomainException('riasec_promotion_release_state_invalid');
        }
        $actualTargets = (array) data_get($release->manifest_json, 'targets', []);
        if (count($actualTargets) !== count($package['targets'])) {
            throw new DomainException('riasec_promotion_exact_readback_count_invalid');
        }
        foreach ($package['targets'] as $target) {
            $rowId = (string) data_get($target, 'identity.row_id');
            $actual = collect($actualTargets)->first(static fn (mixed $candidate): bool => is_array($candidate) && data_get($candidate, 'identity.row_id') === $rowId);
            if (! is_array($actual)
                || ! hash_equals((string) ($target['reader_payload_sha256'] ?? ''), (string) ($actual['reader_payload_sha256'] ?? ''))
                || ! hash_equals((string) ($target['source_line_sha256'] ?? ''), (string) ($actual['source_line_sha256'] ?? ''))
                || ! hash_equals((string) ($target['segment_payload_sha256'] ?? ''), (string) ($actual['segment_payload_sha256'] ?? ''))
                || (string) ($target['snapshot_segment'] ?? '') !== (string) ($actual['snapshot_segment'] ?? '')
                || PromotionContextFactory::canonicalJson((array) ($target['reader_payload'] ?? [])) !== PromotionContextFactory::canonicalJson((array) ($actual['reader_payload'] ?? []))
            ) {
                throw new DomainException('riasec_promotion_exact_readback_invalid');
            }
        }
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function assertSyntheticProjection(array $package, string $form): void
    {
        $counts = ['W4-G06' => 0, 'W4-G07' => 0];
        $safeKinds = ['W4-G12' => 'share_safe_variant', 'W4-G13' => 'pdf_safe_variant', 'W4-G14' => 'history_safe_variant'];
        $projected = 0;
        foreach ($package['targets'] as $target) {
            $identity = (array) ($target['identity'] ?? []);
            $forms = explode(',', (string) ($identity['form_scope'] ?? ''));
            if (! in_array($form, $forms, true)) {
                continue;
            }
            $projected++;
            $group = (string) ($identity['group_id'] ?? '');
            if (($identity['locale'] ?? null) !== 'en'
                || preg_match('/[\x{3400}-\x{9fff}]/u', PromotionContextFactory::canonicalJson((array) ($target['reader_payload'] ?? []))) === 1
                || ($form === 'riasec_60' && in_array($group, ['W4-G06', 'W4-G07'], true))) {
                throw new DomainException('riasec_promotion_public_projection_invalid');
            }
            if (isset($counts[$group])) {
                $counts[$group]++;
            }
            if (isset($safeKinds[$group])) {
                $payload = (array) ($target['reader_payload'] ?? []);
                if (($target['row']['asset_kind'] ?? null) !== $safeKinds[$group]
                    || ($payload['raw_scores_allowed'] ?? null) !== false
                    || ($payload['raw_feedback_allowed'] ?? null) !== false
                    || ($group === 'W4-G12' && ($payload['public_safe'] ?? null) !== true)
                    || (in_array($group, ['W4-G13', 'W4-G14'], true) && ($payload['public_safe'] ?? null) !== false)) {
                    throw new DomainException('riasec_promotion_safe_surface_invalid');
                }
            }
        }
        if ($projected === 0
            || ($form === 'riasec_60' && $counts !== ['W4-G06' => 0, 'W4-G07' => 0])
            || ($form === 'riasec_140' && $counts !== ['W4-G06' => 126, 'W4-G07' => 7])) {
            throw new DomainException('riasec_promotion_form_projection_invalid');
        }
    }
}
