<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

final class SearchChannelQueueEligibilityResult
{
    /**
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly string $eligibilityState,
        public readonly string $claimBoundaryState,
        public readonly array $reasonCodes,
    ) {}
}
