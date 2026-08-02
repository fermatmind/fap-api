<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final readonly class RiasecResultPageProductionRolloutDecision
{
    /**
     * @param  array<string,string|int>  $context
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?string $matchedBy,
        public array $context,
        public array $errors = [],
    ) {}
}
