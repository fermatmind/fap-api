<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class ClaimReasonCode
{
    public const INDEX_STATE_RESTRICTED = 'index_state_restricted';

    public const STRONG_CLAIM_BLOCKED = 'strong_claim_blocked';

    public const SALARY_COMPARISON_BLOCKED = 'salary_comparison_blocked';

    public const AI_STRATEGY_BLOCKED = 'ai_strategy_blocked';

    public const TRANSITION_RECOMMENDATION_BLOCKED = 'transition_recommendation_blocked';

    public const CROSS_MARKET_PAY_BLOCKED = 'cross_market_pay_copy_blocked';

    public const REVIEW_PENDING = 'review_pending';

    public const LOW_QUALITY_CONFIDENCE = 'low_quality_confidence';

    public const EDITORIAL_PATCH_REQUIRED = 'editorial_patch_required';

    public const MISSING_AI_EXPOSURE = 'missing_ai_exposure';

    public const MISSING_MEDIAN_PAY = 'missing_median_pay';

    public const CROSS_MARKET_MISMATCH = 'cross_market_mismatch';

    public const MISSING_TRUST_MANIFEST = 'missing_trust_manifest';

    public const MISSING_SOURCE_TRACE_EVIDENCE = 'missing_source_trace_evidence';

    public const LOW_CROSSWALK_CONFIDENCE = 'low_crosswalk_confidence';
}
