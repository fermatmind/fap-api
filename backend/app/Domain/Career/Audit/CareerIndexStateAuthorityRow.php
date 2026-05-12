<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateAuthorityRow
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<mixed>  $evidence
     * @param  list<CareerIndexStateAuthorityIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly ?string $occupationId,
        public readonly ?string $indexStateId,
        public readonly ?string $rawIndexState,
        public readonly ?string $publicIndexState,
        public readonly bool $indexEligible,
        public readonly ?string $changedAt,
        public readonly CareerCanonicalEligibilityLayerStatus $indexStatus,
        public readonly array $reasonCodes = [],
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertListOfStrings($this->reasonCodes, 'reason_codes');
        self::assertList($this->evidence, 'evidence');

        foreach (['occupation_id' => $this->occupationId, 'index_state_id' => $this->indexStateId, 'raw_index_state' => $this->rawIndexState, 'public_index_state' => $this->publicIndexState, 'changed_at' => $this->changedAt] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career index-state authority row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerIndexStateAuthorityIssue) {
                throw new InvalidArgumentException('Career index-state authority row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array{canonical_slug: string, occupation_id: string|null, index_state_id: string|null, raw_index_state: string|null, public_index_state: string|null, index_eligible: bool, changed_at: string|null, index_status: array<string, mixed>, reason_codes: list<string>, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'occupation_id' => $this->occupationId,
            'index_state_id' => $this->indexStateId,
            'raw_index_state' => $this->rawIndexState,
            'public_index_state' => $this->publicIndexState,
            'index_eligible' => $this->indexEligible,
            'changed_at' => $this->changedAt,
            'index_status' => $this->indexStatus->toArray(),
            'reason_codes' => $this->reasonCodes,
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerIndexStateAuthorityIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career index-state authority row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career index-state authority row [%s] must be a list.', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        self::assertList($value, $key);

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career index-state authority row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
