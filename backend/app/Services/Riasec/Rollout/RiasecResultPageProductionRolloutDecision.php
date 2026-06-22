<?php

declare(strict_types=1);

namespace App\Services\Riasec\Rollout;

final class RiasecResultPageProductionRolloutDecision
{
    /**
     * @param  array<string,string|int>  $context
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?string $matchedBy,
        public readonly array $context,
        public readonly array $errors = [],
    ) {}
}
