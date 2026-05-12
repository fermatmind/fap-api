<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBatchLiveAcceptanceV2Result
{
    /**
     * @param  list<CareerBatchLiveAcceptanceV2Row>  $rows
     * @param  list<CareerBatchLiveAcceptanceV2Issue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $accepted,
        public readonly string $batchId,
        public readonly int $expectedRows,
        public readonly int $foundProjectionRows,
        public readonly int $foundTruthRows,
        public readonly int $releaseGatePassCount,
        public readonly int $releaseGateBlockedCount,
        public readonly string $surfaceEquality,
        public readonly int $mismatchCount,
        public readonly int $unverifiedSurfaceCount,
        public readonly bool $readOnly,
        public readonly bool $writesDatabase,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        if (trim($this->batchId) === '') {
            throw new InvalidArgumentException('Batch live acceptance v2 result requires batch_id.');
        }
        foreach (['expected_rows' => $this->expectedRows, 'found_projection_rows' => $this->foundProjectionRows, 'found_truth_rows' => $this->foundTruthRows, 'release_gate_pass_count' => $this->releaseGatePassCount, 'release_gate_blocked_count' => $this->releaseGateBlockedCount, 'mismatch_count' => $this->mismatchCount, 'unverified_surface_count' => $this->unverifiedSurfaceCount] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Batch live acceptance v2 [%s] must be non-negative.', $key));
            }
        }
        if (! array_is_list($this->rows) || ! array_is_list($this->issues) || ! array_is_list($this->sidecars)) {
            throw new InvalidArgumentException('Batch live acceptance v2 rows, issues, and sidecars must be lists.');
        }
    }

    /**
     * @return array<string, int>
     */
    public function byReason(): array
    {
        $counts = [];
        foreach ($this->issues as $issue) {
            $counts[$issue->reason] = ($counts[$issue->reason] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'accepted' => $this->accepted,
            'batch_id' => $this->batchId,
            'expected_rows' => $this->expectedRows,
            'found_projection_rows' => $this->foundProjectionRows,
            'found_truth_rows' => $this->foundTruthRows,
            'release_gate' => [
                'pass' => $this->releaseGatePassCount,
                'blocked' => $this->releaseGateBlockedCount,
            ],
            'surfaces' => [
                'surface_equality' => $this->surfaceEquality,
                'mismatch_count' => $this->mismatchCount,
                'unverified_count' => $this->unverifiedSurfaceCount,
            ],
            'read_only' => $this->readOnly,
            'writes_database' => $this->writesDatabase,
            'by_reason' => $this->byReason(),
            'rows' => array_map(static fn (CareerBatchLiveAcceptanceV2Row $row): array => $row->toArray(), $this->rows),
            'issues' => array_map(static fn (CareerBatchLiveAcceptanceV2Issue $issue): array => $issue->toArray(), $this->issues),
            'sidecars' => array_map(static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(), $this->sidecars),
        ];
    }
}
