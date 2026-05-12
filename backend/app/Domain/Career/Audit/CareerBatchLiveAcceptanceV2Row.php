<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBatchLiveAcceptanceV2Row
{
    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @param  list<CareerBatchLiveAcceptanceV2Issue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly string $status,
        public readonly bool $projectionFound,
        public readonly bool $truthFound,
        public readonly bool $releaseGatePass,
        public readonly string $surfaceStatus,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        foreach (['canonical_slug' => $this->canonicalSlug, 'locale' => $this->locale] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Batch live acceptance v2 row requires non-empty [%s].', $key));
            }
        }
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        CareerCanonicalEligibilityStatus::assertValid($this->surfaceStatus);
        if (! array_is_list($this->reasons) || ! array_is_list($this->evidence) || ! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Batch live acceptance v2 row reasons, evidence, and issues must be lists.');
        }
        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerBatchLiveAcceptanceV2Issue) {
                throw new InvalidArgumentException('Batch live acceptance v2 row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'status' => $this->status,
            'projection_found' => $this->projectionFound,
            'truth_found' => $this->truthFound,
            'release_gate_pass' => $this->releaseGatePass,
            'surface_status' => $this->surfaceStatus,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
            'issues' => array_map(static fn (CareerBatchLiveAcceptanceV2Issue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
