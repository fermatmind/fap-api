<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class PublishReasonCode
{
    public const MANIFEST_FIRST_WAVE_SELECTED = 'manifest_first_wave_selected';

    public const CROSSWALK_MODE_ALLOWED = 'crosswalk_mode_allowed';

    public const CROSSWALK_MODE_CANDIDATE_ONLY = 'crosswalk_mode_candidate_only';

    public const CROSSWALK_MODE_DISALLOWED = 'crosswalk_mode_disallowed';

    public const CONFIDENCE_READY = 'confidence_ready';

    public const CONFIDENCE_BORDERLINE = 'confidence_borderline';

    public const CONFIDENCE_TOO_LOW = 'confidence_too_low';

    public const REVIEWER_APPROVED = 'reviewer_approved';

    public const REVIEWER_PENDING = 'reviewer_pending';

    public const REVIEWER_BLOCKED = 'reviewer_blocked';

    public const INDEX_ELIGIBLE = 'index_eligible';

    public const INDEX_INELIGIBLE = 'index_ineligible';

    public const STRONG_CLAIM_ALLOWED = 'strong_claim_allowed';

    public const STRONG_CLAIM_BLOCKED = 'strong_claim_blocked';

    public const STABLE_PUBLISH_READY = 'stable_publish_ready';

    public const CANDIDATE_REVIEW_REQUIRED = 'candidate_review_required';

    public const HOLD_SCOPE_RESTRICTED = 'hold_scope_restricted';
}
