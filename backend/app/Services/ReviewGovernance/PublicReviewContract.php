<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * @review-surface article
 * @review-surface content_page
 * @review-surface content_page_external_evidence_gate
 * @review-surface support_article
 * @review-surface interpretation_guide
 * @review-surface research_report
 * @review-surface personality_public_content_asset
 * @review-surface mbti_cross_type_comparison_authority
 * @review-surface riasec_content_release_review
 * @review-surface career_trust_manifest
 * @review-surface career_occupation_directory_review
 * @review-surface daily_giving_operator_approval
 */
final class PublicReviewContract
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{review_state:string,last_reviewed_at:string|null,reviewer:null}
     */
    public function project(mixed $state, mixed $lastReviewedAt = null): array
    {
        return [
            'review_state' => $this->normalizeState($state),
            'last_reviewed_at' => $this->normalizeTimestamp($lastReviewedAt),
            'reviewer' => null,
        ];
    }

    public function normalizeState(mixed $state): string
    {
        if (! is_scalar($state)) {
            return self::UNKNOWN;
        }

        $normalized = strtolower(trim((string) $state));
        if ($normalized === '') {
            return self::UNKNOWN;
        }

        if (in_array($normalized, [
            'approved',
            'approved_for_production',
            'approved_for_staging',
            'agent_promoted_content_ready',
            'human_review_approved',
            'human_review_completed',
            'human_reviewed',
            'content_reviewed',
            'operator_approved',
            'operator_approved_content_ready',
            'operator_approved_published',
            'operator_content_only_release',
            'operator_v3_release',
            'published',
            'published_no_llms',
            'seo_discoverability_released',
            'reviewed',
            'verified',
        ], true)) {
            return self::APPROVED;
        }

        if (in_array($normalized, [
            'changes_required',
            'changes_requested',
            'denied',
            'rejected',
        ], true)) {
            return self::REJECTED;
        }

        if (in_array($normalized, [
            'draft',
            'human_review',
            'claim_review',
            'cms_import_draft_pending_review',
            'company_review',
            'content_review',
            'in_review',
            'legal_review',
            'machine_draft',
            'not_reviewed',
            'owner_review',
            'pending',
            'pending_human_review',
            'pending_manual_review',
            'product_or_policy_review',
            'review_needed',
            'research_review',
            'review_required',
            'science_review',
            'science_or_product_review',
            'source',
            'support_review',
            'unassigned',
        ], true)) {
            return self::PENDING;
        }

        return self::UNKNOWN;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc()->toISOString();
            }

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return CarbonImmutable::parse($value)->utc()->toISOString();
        } catch (Throwable) {
            return null;
        }
    }
}
