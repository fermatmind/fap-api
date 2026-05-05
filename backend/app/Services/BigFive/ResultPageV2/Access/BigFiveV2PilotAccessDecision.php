<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Access;

final readonly class BigFiveV2PilotAccessDecision
{
    /**
     * @param  array<string,string>  $context
     */
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?string $matchedRule = null,
        public array $context = [],
    ) {}
}
