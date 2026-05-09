<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalBatchCloseoutResultDTO
{
    /**
     * @param  list<array<string, string>>  $promotedRows
     * @param  list<string>  $failedSlugs
     * @param  list<string>  $failureReasons
     * @param  list<string>  $rollbackGroup
     */
    public function __construct(
        public readonly string $batchId,
        public readonly array $rollbackGroup,
        public readonly array $promotedRows,
        public readonly int $releaseGatePassCount,
        public readonly int $releaseGateBlockedCount,
        public readonly array $failedSlugs,
        public readonly array $failureReasons,
        public readonly bool $closeoutAllowed,
        public readonly bool $rollbackRequired,
        public readonly bool $quarantineRequired,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'rollback_group' => $this->rollbackGroup,
            'promoted_rows' => $this->promotedRows,
            'release_gate_pass_count' => $this->releaseGatePassCount,
            'release_gate_blocked_count' => $this->releaseGateBlockedCount,
            'failed_slugs' => $this->failedSlugs,
            'failure_reasons' => $this->failureReasons,
            'closeout_allowed' => $this->closeoutAllowed,
            'rollback_required' => $this->rollbackRequired,
            'quarantine_required' => $this->quarantineRequired,
            'read_only' => true,
            'writes_database' => false,
        ];
    }
}
