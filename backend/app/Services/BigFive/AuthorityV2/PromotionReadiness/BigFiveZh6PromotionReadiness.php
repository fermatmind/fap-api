<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\PromotionReadiness;

use App\Models\AdminUser;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\PersonalityPublicContentAsset;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BigFiveZh6PromotionReadiness
{
    public const PACKAGE_SCHEMA = 'big5-zh6-promotion-readiness-package.v1';

    public const COHORT_ID = 'big_five_v2_zh_cn_hub_plus_five_domains_01';

    public const REVIEWER_ADMIN_USER_ID = 1;

    public const PUBLIC_LABEL = 'FermatMind Editorial';

    public const HUB_MEDIA_CONTENT_IDENTITY = 'big5:model_hub:zh-CN:hero-og';

    private const COHORT_SNAPSHOT_SHA256 = 'f724913f5cdd5fcd33b7e899e3bd8c7f9f003919c12d5631572d5ddebc4265fa';

    private const SNAPSHOT_PAYLOAD_SHA256 = '0c009c77310fb6ca8d67cf3fac2b85a56ecb892e5b6b20d56ee41de103e910d7';

    private const SNAPSHOT_FILE_SHA256 = 'b8206a045e100aed1016e24d4266ee8d75fb82b38496213f892a9dff0ed7eb5d';

    private const CONFIRMATION_RECORD_SHA256 = 'd1f958579c8527a8cc6bf18200b7927274e135ab72e61e780e4e2f9c69539fb9';

    private const CONFIRMATION_FILE_SHA256 = '31212f598c0a250c972f1776e67c7f6baadb29977bbbdecc45863469872086d8';

    private const OWNER_AUTHORITY_SHA256 = '6646dd8086d6e85a42539d8e77f4cda31649a903875825d7916d3023467134cf';

    /** @var list<array{asset_id:string,route:string,entity_type:string,entity_key:string}> */
    private const ASSETS = [
        [
            'asset_id' => 'model_hub:zh-CN:/zh/personality/big-five',
            'route' => '/zh/personality/big-five',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
        ],
        [
            'asset_id' => 'domain:zh-CN:/zh/personality/big-five/openness',
            'route' => '/zh/personality/big-five/openness',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
        ],
        [
            'asset_id' => 'domain:zh-CN:/zh/personality/big-five/conscientiousness',
            'route' => '/zh/personality/big-five/conscientiousness',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'conscientiousness',
        ],
        [
            'asset_id' => 'domain:zh-CN:/zh/personality/big-five/extraversion',
            'route' => '/zh/personality/big-five/extraversion',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'extraversion',
        ],
        [
            'asset_id' => 'domain:zh-CN:/zh/personality/big-five/agreeableness',
            'route' => '/zh/personality/big-five/agreeableness',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'agreeableness',
        ],
        [
            'asset_id' => 'domain:zh-CN:/zh/personality/big-five/neuroticism',
            'route' => '/zh/personality/big-five/neuroticism',
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'neuroticism',
        ],
    ];

    /** @return array<string,mixed> */
    public function packageOnly(string $packagePath): array
    {
        $package = $this->validatedPackage($packagePath);

        return [
            'ok' => true,
            'contract_valid' => true,
            'ready' => (bool) $package['ready_for_working_revision'],
            'status' => (string) $package['status'],
            'mode' => 'package_only_zero_write',
            'release_snapshot_sha256' => (string) $package['release_snapshot_sha256'],
            'package_payload_sha256' => (string) $package['package_payload_sha256'],
            'counts' => $package['counts'],
            'blockers' => $package['blockers'],
            'actions' => $this->zeroActions(0),
        ];
    }

    /** @return array<string,mixed> */
    public function databasePreflight(string $packagePath): array
    {
        $package = $this->validatedPackage($packagePath);
        $inspection = $this->inspectDatabase();
        $drift = [];

        $expectedAdmin = $package['editorial_authority']['review_record'] ?? [];
        if (($inspection['admin_user_1']['exists'] ?? false) !== true
            || ($inspection['admin_user_1']['is_active'] ?? false) !== true
            || ($expectedAdmin['author_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($expectedAdmin['reviewer_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($expectedAdmin['public_label'] ?? null) !== self::PUBLIC_LABEL) {
            $drift[] = 'admin_user_1_authority_mismatch';
        }
        if (($package['permissions']['reviewer']['totp_required'] ?? null) !== true
            || ((bool) config('admin.totp.enabled', true)
                && ($inspection['admin_user_1']['totp_enrolled'] ?? false) !== true)) {
            $drift[] = 'admin_user_1_totp_enrollment_missing';
        }

        $expectedRuntime = $package['runtime_baseline']['rows'] ?? null;
        if (! is_array($expectedRuntime)
            || ! hash_equals($this->canonicalSha256($expectedRuntime), $this->canonicalSha256($inspection['runtime_assets']['rows']))) {
            $drift[] = 'runtime_baseline_drift';
        }

        $expectedMediaCount = $package['media_authority']['eligible_candidate_count'] ?? null;
        $observedMediaCount = $inspection['media_inventory']['eligible_candidate_count'];
        if ($expectedMediaCount !== $observedMediaCount) {
            $drift[] = 'media_candidate_count_drift';
        }
        $expectedSelected = $package['media_authority']['selected_candidate']['candidate_sha256'] ?? null;
        $observedSelected = $observedMediaCount === 1
            ? ($inspection['media_inventory']['eligible_candidates'][0]['candidate_sha256'] ?? null)
            : null;
        if ($expectedSelected !== $observedSelected) {
            $drift[] = 'media_candidate_identity_drift';
        }

        $drift = array_values(array_unique($drift));
        sort($drift);
        $ready = $drift === []
            && $observedMediaCount === 1
            && ($package['ready_for_working_revision'] ?? false) === true
            && ($package['permissions']['reviewer']['approved'] ?? false) === true
            && ($package['permissions']['media']['approved'] ?? false) === true;
        $status = match (true) {
            $drift !== [] => 'FAIL_CLOSED_RUNTIME_OR_AUTHORITY_DRIFT',
            $observedMediaCount === 0 => 'HOLD_FAIL_CLOSED_ZERO_ELIGIBLE_HUB_MEDIA',
            $observedMediaCount > 1 => 'HOLD_FAIL_CLOSED_MULTIPLE_ELIGIBLE_HUB_MEDIA',
            $ready => 'PASS_PROMOTION_READINESS_ZERO_WRITE',
            default => 'HOLD_FAIL_CLOSED_PACKAGE_NOT_RELEASE_READY',
        };

        return [
            'ok' => $ready,
            'contract_valid' => true,
            'ready' => $ready,
            'status' => $status,
            'mode' => 'database_read_only_zero_write',
            'release_snapshot_sha256' => (string) $package['release_snapshot_sha256'],
            'package_payload_sha256' => (string) $package['package_payload_sha256'],
            'drift_codes' => $drift,
            'blockers' => $ready ? [] : array_values(array_unique([
                ...$drift,
                ...($observedMediaCount === 0 ? ['unique_hub_hero_og_media_missing'] : []),
                ...($observedMediaCount > 1 ? ['multiple_hub_hero_og_media_candidates'] : []),
            ])),
            'inspection' => $inspection,
            'actions' => $this->zeroActions((int) $inspection['database_reads']),
        ];
    }

    /** @return array<string,mixed> */
    public function inspectDatabase(): array
    {
        $admin = AdminUser::query()->whereKey(self::REVIEWER_ADMIN_USER_ID)->first([
            'id',
            'is_active',
            'totp_enabled_at',
        ]);
        $runtimeRows = $this->runtimeRows();
        $mediaCandidates = $this->mediaCandidates();

        return [
            'admin_user_1' => [
                'exists' => $admin instanceof AdminUser,
                'is_active' => $admin instanceof AdminUser && (int) $admin->is_active === 1,
                'totp_enrolled' => $admin instanceof AdminUser && $admin->totp_enabled_at !== null,
                'public_label' => self::PUBLIC_LABEL,
            ],
            'runtime_assets' => [
                'count_found' => count(array_filter($runtimeRows, static fn (array $row): bool => ($row['found'] ?? false) === true)),
                'rows' => array_map(static function (array $row): array {
                    unset($row['found']);

                    return $row;
                }, $runtimeRows),
            ],
            'media_inventory' => [
                'required_content_identity' => self::HUB_MEDIA_CONTENT_IDENTITY,
                'required_variant_keys' => ['hero', 'og'],
                'eligible_candidate_count' => count($mediaCandidates),
                'eligible_candidates' => $mediaCandidates,
                'selection_status' => count($mediaCandidates) === 1
                    ? 'unique_eligible_candidate'
                    : (count($mediaCandidates) === 0 ? 'blocked_zero_eligible_candidates' : 'blocked_multiple_eligible_candidates'),
                'fail_closed_on_zero_or_multiple' => true,
            ],
            'database_reads' => 9,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function runtimeRows(): array
    {
        $rows = [];
        foreach (self::ASSETS as $identity) {
            $asset = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                ->where('entity_type', $identity['entity_type'])
                ->where('entity_key', $identity['entity_key'])
                ->where('locale', 'zh-CN')
                ->first();
            if (! $asset instanceof PersonalityPublicContentAsset) {
                $rows[] = [
                    'asset_id' => $identity['asset_id'],
                    'route' => $identity['route'],
                    'found' => false,
                ];

                continue;
            }

            $attributes = $asset->getAttributes();
            unset($attributes['working_revision_id']);
            $canonicalPath = is_array($asset->canonical_json) && is_string($asset->canonical_json['path'] ?? null)
                ? $asset->canonical_json['path']
                : null;

            $rows[] = [
                'asset_id' => $identity['asset_id'],
                'route' => $identity['route'],
                'found' => true,
                'primary_id' => (int) $asset->getKey(),
                'working_revision_id' => $asset->getAttribute('working_revision_id') === null
                    ? null
                    : (int) $asset->getAttribute('working_revision_id'),
                'published_revision_id' => $asset->getAttribute('published_revision_id') === null
                    ? null
                    : (int) $asset->getAttribute('published_revision_id'),
                'public_runtime_baseline_sha256' => $this->canonicalSha256($attributes),
                'canonical_path' => $canonicalPath,
                'is_public' => (bool) $asset->is_public,
                'index_eligible' => (bool) $asset->index_eligible,
                'sitemap_eligible' => (bool) $asset->sitemap_eligible,
                'llms_eligible' => (bool) $asset->llms_eligible,
                'robots' => (string) $asset->robots,
                'launch_state' => (string) $asset->launch_state,
                'review_state' => (string) $asset->review_state,
                'source_hash' => (string) $asset->source_hash,
                'created_by_admin_user_id' => $asset->created_by_admin_user_id === null
                    ? null
                    : (int) $asset->created_by_admin_user_id,
                'last_reviewed_at' => $asset->last_reviewed_at?->toISOString(),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function mediaCandidates(): array
    {
        $assets = MediaAsset::query()
            ->withoutGlobalScopes()
            ->with('variants')
            ->where('org_id', 0)
            ->where('status', MediaAsset::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('sync_status', MediaAsset::SYNC_SYNCED)
            ->where('cdn_status', MediaAsset::CDN_VERIFIED)
            ->orderBy('id')
            ->get();
        $candidates = [];
        foreach ($assets as $asset) {
            $candidate = $this->mediaCandidate($asset);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @return array<string,mixed>|null */
    private function mediaCandidate(MediaAsset $asset): ?array
    {
        $payload = is_array($asset->payload_json) ? $asset->payload_json : [];
        if (($payload['content_identity'] ?? null) !== self::HUB_MEDIA_CONTENT_IDENTITY
            || ($payload['locale'] ?? null) !== 'zh-CN'
            || trim((string) $asset->alt) === '') {
            return null;
        }
        foreach (['rights', 'license', 'provenance', 'operator_approval_ref'] as $field) {
            $value = $payload[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
        }

        $publicUrls = [];
        foreach (['hero', 'og'] as $variantKey) {
            $variant = $asset->variants->firstWhere('variant_key', $variantKey);
            if (! $variant instanceof MediaVariant
                || $variant->sync_status !== MediaAsset::SYNC_SYNCED
                || $variant->cdn_status !== MediaAsset::CDN_VERIFIED) {
                return null;
            }
            $publicUrl = PublicMediaUrlGuard::canonicalMediaUrl($asset->disk, $variant->path, $variant->url);
            if ($publicUrl === null || ! PublicMediaUrlGuard::isAllowedPublicMediaUrl($publicUrl)) {
                return null;
            }
            $publicUrls[$variantKey] = $publicUrl;
        }

        $candidate = [
            'media_asset_id' => (int) $asset->getKey(),
            'media_asset_key' => (string) $asset->asset_key,
            'locale' => 'zh-CN',
            'content_identity' => self::HUB_MEDIA_CONTENT_IDENTITY,
            'status' => 'published_public_synced_cdn_verified',
            'variant_keys' => ['hero', 'og'],
            'public_urls' => $publicUrls,
            'alt' => (string) $asset->alt,
            'rights' => (string) $payload['rights'],
            'license' => (string) $payload['license'],
            'provenance' => (string) $payload['provenance'],
            'operator_approval_ref' => (string) $payload['operator_approval_ref'],
        ];

        return [
            ...$candidate,
            'candidate_sha256' => $this->canonicalSha256($candidate),
        ];
    }

    /** @return array<string,mixed> */
    private function validatedPackage(string $packagePath): array
    {
        $resolvedPath = $this->resolvePath($packagePath);
        $packageText = File::get($resolvedPath);
        $packageHashPath = preg_replace('/\.json$/', '.sha256', $resolvedPath);
        if (! is_string($packageHashPath) || $packageHashPath === $resolvedPath || ! File::isFile($packageHashPath)) {
            throw new RuntimeException('ZH6 promotion-readiness package SHA sidecar is missing.');
        }
        $declaredPackageSha256 = trim(File::get($packageHashPath));
        if (preg_match('/^[0-9a-f]{64}$/', $declaredPackageSha256) !== 1
            || ! hash_equals($declaredPackageSha256, hash('sha256', $packageText))) {
            throw new RuntimeException('ZH6 promotion-readiness package SHA sidecar mismatch.');
        }
        $package = json_decode($packageText, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($package)
            || ($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA
            || ($package['cohort_id'] ?? null) !== self::COHORT_ID) {
            throw new RuntimeException('ZH6 promotion-readiness package schema or cohort mismatch.');
        }
        if (($package['counts']['assets'] ?? null) !== 6
            || ($package['counts']['reviewed_assets'] ?? null) !== 6
            || ($package['counts']['source_permission_assets'] ?? null) !== 6
            || ($package['counts']['visible_sources'] ?? null) !== 18
            || ($package['counts']['runtime_baselines'] ?? null) !== 6) {
            throw new RuntimeException('ZH6 promotion-readiness package counts are not exact.');
        }
        $review = $package['editorial_authority']['review_record'] ?? null;
        if (! is_array($review)
            || ($review['cohort_snapshot_sha256'] ?? null) !== self::COHORT_SNAPSHOT_SHA256
            || ($review['package_payload_sha256'] ?? null) !== self::SNAPSHOT_PAYLOAD_SHA256
            || ($review['package_file_sha256'] ?? null) !== self::SNAPSHOT_FILE_SHA256
            || ($review['confirmation_record_sha256'] ?? null) !== self::CONFIRMATION_RECORD_SHA256
            || ($review['mode'] ?? null) !== 'solo_operator'
            || ($review['explicit_self_review'] ?? null) !== true
            || ($review['author_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($review['reviewer_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($review['public_label'] ?? null) !== self::PUBLIC_LABEL
            || ($review['revision_binding'] ?? null) !== 'pending_exact_working_revision_ids_in_task50'
            || ($review['global_role_separation_relaxed'] ?? null) !== false
            || count($review['assets'] ?? []) !== 6) {
            throw new RuntimeException('ZH6 solo_operator review contract is invalid.');
        }
        $sourceRows = $package['source_permissions']['rows'] ?? null;
        if (! is_array($sourceRows)
            || count($sourceRows) !== 6
            || collect($sourceRows)->contains(static fn (mixed $row): bool => ! is_array($row)
                || ($row['approved'] ?? null) !== true
                || ($row['permission_scope'] ?? null) !== 'public_link_citation_and_original_paraphrase_only'
                || ! is_array($row['source_ids'] ?? null)
                || count($row['source_ids'] ?? []) !== 3)) {
            throw new RuntimeException('ZH6 source permissions are incomplete.');
        }
        $permissions = $package['permissions'] ?? null;
        $reviewerApproved = is_array($permissions) ? ($permissions['reviewer']['approved'] ?? null) : null;
        $reviewerTotpEnrolled = is_array($permissions) ? ($permissions['reviewer']['totp_enrolled'] ?? null) : null;
        $expectedReviewerAuthority = $reviewerTotpEnrolled === true
            ? 'solo_operator_review:'.(string) ($package['editorial_authority']['review_record_sha256'] ?? '')
            : null;
        if (! is_array($permissions)
            || ($permissions['author']['approved'] ?? null) !== true
            || ($permissions['author']['authority_reference'] ?? null) !== 'admin_user:1'
            || ($permissions['author']['public_label'] ?? null) !== self::PUBLIC_LABEL
            || ! is_bool($reviewerApproved)
            || ! is_bool($reviewerTotpEnrolled)
            || $reviewerApproved !== $reviewerTotpEnrolled
            || ($permissions['reviewer']['totp_required'] ?? null) !== true
            || ($permissions['reviewer']['authority_reference'] ?? null) !== $expectedReviewerAuthority
            || ($permissions['reviewer']['admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($permissions['sources']['approved'] ?? null) !== true
            || ($permissions['sources']['asset_count'] ?? null) !== 6
            || ($permissions['sources']['visible_source_count'] ?? null) !== 18) {
            throw new RuntimeException('ZH6 editorial or source permission binding is invalid.');
        }
        $rollbackRows = $package['rollback_baseline']['rows'] ?? null;
        if (! is_array($rollbackRows)
            || count($rollbackRows) !== 6
            || collect($rollbackRows)->contains(static fn (mixed $row): bool => ! is_array($row)
                || ($row['exact_target_bound'] ?? null) !== true
                || ($row['abort_on_missing_or_drifted_target'] ?? null) !== true)) {
            throw new RuntimeException('ZH6 rollback baseline is incomplete.');
        }
        $runtimeRows = $package['runtime_baseline']['rows'] ?? null;
        if (! is_array($runtimeRows) || count($runtimeRows) !== 6) {
            throw new RuntimeException('ZH6 runtime baseline is incomplete.');
        }
        $expectedRollbackRows = [];
        foreach ($runtimeRows as $runtimeRow) {
            if (! is_array($runtimeRow)
                || trim((string) ($runtimeRow['asset_id'] ?? '')) === ''
                || trim((string) ($runtimeRow['route'] ?? '')) === ''
                || ! is_int($runtimeRow['primary_id'] ?? null)
                || ($runtimeRow['primary_id'] ?? 0) < 1
                || (! is_null($runtimeRow['working_revision_id'] ?? null)
                    && (! is_int($runtimeRow['working_revision_id']) || $runtimeRow['working_revision_id'] < 1))
                || (! is_null($runtimeRow['published_revision_id'] ?? null)
                    && (! is_int($runtimeRow['published_revision_id']) || $runtimeRow['published_revision_id'] < 1))
                || preg_match('/^[0-9a-f]{64}$/', (string) ($runtimeRow['public_runtime_baseline_sha256'] ?? '')) !== 1) {
                throw new RuntimeException('ZH6 runtime baseline row is invalid.');
            }
            $expectedRollbackRows[] = [
                'asset_id' => $runtimeRow['asset_id'],
                'route' => $runtimeRow['route'],
                'primary_id' => $runtimeRow['primary_id'],
                'observed_working_revision_id' => $runtimeRow['working_revision_id'],
                'restore_published_revision_id' => $runtimeRow['published_revision_id'],
                'restore_public_runtime_baseline_sha256' => $runtimeRow['public_runtime_baseline_sha256'],
                'exact_target_bound' => true,
                'abort_on_missing_or_drifted_target' => true,
            ];
        }
        if (! hash_equals($this->canonicalSha256($expectedRollbackRows), $this->canonicalSha256($rollbackRows))) {
            throw new RuntimeException('ZH6 rollback rows do not match the runtime baseline.');
        }
        if (($package['media_authority']['fail_closed_on_zero_or_multiple'] ?? null) !== true
            || ($package['media_authority']['required_content_identity'] ?? null) !== self::HUB_MEDIA_CONTENT_IDENTITY) {
            throw new RuntimeException('ZH6 media uniqueness contract is invalid.');
        }
        if (! hash_equals((string) ($package['editorial_authority']['review_record_sha256'] ?? ''), $this->canonicalSha256($review))
            || ! hash_equals((string) ($package['source_permissions']['source_permission_sha256'] ?? ''), $this->canonicalSha256($sourceRows))
            || ! hash_equals((string) ($package['rollback_baseline']['rollback_baseline_sha256'] ?? ''), $this->canonicalSha256($rollbackRows))) {
            throw new RuntimeException('ZH6 readiness subrecord SHA mismatch.');
        }
        $permissionMaterial = $permissions;
        $permissionsSha256 = $permissionMaterial['permissions_sha256'] ?? null;
        unset($permissionMaterial['permissions_sha256']);
        if (! is_string($permissionsSha256)
            || ! hash_equals($permissionsSha256, $this->canonicalSha256($permissionMaterial))) {
            throw new RuntimeException('ZH6 permissions SHA mismatch.');
        }

        $eligibleMediaCount = $package['media_authority']['eligible_candidate_count'] ?? null;
        $mediaApproved = $package['permissions']['media']['approved'] ?? null;
        $workingReady = $package['ready_for_working_revision'] ?? null;
        $expectedWorkingReady = $eligibleMediaCount === 1 && $reviewerApproved === true;
        $expectedStatus = $eligibleMediaCount !== 1
            ? 'HOLD_FAIL_CLOSED_MEDIA_AUTHORITY'
            : ($reviewerApproved === true ? 'PASS_PROMOTION_READINESS_ZERO_WRITE' : 'HOLD_FAIL_CLOSED_REVIEWER_TOTP');
        $mediaMaterial = array_intersect_key($package['media_authority'], array_flip([
            'required_content_identity',
            'required_variant_keys',
            'selection_status',
            'eligible_candidate_count',
            'selected_candidate',
            'fail_closed_on_zero_or_multiple',
            'observation_sha256',
        ]));
        $mediaAuthoritySha256 = $package['media_authority']['media_authority_sha256'] ?? null;
        $expectedMediaAuthorityReference = $eligibleMediaCount === 1 && is_string($mediaAuthoritySha256)
            ? 'media_authority:'.$mediaAuthoritySha256
            : null;
        if (! is_int($eligibleMediaCount)
            || ! is_string($mediaAuthoritySha256)
            || ! hash_equals($mediaAuthoritySha256, $this->canonicalSha256($mediaMaterial))
            || ($eligibleMediaCount === 1) !== ($mediaApproved === true)
            || ($package['permissions']['media']['authority_reference'] ?? null) !== $expectedMediaAuthorityReference
            || $expectedWorkingReady !== ($workingReady === true)
            || ($package['status'] ?? null) !== $expectedStatus
            || ($package['counts']['eligible_hub_media_candidates'] ?? null) !== $eligibleMediaCount
            || ($package['counts']['selected_hub_media_assets'] ?? null) !== ($eligibleMediaCount === 1 ? 1 : 0)
            || ($eligibleMediaCount !== 1 && ($package['media_authority']['selected_candidate'] ?? null) !== null)
            || (($reviewerApproved === false) !== in_array('admin_user_1_totp_enrollment_missing', $package['blockers'] ?? [], true))
            || ($expectedWorkingReady && ($package['blockers'] ?? null) !== [])
            || ($eligibleMediaCount === 0 && ! in_array('unique_hub_hero_og_media_missing', $package['blockers'] ?? [], true))
            || ($eligibleMediaCount > 1 && ! in_array('multiple_hub_hero_og_media_candidates', $package['blockers'] ?? [], true))) {
            throw new RuntimeException('ZH6 media readiness disposition is inconsistent.');
        }
        if ($eligibleMediaCount === 1) {
            $selectedCandidate = $package['media_authority']['selected_candidate'];
            $selectedCandidateSha256 = $selectedCandidate['candidate_sha256'] ?? null;
            unset($selectedCandidate['candidate_sha256']);
            if (! is_string($selectedCandidateSha256)
                || ! hash_equals($selectedCandidateSha256, $this->canonicalSha256($selectedCandidate))) {
                throw new RuntimeException('ZH6 selected media candidate SHA mismatch.');
            }
        }
        if (($package['ready_for_promotion'] ?? null) !== false
            || ($package['release_snapshot_executable'] ?? null) !== false) {
            throw new RuntimeException('PR49 package must never authorize promotion or release execution.');
        }

        $payload = $package;
        $packagePayloadSha256 = $payload['package_payload_sha256'] ?? null;
        unset($payload['package_payload_sha256']);
        if (! is_string($packagePayloadSha256)
            || ! hash_equals($packagePayloadSha256, $this->canonicalSha256($payload))) {
            throw new RuntimeException('ZH6 promotion-readiness package payload SHA mismatch.');
        }
        if (! is_array($package['release_lock_material'] ?? null)
            || ! hash_equals((string) $package['release_snapshot_sha256'], $this->canonicalSha256($package['release_lock_material']))) {
            throw new RuntimeException('ZH6 release snapshot SHA mismatch.');
        }

        $inputHashFields = [
            'snapshot_path' => ['snapshot_file_sha256', self::SNAPSHOT_FILE_SHA256],
            'confirmation_path' => ['confirmation_file_sha256', self::CONFIRMATION_FILE_SHA256],
            'owner_authority_path' => ['owner_authority_sha256', self::OWNER_AUTHORITY_SHA256],
        ];
        $resolvedInputs = [];
        foreach ($inputHashFields as $field => [$shaField, $expectedSha256]) {
            $value = $package['inputs'][$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('ZH6 readiness input path is missing: '.$field.'.');
            }
            $inputPath = $this->resolvePath((string) $value);
            $resolvedInputs[$field] = $inputPath;
            if (! hash_equals($expectedSha256, (string) ($package['inputs'][$shaField] ?? ''))
                || ! hash_equals($expectedSha256, hash_file('sha256', $inputPath))) {
                throw new RuntimeException('ZH6 readiness input SHA mismatch: '.$field.'.');
            }
        }
        $snapshot = $this->readJson($resolvedInputs['snapshot_path']);
        $snapshotAssets = $snapshot['assets'] ?? null;
        if (($snapshot['cohort_id'] ?? null) !== self::COHORT_ID
            || ($snapshot['cohort_snapshot_sha256'] ?? null) !== self::COHORT_SNAPSHOT_SHA256
            || ($snapshot['package_payload_sha256'] ?? null) !== self::SNAPSHOT_PAYLOAD_SHA256
            || ! is_array($snapshotAssets)
            || count($snapshotAssets) !== 6) {
            throw new RuntimeException('ZH6 locked snapshot content is invalid.');
        }
        $expectedReviewAssets = [];
        $expectedSourceRows = [];
        foreach ($snapshotAssets as $asset) {
            $visibleSources = is_array($asset) ? ($asset['public_snapshot']['visible_sources'] ?? null) : null;
            $sourceAuthority = is_array($asset) ? ($asset['source_authority'] ?? null) : null;
            if (! is_array($asset)
                || trim((string) ($asset['asset_id'] ?? '')) === ''
                || trim((string) ($asset['canonical_path'] ?? '')) === ''
                || preg_match('/^[0-9a-f]{64}$/', (string) ($asset['snapshot_sha256'] ?? '')) !== 1
                || ! is_array($visibleSources)
                || count($visibleSources) !== 3
                || ! is_array($sourceAuthority)
                || ($sourceAuthority['status'] ?? null) !== 'approved_for_link_citation_and_original_paraphrase'
                || preg_match('/^[0-9a-f]{64}$/', (string) ($sourceAuthority['locked_ledger_sha256'] ?? '')) !== 1) {
                throw new RuntimeException('ZH6 locked snapshot source authority is invalid.');
            }
            $sourceIds = array_map(
                static fn (mixed $source): string => is_array($source) ? (string) ($source['source_id'] ?? '') : '',
                $visibleSources,
            );
            if (collect($sourceIds)->contains(static fn (string $sourceId): bool => trim($sourceId) === '')) {
                throw new RuntimeException('ZH6 locked snapshot source id is invalid.');
            }
            $expectedReviewAssets[] = [
                'asset_id' => $asset['asset_id'],
                'canonical_path' => $asset['canonical_path'],
                'snapshot_sha256' => $asset['snapshot_sha256'],
            ];
            $expectedSourceRows[] = [
                'asset_id' => $asset['asset_id'],
                'snapshot_sha256' => $asset['snapshot_sha256'],
                'approved' => true,
                'permission_scope' => 'public_link_citation_and_original_paraphrase_only',
                'approval_reference' => 'source-ledger:'.$sourceAuthority['locked_ledger_sha256'],
                'source_ids' => $sourceIds,
            ];
        }
        if (! hash_equals($this->canonicalSha256($expectedReviewAssets), $this->canonicalSha256($review['assets']))
            || ! hash_equals($this->canonicalSha256($expectedSourceRows), $this->canonicalSha256($sourceRows))) {
            throw new RuntimeException('ZH6 review or source rows do not match the locked snapshot.');
        }
        $observationPath = $package['inputs']['production_observation_path'] ?? null;
        $observationSha256 = $package['inputs']['production_observation_sha256'] ?? null;
        if (! is_string($observationPath) || trim($observationPath) === ''
            || ! is_string($observationSha256)
            || preg_match('/^[0-9a-f]{64}$/', $observationSha256) !== 1) {
            throw new RuntimeException('ZH6 production observation path or SHA is invalid.');
        }
        $resolvedInputs['production_observation_path'] = $this->resolvePath($observationPath);
        if (dirname($resolvedInputs['production_observation_path']) !== dirname($resolvedPath)
            || basename($resolvedInputs['production_observation_path']) !== 'production-observation.json') {
            throw new RuntimeException('ZH6 production observation must be the reviewed package sibling.');
        }
        if (! hash_equals($observationSha256, hash_file('sha256', $resolvedInputs['production_observation_path']))
            || ! hash_equals($observationSha256, (string) ($package['media_authority']['observation_sha256'] ?? ''))
            || ! hash_equals($observationSha256, (string) ($package['release_lock_material']['production_observation_sha256'] ?? ''))) {
            throw new RuntimeException('ZH6 production observation SHA binding is invalid.');
        }
        $observation = $this->readJson($resolvedInputs['production_observation_path']);
        $observationRuntimeRows = $observation['runtime_assets']['rows'] ?? null;
        $observationCandidates = $observation['media_inventory']['authority_complete_hero_og'] ?? null;
        $observationCandidateCount = $observation['media_inventory']['authority_complete_hero_og_count'] ?? null;
        if (($observation['schema_version'] ?? null) !== 'big5-zh6-promotion-readiness-production-observation.v1'
            || ($observation['admin_user_1']['exists'] ?? null) !== true
            || ($observation['admin_user_1']['is_active'] ?? null) !== true
            || ! is_bool($observation['admin_user_1']['totp_enrolled'] ?? null)
            || ($observation['admin_user_1']['totp_enrolled'] ?? null) !== $reviewerTotpEnrolled
            || ($observation['admin_user_1']['public_label'] ?? null) !== self::PUBLIC_LABEL
            || ! is_array($observationRuntimeRows)
            || count($observationRuntimeRows) !== 6
            || ! hash_equals($this->canonicalSha256($observationRuntimeRows), $this->canonicalSha256($package['runtime_baseline']['rows'] ?? []))
            || ! is_array($observationCandidates)
            || ! is_int($observationCandidateCount)
            || $observationCandidateCount !== count($observationCandidates)
            || $observationCandidateCount !== $eligibleMediaCount) {
            throw new RuntimeException('ZH6 production observation content is inconsistent.');
        }
        if ($observationCandidateCount === 1) {
            $observedCandidate = $this->observationMediaCandidate($observationCandidates[0]);
            $selectedCandidate = $package['media_authority']['selected_candidate'];
            unset($selectedCandidate['candidate_sha256']);
            if (! hash_equals($this->canonicalSha256($observedCandidate), $this->canonicalSha256($selectedCandidate))) {
                throw new RuntimeException('ZH6 selected media candidate does not match the observation.');
            }
        }
        $confirmation = $this->readJson($resolvedInputs['confirmation_path']);
        $ownerAuthority = $this->readJson($resolvedInputs['owner_authority_path']);
        $expectedPhrase = '我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；'
            .'cohort_snapshot_sha256='.self::COHORT_SNAPSHOT_SHA256.'；'
            .'package_payload_sha256='.self::SNAPSHOT_PAYLOAD_SHA256.'；'
            .'package_file_sha256='.self::SNAPSHOT_FILE_SHA256.'；'
            .'CMS reviewer_admin_user_id=1。';
        $expectedExternalHumanAuthority = [
            'source' => $ownerAuthority['source'] ?? null,
            'pull_request_number' => $ownerAuthority['pull_request_number'] ?? null,
            'comment_database_id' => $ownerAuthority['comment_database_id'] ?? null,
            'author_login' => $ownerAuthority['author_login'] ?? null,
            'author_association' => $ownerAuthority['author_association'] ?? null,
            'confirmation_phrase_sha256' => hash('sha256', $expectedPhrase),
        ];
        if (($confirmation['status'] ?? null) !== 'approved_by_real_human'
            || ($confirmation['reviewer_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($confirmation['confirmation_record_sha256'] ?? null) !== self::CONFIRMATION_RECORD_SHA256
            || ($confirmation['confirmation_phrase'] ?? null) !== $expectedPhrase
            || ($ownerAuthority['schema_version'] ?? null) !== 'big5-zh6-pr48-owner-authority.v1'
            || ($ownerAuthority['source'] ?? null) !== 'github_pull_request_comment'
            || ($ownerAuthority['repository'] ?? null) !== 'fermatmind/fap-api'
            || ($ownerAuthority['author_login'] ?? null) !== 'fermatmind'
            || ($ownerAuthority['author_association'] ?? null) !== 'OWNER'
            || ($ownerAuthority['reviewer_admin_user_id'] ?? null) !== self::REVIEWER_ADMIN_USER_ID
            || ($ownerAuthority['confirmation_phrase'] ?? null) !== $expectedPhrase
            || ($review['reviewed_at'] ?? null) !== ($ownerAuthority['confirmed_at'] ?? null)
            || ! is_array($review['external_human_authority'] ?? null)
            || ! hash_equals(
                $this->canonicalSha256($expectedExternalHumanAuthority),
                $this->canonicalSha256($review['external_human_authority']),
            )) {
            throw new RuntimeException('ZH6 real-human OWNER authority binding is invalid.');
        }
        foreach (['cms_or_database_write', 'working_revision_write', 'media_authority', 'promotion_or_publication', 'indexability_sitemap_llms_schema', 'deployment_cache_or_search'] as $field) {
            if (($ownerAuthority['approval_scope'][$field] ?? null) !== false) {
                throw new RuntimeException('ZH6 OWNER approval scope overclaims controlled authority: '.$field.'.');
            }
        }
        $expectedActions = [
            'production_database_read_only_observation' => true,
            'database_writes' => 0,
            'cms_writes' => 0,
            'media_library_writes' => 0,
            'media_uploads' => 0,
            'working_revisions_created' => 0,
            'promotions' => 0,
            'published_pointer_changes' => 0,
            'indexability_changes' => 0,
            'sitemap_changes' => 0,
            'llms_changes' => 0,
            'schema_changes' => 0,
            'search_submissions' => 0,
            'cache_operations' => 0,
            'deployments' => 0,
        ];
        $actions = $package['actions'] ?? null;
        if (! is_array($actions)
            || ! hash_equals($this->canonicalSha256($expectedActions), $this->canonicalSha256($actions))) {
            throw new RuntimeException('PR49 action evidence must include the exact read-only observation and zero-mutation fields.');
        }

        return $package;
    }

    private function resolvePath(string $path): string
    {
        $resolved = match (true) {
            str_starts_with($path, DIRECTORY_SEPARATOR) => $path,
            str_starts_with($path, '..'.DIRECTORY_SEPARATOR) => base_path($path),
            default => base_path('../'.$path),
        };
        $realpath = realpath($resolved);
        if (! is_string($realpath) || ! File::isFile($realpath)) {
            throw new RuntimeException('Required readiness artifact was not found: '.$resolved.'.');
        }

        return $realpath;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $value = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('Required readiness JSON is not an object: '.$path.'.');
        }

        return $value;
    }

    /** @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function observationMediaCandidate(array $candidate): array
    {
        $publicUrls = $candidate['public_urls'] ?? null;
        if (! is_int($candidate['media_asset_id'] ?? null)
            || ($candidate['media_asset_id'] ?? 0) < 1
            || trim((string) ($candidate['media_asset_key'] ?? '')) === ''
            || ($candidate['locale'] ?? null) !== 'zh-CN'
            || ($candidate['content_identity'] ?? null) !== self::HUB_MEDIA_CONTENT_IDENTITY
            || ($candidate['status'] ?? null) !== 'published_public_synced_cdn_verified'
            || ! is_array($candidate['variant_keys'] ?? null)
            || ! in_array('hero', $candidate['variant_keys'], true)
            || ! in_array('og', $candidate['variant_keys'], true)
            || ! is_array($publicUrls)
            || ! PublicMediaUrlGuard::isAllowedPublicMediaUrl((string) ($publicUrls['hero'] ?? ''))
            || ! PublicMediaUrlGuard::isAllowedPublicMediaUrl((string) ($publicUrls['og'] ?? ''))
            || trim((string) ($candidate['alt'] ?? '')) === '') {
            throw new RuntimeException('ZH6 observed media candidate identity is invalid.');
        }
        foreach (['rights', 'license', 'provenance', 'operator_approval_ref'] as $field) {
            $value = $candidate[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('ZH6 observed media candidate authority is incomplete.');
            }
        }

        return [
            'media_asset_id' => $candidate['media_asset_id'],
            'media_asset_key' => (string) $candidate['media_asset_key'],
            'locale' => 'zh-CN',
            'content_identity' => self::HUB_MEDIA_CONTENT_IDENTITY,
            'status' => 'published_public_synced_cdn_verified',
            'variant_keys' => ['hero', 'og'],
            'public_urls' => [
                'hero' => (string) $publicUrls['hero'],
                'og' => (string) $publicUrls['og'],
            ],
            'alt' => (string) $candidate['alt'],
            'rights' => (string) $candidate['rights'],
            'license' => (string) $candidate['license'],
            'provenance' => (string) $candidate['provenance'],
            'operator_approval_ref' => (string) $candidate['operator_approval_ref'],
        ];
    }

    /** @param array<mixed> $value */
    private function canonicalSha256(array $value): string
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

    /** @return array<string,int> */
    private function zeroActions(int $databaseReads): array
    {
        return [
            'database_reads' => $databaseReads,
            'database_writes' => 0,
            'cms_writes' => 0,
            'media_library_writes' => 0,
            'media_uploads' => 0,
            'working_revisions_created' => 0,
            'promotions' => 0,
            'published_pointer_changes' => 0,
            'indexability_changes' => 0,
            'sitemap_changes' => 0,
            'llms_changes' => 0,
            'schema_changes' => 0,
            'search_submissions' => 0,
            'cache_operations' => 0,
            'deployments' => 0,
        ];
    }
}
