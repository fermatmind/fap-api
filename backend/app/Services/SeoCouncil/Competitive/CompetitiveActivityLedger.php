<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use InvalidArgumentException;

final class CompetitiveActivityLedger
{
    private const ACTIVITIES = [
        'runner_calls', 'model_calls', 'tool_calls', 'external_calls', 'cms_writes',
        'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    public function record(string $activity): void
    {
        if (! in_array($activity, self::ACTIVITIES, true)) {
            throw new InvalidArgumentException('UNKNOWN_COMPETITIVE_ACTIVITY');
        }
        $this->counts[$activity] = ($this->counts[$activity] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [...array_fill_keys(self::ACTIVITIES, 0), ...$this->counts];
    }
}
