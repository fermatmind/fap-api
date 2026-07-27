<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Services\Career\CareerDirectoryAuthorityService;

/**
 * Fail-closed, read-only search-entry classification over existing Career authority.
 *
 * @review-surface career_trust_manifest
 */
final class CareerSearchEntryTierResolver
{
    public const SCHEMA_VERSION = 'career.search_entry_authority.v1';

    public const CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE = 'tier_a_controlled_search_entry_candidate';

    public const TIER_STABLE = 'stable';

    public const TIER_APPROVED_CANDIDATE = 'approved_candidate';

    public const TIER_INELIGIBLE = 'ineligible';

    /**
     * @return array{
     *   schema_version:string,
     *   search_entry_tier:string,
     *   search_entry_eligible:bool,
     *   public_visibility:bool,
     *   robots_indexable:bool,
     *   review_state:string,
     *   publish_track:string|null,
     *   content_quality_tier:string,
     *   held_slug:bool,
     *   reason_codes:list<string>
     * }
     */
    public function resolve(
        string $slug,
        bool $publicVisibility,
        bool $robotsIndexable,
        string $reviewState,
        ?string $lastReviewedAt,
        ?string $publishTrack,
        ?string $contentQualityTier,
    ): array {
        $slug = strtolower(trim($slug));
        $reviewState = strtolower(trim($reviewState));
        $publishTrack = $this->normalizeNullable($publishTrack);
        $contentQualityTier = $this->normalizeNullable($contentQualityTier);
        $reviewEvidenceCurrent = $reviewState === 'approved'
            && $this->normalizeNullable($lastReviewedAt) !== null;
        $heldSlug = in_array($slug, CareerDirectoryAuthorityService::excludedSlugs(), true);

        $reasons = [];
        if ($slug === '') {
            $reasons[] = 'missing_canonical_slug';
        }
        if ($heldSlug) {
            $reasons[] = 'held_slug';
        }
        if (! $publicVisibility) {
            $reasons[] = 'not_publicly_visible';
        }
        if (! $robotsIndexable) {
            $reasons[] = 'robots_not_indexable';
        }
        if (! $reviewEvidenceCurrent) {
            $reasons[] = 'reviewer_evidence_not_current';
        }
        if ($contentQualityTier === null) {
            $reasons[] = 'content_quality_tier_unknown';
        } elseif ($contentQualityTier !== self::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE) {
            $reasons[] = 'content_quality_tier_ineligible';
        }

        if ($publishTrack === null) {
            $reasons[] = 'publish_track_unknown';
        } elseif ($publishTrack === 'review_needed') {
            $reasons[] = 'publish_track_review_needed';
        } elseif ($publishTrack === 'hold') {
            $reasons[] = 'publish_track_hold';
        } elseif (! in_array($publishTrack, ['stable', 'candidate'], true)) {
            $reasons[] = 'publish_track_unsupported';
        }

        $eligible = $reasons === [];
        $tier = match ($eligible ? $publishTrack : null) {
            'stable' => self::TIER_STABLE,
            'candidate' => self::TIER_APPROVED_CANDIDATE,
            default => self::TIER_INELIGIBLE,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'search_entry_tier' => $tier,
            'search_entry_eligible' => $eligible,
            'public_visibility' => $publicVisibility,
            'robots_indexable' => $robotsIndexable,
            'review_state' => $reviewState !== '' ? $reviewState : 'unknown',
            'publish_track' => $publishTrack,
            'content_quality_tier' => $contentQualityTier ?? 'unknown',
            'held_slug' => $heldSlug,
            'reason_codes' => $reasons,
        ];
    }

    private function normalizeNullable(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : null;
    }
}
