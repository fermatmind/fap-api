<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerProgressiveReadinessSelectionIssue
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = 'medium',
        public readonly array $evidence = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'evidence' => $this->evidence,
        ];
    }
}
