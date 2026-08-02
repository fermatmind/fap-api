<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

final class PromotionPhaseIdentity
{
    public static function idempotencyKey(PromotionContext $context, string $phase, PromotionTargetSet $targets): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson([
            'schema_version' => 'fermatmind.content_promotion_phase_identity.v2',
            'lane' => $context->lane,
            'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256,
            'phase' => $phase,
            'source_commit' => $context->sourceCommit,
            'target_fingerprint' => $targets->fingerprint(),
        ]));
    }
}
