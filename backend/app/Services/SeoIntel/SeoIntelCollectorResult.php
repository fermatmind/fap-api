<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SeoIntelCollectorResult
{
    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $collector,
        public readonly string $status,
        public readonly bool $dryRun,
        public readonly bool $writesAttempted,
        public readonly bool $writesCommitted,
        public readonly bool $externalCallsAttempted,
        public readonly int $itemsSeen = 0,
        public readonly array $issues = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array{
     *     collector: string,
     *     status: string,
     *     dry_run: bool,
     *     writes_attempted: bool,
     *     writes_committed: bool,
     *     external_calls_attempted: bool,
     *     items_seen: int,
     *     issues: list<string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'collector' => $this->collector,
            'status' => $this->status,
            'dry_run' => $this->dryRun,
            'writes_attempted' => $this->writesAttempted,
            'writes_committed' => $this->writesCommitted,
            'external_calls_attempted' => $this->externalCallsAttempted,
            'items_seen' => $this->itemsSeen,
            'issues' => $this->issues,
            'metadata' => $this->metadata,
        ];
    }
}
