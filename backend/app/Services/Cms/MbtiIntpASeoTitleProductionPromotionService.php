<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface mbti_approval_batch */
final class MbtiIntpASeoTitleProductionPromotionService
{
    private const SCHEMA_VERSION = 'personality.mbti-seo-title-production-promotion.v1';

    private const PROMOTION_ID = 'zh-intp-a-seo-title-production-20260810-v1';

    private const CURRENT_TITLE = 'INTP-A 人格特点：分析建模、可能性探索和独立解题 | FermatMind';

    private const PROPOSED_TITLE = 'INTP-A 是什么？人格特点、优势盲点与适合场景 | FermatMind';

    private const CURRENT_DESCRIPTION = '了解 INTP-A 的分析建模、可能性探索和独立解题、适合与不适合的场景、A/T 差异、职业、关系、压力应对、常见误解与 FAQ。内容仅用于自我理解和成长复盘。';

    private const TARGET_ROUTE = '/zh/personality/intp-a';

    private const EXPERIMENT_PACKAGE_SHA256 = '809a86d39744bf13c8aa5c43eeaa5f12ec9116c5cd6f6a8af68eea551aa8dc96';

    private const STAGING_RUN_ID = '31395530368';

    private const STAGING_RECEIPT_SHA256 = 'd5bdc286f156f7b07f4694b0dc702461eeb64f1fdd359d0cfc42b22beef1d57a';

    private const STAGING_REVISION_SNAPSHOT_SHA256 = 'ec74c351c92498fec3714368bceb9b3f2674f07b2c9e5b313b9826461faab7be';

    public function __construct(
        private readonly MbtiSeoFieldOverrideRevisionService $overrideRevisions,
        private readonly PersonalityPublicReadModelCache $publicReadModelCache,
    ) {}

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function plan(array $package, string $packageSha256, string $activeRevision): array
    {
        $this->assertPackage($package, $packageSha256);
        [$profile, $variant, $seoMeta] = $this->resolveAuthority();
        $resolution = $this->overrideRevisions->resolve($profile, $variant, $seoMeta);

        if ($this->isExactPromotedMarker($resolution, $packageSha256)) {
            return $this->receipt('idempotent_promoted_live', $packageSha256, $activeRevision, 0, 1, 0, 0, false, $resolution);
        }

        $this->assertNoConflictingMarker($resolution, $packageSha256);
        $this->assertBaseline($profile, $variant, $seoMeta, $package);

        return $this->receipt('planned', $packageSha256, $activeRevision, 0, 0, 0, 0, false, $resolution);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function promote(array $package, string $packageSha256, string $activeRevision): array
    {
        $this->assertPackage($package, $packageSha256);

        return DB::transaction(function () use ($package, $packageSha256, $activeRevision): array {
            [$profile, $variant, $seoMeta] = $this->resolveAuthority(lock: true);
            $resolution = $this->overrideRevisions->resolve($profile, $variant, $seoMeta, lock: true);

            if ($this->isExactPromotedMarker($resolution, $packageSha256)) {
                return $this->receipt('idempotent_promoted_live', $packageSha256, $activeRevision, 0, 1, 0, 0, false, $resolution);
            }

            $this->assertNoConflictingMarker($resolution, $packageSha256);
            $this->assertBaseline($profile, $variant, $seoMeta, $package);
            $unchangedBefore = $this->unchangedAuthorityFingerprint($profile, $variant, $seoMeta);

            $seoMeta->seo_title = self::PROPOSED_TITLE;
            $seoMeta->save();
            $marker = $this->appendMarker(
                $variant,
                MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
                $packageSha256,
            );

            $profile->refresh();
            $variant->refresh();
            $seoMeta->refresh();
            if (! hash_equals($unchangedBefore, $this->unchangedAuthorityFingerprint($profile, $variant, $seoMeta))) {
                throw new RuntimeException('A field outside seo_title changed during production promotion.');
            }
            $verified = $this->overrideRevisions->resolve($profile, $variant, $seoMeta, lock: true);
            if ((int) $verified['marker_revision_id'] !== (int) $marker->id) {
                throw new RuntimeException('Promoted override marker readback mismatch.');
            }
            if (! $this->publicReadModelCache->forgetType('INTP-A', 'zh-CN', 0, PersonalityProfile::SCALE_CODE_MBTI)) {
                throw new RuntimeException('INTP-A public detail/SEO cache invalidation failed.');
            }

            return $this->receipt('promoted_live', $packageSha256, $activeRevision, 1, 0, 1, 1, true, $verified);
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function rollback(array $package, string $packageSha256, string $activeRevision): array
    {
        $this->assertPackage($package, $packageSha256);

        return DB::transaction(function () use ($packageSha256, $activeRevision): array {
            [$profile, $variant, $seoMeta] = $this->resolveAuthority(lock: true);
            $resolution = $this->overrideRevisions->resolve($profile, $variant, $seoMeta, lock: true);

            if ($resolution['status'] === MbtiSeoFieldOverrideRevisionService::STATUS_ROLLED_BACK
                && $this->isExactMarkerIdentity($resolution, $packageSha256)) {
                return $this->receipt('idempotent_rolled_back', $packageSha256, $activeRevision, 0, 1, 0, 0, false, $resolution);
            }
            if (! $this->isExactPromotedMarker($resolution, $packageSha256)) {
                throw new RuntimeException('Rollback requires the exact package promoted_live marker.');
            }

            $unchangedBefore = $this->unchangedAuthorityFingerprint($profile, $variant, $seoMeta);
            $seoMeta->seo_title = self::CURRENT_TITLE;
            $seoMeta->save();
            $marker = $this->appendMarker(
                $variant,
                MbtiSeoFieldOverrideRevisionService::STATUS_ROLLED_BACK,
                $packageSha256,
            );

            $profile->refresh();
            $variant->refresh();
            $seoMeta->refresh();
            if (! hash_equals($unchangedBefore, $this->unchangedAuthorityFingerprint($profile, $variant, $seoMeta))) {
                throw new RuntimeException('A field outside seo_title changed during production rollback.');
            }
            $verified = $this->overrideRevisions->resolve($profile, $variant, $seoMeta, lock: true);
            if ((int) $verified['marker_revision_id'] !== (int) $marker->id) {
                throw new RuntimeException('Rolled-back override marker readback mismatch.');
            }
            if (! $this->publicReadModelCache->forgetType('INTP-A', 'zh-CN', 0, PersonalityProfile::SCALE_CODE_MBTI)) {
                throw new RuntimeException('INTP-A public detail/SEO cache invalidation failed during rollback.');
            }

            return $this->receipt('rolled_back', $packageSha256, $activeRevision, 0, 0, 1, 1, true, $verified);
        }, 3);
    }

    /** @param array<string,mixed> $package */
    private function assertPackage(array $package, string $packageSha256): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $packageSha256) !== 1) {
            throw new RuntimeException('Promotion package SHA-256 is invalid.');
        }

        $expected = [
            'schema_version' => self::SCHEMA_VERSION,
            'promotion_id' => self::PROMOTION_ID,
            'target' => $this->target(),
            'change' => [
                'field' => MbtiSeoFieldOverrideRevisionService::FIELD_SEO_TITLE,
                'current' => self::CURRENT_TITLE,
                'proposed' => self::PROPOSED_TITLE,
            ],
            'staging_evidence' => [
                'experiment_package_sha256' => self::EXPERIMENT_PACKAGE_SHA256,
                'workflow_run_id' => self::STAGING_RUN_ID,
                'workflow_receipt_sha256' => self::STAGING_RECEIPT_SHA256,
                'revision_snapshot_sha256' => self::STAGING_REVISION_SNAPSHOT_SHA256,
            ],
            'allowed_public_projection_changes' => [
                'seo_meta.seo_title',
                'seo_surface_v1.title',
                'seo_surface_v1.metadata_fingerprint',
                'mbti_public_projection_v1.seo.title',
            ],
            'production_baseline' => $this->baselineContract(),
        ];
        foreach ($expected as $key => $value) {
            if (($package[$key] ?? null) !== $value) {
                throw new RuntimeException('Promotion package contract mismatch at '.$key.'.');
            }
        }

        $negativeGuarantees = $package['negative_guarantees'] ?? null;
        $expectedNegativeGuarantees = [
            'description_change',
            'h1_change',
            'content_change',
            'faq_change',
            'internal_link_change',
            'og_twitter_change',
            'canonical_change',
            'robots_change',
            'json_ld_change',
            'publication_change',
            'indexability_change',
            'discoverability_change',
            'sitemap_change',
            'llms_change',
            'search_channel_change',
        ];
        if (! is_array($negativeGuarantees) || array_keys($negativeGuarantees) !== $expectedNegativeGuarantees
            || array_filter($negativeGuarantees, static fn (mixed $value): bool => $value !== false) !== []) {
            throw new RuntimeException('Promotion package negative guarantees must all remain false.');
        }
        if (data_get($package, 'rollback_policy.automatic_repromotion_after_rollback') !== false) {
            throw new RuntimeException('Promotion package must prohibit automatic re-promotion after rollback.');
        }
    }

    /** @return array{PersonalityProfile,PersonalityProfileVariant,PersonalityProfileVariantSeoMeta} */
    private function resolveAuthority(bool $lock = false): array
    {
        $profileQuery = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')->where('type_code', 'INTP');
        $profiles = ($lock ? $profileQuery->lockForUpdate() : $profileQuery)->limit(2)->get();
        if ($profiles->count() !== 1 || ! $profiles->first() instanceof PersonalityProfile) {
            throw new RuntimeException('Unique production INTP profile authority was not found.');
        }
        /** @var PersonalityProfile $profile */
        $profile = $profiles->first();

        $variantQuery = PersonalityProfileVariant::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'INTP-A');
        $variants = ($lock ? $variantQuery->lockForUpdate() : $variantQuery)->limit(2)->get();
        if ($variants->count() !== 1 || ! $variants->first() instanceof PersonalityProfileVariant) {
            throw new RuntimeException('Unique production INTP-A variant authority was not found.');
        }
        /** @var PersonalityProfileVariant $variant */
        $variant = $variants->first();

        $seoQuery = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('personality_profile_variant_id', (int) $variant->id);
        $seoRows = ($lock ? $seoQuery->lockForUpdate() : $seoQuery)->limit(2)->get();
        if ($seoRows->count() !== 1 || ! $seoRows->first() instanceof PersonalityProfileVariantSeoMeta) {
            throw new RuntimeException('Unique production INTP-A SEO authority was not found.');
        }
        /** @var PersonalityProfileVariantSeoMeta $seoMeta */
        $seoMeta = $seoRows->first();

        return [$profile, $variant, $seoMeta];
    }

    /** @param array<string,mixed> $package */
    private function assertBaseline(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
        array $package,
    ): void {
        $actual = [
            'profile' => [
                'title' => (string) $profile->title,
                'status' => (string) $profile->status,
                'is_public' => (bool) $profile->is_public,
                'is_indexable' => (bool) $profile->is_indexable,
            ],
            'variant' => ['is_published' => (bool) $variant->is_published],
            'seo_meta' => [
                'seo_title' => $seoMeta->seo_title,
                'seo_description' => $seoMeta->seo_description,
                'canonical_url' => $seoMeta->canonical_url,
                'og_title' => $seoMeta->og_title,
                'og_description' => $seoMeta->og_description,
                'og_image_url' => $seoMeta->og_image_url,
                'twitter_title' => $seoMeta->twitter_title,
                'twitter_description' => $seoMeta->twitter_description,
                'twitter_image_url' => $seoMeta->twitter_image_url,
                'robots' => $seoMeta->robots,
                'jsonld_overrides_json' => $seoMeta->jsonld_overrides_json,
            ],
        ];
        if ($actual !== $this->baselineContract() || $actual !== ($package['production_baseline'] ?? null)) {
            throw new RuntimeException('Production authority baseline drifted; refusing the promotion.');
        }
    }

    /** @return array<string,mixed> */
    private function baselineContract(): array
    {
        return [
            'profile' => [
                'title' => 'INTP - 逻辑学家',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
            ],
            'variant' => ['is_published' => true],
            'seo_meta' => [
                'seo_title' => self::CURRENT_TITLE,
                'seo_description' => self::CURRENT_DESCRIPTION,
                'canonical_url' => 'https://fermatmind.com'.self::TARGET_ROUTE,
                'og_title' => self::CURRENT_TITLE,
                'og_description' => self::CURRENT_DESCRIPTION,
                'og_image_url' => null,
                'twitter_title' => self::CURRENT_TITLE,
                'twitter_description' => self::CURRENT_DESCRIPTION,
                'twitter_image_url' => null,
                'robots' => 'index,follow',
                'jsonld_overrides_json' => [
                    'url' => 'https://fermatmind.com'.self::TARGET_ROUTE,
                    'name' => 'INTP-A 人格特点',
                    'description' => self::CURRENT_DESCRIPTION,
                ],
            ],
        ];
    }

    private function unchangedAuthorityFingerprint(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
    ): string {
        $profile->load(['sections' => static fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        $variant->load(['sections' => static fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        $seo = $seoMeta->getAttributes();
        unset($seo['seo_title'], $seo['updated_at']);
        $payload = [
            'profile' => $profile->getAttributes(),
            'variant' => $variant->getAttributes(),
            'seo_meta_except_title_and_updated_at' => $seo,
            'profile_sections' => $profile->sections->map->getAttributes()->values()->all(),
            'variant_sections' => $variant->sections->map->getAttributes()->values()->all(),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to fingerprint the protected production authority.');
        }

        return hash('sha256', $encoded);
    }

    private function appendMarker(
        PersonalityProfileVariant $variant,
        string $status,
        string $packageSha256,
    ): PersonalityProfileVariantRevision {
        $revisionNo = ((int) PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $variant->id)
            ->max('revision_no')) + 1;
        $snapshot = $this->overrideRevisions->markerSnapshot(
            $status,
            self::PROMOTION_ID,
            $packageSha256,
            $this->target(),
            self::CURRENT_TITLE,
            self::PROPOSED_TITLE,
        );

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => $revisionNo,
            'snapshot_json' => $snapshot,
            'note' => self::PROMOTION_ID.':'.$status.':'.$packageSha256,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $resolution */
    private function isExactPromotedMarker(array $resolution, string $packageSha256): bool
    {
        return $resolution['status'] === MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE
            && $this->isExactMarkerIdentity($resolution, $packageSha256);
    }

    /** @param array<string,mixed> $resolution */
    private function isExactMarkerIdentity(array $resolution, string $packageSha256): bool
    {
        return $resolution['promotion_id'] === self::PROMOTION_ID
            && $resolution['package_sha256'] === $packageSha256;
    }

    /** @param array<string,mixed> $resolution */
    private function assertNoConflictingMarker(array $resolution, string $packageSha256): void
    {
        if ($resolution['status'] === 'none') {
            return;
        }
        if ($resolution['status'] === MbtiSeoFieldOverrideRevisionService::STATUS_ROLLED_BACK
            && $this->isExactMarkerIdentity($resolution, $packageSha256)) {
            throw new RuntimeException('This exact promotion package was rolled back and cannot be promoted automatically again.');
        }

        throw new RuntimeException('A conflicting MBTI SEO field override marker already owns the target.');
    }

    /** @return array{org_id:int,framework:string,locale:string,runtime_type_code:string,route:string} */
    private function target(): array
    {
        return [
            'org_id' => 0,
            'framework' => 'MBTI',
            'locale' => 'zh-CN',
            'runtime_type_code' => 'INTP-A',
            'route' => self::TARGET_ROUTE,
        ];
    }

    /**
     * @param  array<string,mixed>  $resolution
     * @return array<string,mixed>
     */
    private function receipt(
        string $status,
        string $packageSha256,
        string $activeRevision,
        int $seoTitleChanges,
        int $idempotentCount,
        int $auditRevisionCreatedCount,
        int $cacheInvalidations,
        bool $writesCommitted,
        array $resolution,
    ): array {
        return [
            'schema_version' => 'personality.mbti-seo-title-production-promotion-receipt.v1',
            'ok' => true,
            'status' => $status,
            'active_revision' => $activeRevision,
            'package_sha256' => $packageSha256,
            'target' => $this->target(),
            'seo_title_changes' => $seoTitleChanges,
            'idempotent_count' => $idempotentCount,
            'audit_revision_created_count' => $auditRevisionCreatedCount,
            'other_seo_field_changes' => 0,
            'live_projection_forbidden_changes' => 0,
            'cache_invalidations' => $cacheInvalidations,
            'writes_committed' => $writesCommitted,
            'marker_revision_id' => $resolution['marker_revision_id'],
            'marker_snapshot_sha256' => $resolution['marker_snapshot_sha256'],
            'publication_changes' => 0,
            'indexability_changes' => 0,
            'discoverability_changes' => 0,
            'search_changes' => 0,
            'errors' => [],
        ];
    }
}
