<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSeoMeta;
use RuntimeException;

final class MbtiSeoFieldOverrideRevisionService
{
    public const SCHEMA_VERSION = 'personality.mbti-seo-field-override.v1';

    public const FIELD_SEO_TITLE = 'personality_profile_variant_seo_meta.seo_title';

    public const STATUS_PROMOTED_LIVE = 'promoted_live';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    /**
     * @return array{status:string,protected_fields:list<string>,marker_revision_id:int|null,marker_snapshot_sha256:string|null}
     */
    public function resolve(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
        bool $lock = false,
    ): array {
        $query = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $variant->id)
            ->orderByDesc('revision_no')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        foreach ($query->get() as $revision) {
            if (! $revision instanceof PersonalityProfileVariantRevision) {
                continue;
            }

            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            if (! self::isOverrideMarker($snapshot)) {
                continue;
            }

            $this->assertMarker($snapshot, $profile, $variant, $seoMeta);
            $status = (string) $snapshot['status'];

            return [
                'status' => $status,
                'protected_fields' => $status === self::STATUS_PROMOTED_LIVE ? ['seo_title'] : [],
                'marker_revision_id' => (int) $revision->id,
                'marker_snapshot_sha256' => (string) $snapshot['snapshot_sha256'],
            ];
        }

        return [
            'status' => 'none',
            'protected_fields' => [],
            'marker_revision_id' => null,
            'marker_snapshot_sha256' => null,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public static function isOverrideMarker(array $snapshot): bool
    {
        return ($snapshot['schema_version'] ?? null) === self::SCHEMA_VERSION;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function snapshotSha256(array $snapshot): string
    {
        unset($snapshot['snapshot_sha256']);
        $encoded = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode the MBTI SEO field override marker.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertMarker(
        array $snapshot,
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
    ): void {
        $storedSha = (string) ($snapshot['snapshot_sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $storedSha) !== 1
            || ! hash_equals($this->snapshotSha256($snapshot), $storedSha)) {
            throw new RuntimeException('MBTI SEO field override marker checksum mismatch.');
        }

        $status = (string) ($snapshot['status'] ?? '');
        if (! in_array($status, [self::STATUS_PROMOTED_LIVE, self::STATUS_ROLLED_BACK], true)) {
            throw new RuntimeException('MBTI SEO field override marker status is unsupported.');
        }

        $promotionId = (string) ($snapshot['promotion_id'] ?? '');
        $packageSha = (string) ($snapshot['package_sha256'] ?? '');
        if ($promotionId === '' || preg_match('/^[a-f0-9]{64}$/', $packageSha) !== 1) {
            throw new RuntimeException('MBTI SEO field override marker identity is invalid.');
        }

        $expectedTarget = [
            'org_id' => 0,
            'framework' => PersonalityProfile::SCALE_CODE_MBTI,
            'locale' => (string) $profile->locale,
            'runtime_type_code' => (string) $variant->runtime_type_code,
            'route' => '/'.($profile->locale === 'zh-CN' ? 'zh' : 'en').'/personality/'.strtolower((string) $variant->runtime_type_code),
        ];
        if (($snapshot['target'] ?? null) !== $expectedTarget) {
            throw new RuntimeException('MBTI SEO field override marker target identity mismatch.');
        }

        $change = $snapshot['change'] ?? null;
        if (! is_array($change)
            || array_keys($change) !== ['field', 'previous', 'promoted', 'live_value']
            || ($change['field'] ?? null) !== self::FIELD_SEO_TITLE) {
            throw new RuntimeException('MBTI SEO field override marker field contract is unsupported.');
        }

        $expectedLive = $status === self::STATUS_PROMOTED_LIVE
            ? (string) $change['promoted']
            : (string) $change['previous'];
        if ($expectedLive === ''
            || (string) $change['live_value'] !== $expectedLive
            || (string) $seoMeta->seo_title !== $expectedLive) {
            throw new RuntimeException('MBTI SEO field override marker does not match the live SEO title.');
        }
    }
}
