<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerOccupationEntityInventoryRow
{
    /**
     * @param  list<string>  $missingEntityFields
     * @param  list<mixed>  $evidence
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly bool $occupationExists,
        public readonly ?string $occupationId,
        public readonly bool $duplicateInputSlug,
        public readonly bool $duplicateEntitySlug,
        public readonly CareerCanonicalEligibilityLayerStatus $entityStatus,
        public readonly array $missingEntityFields = [],
        public readonly ?string $sourceScope = null,
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertListOfStrings($this->missingEntityFields, 'missing_entity_fields');
        self::assertList($this->evidence, 'evidence');

        if ($this->occupationId !== null) {
            self::assertNonEmptyString($this->occupationId, 'occupation_id');
        }

        if ($this->sourceScope !== null) {
            self::assertNonEmptyString($this->sourceScope, 'source_scope');
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career occupation entity inventory row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerOccupationEntityInventoryIssue) {
                throw new InvalidArgumentException('Career occupation entity inventory row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array{canonical_slug: string, occupation_exists: bool, occupation_id: string|null, duplicate_input_slug: bool, duplicate_entity_slug: bool, entity_status: array<string, mixed>, missing_entity_fields: list<string>, source_scope: string|null, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'occupation_exists' => $this->occupationExists,
            'occupation_id' => $this->occupationId,
            'duplicate_input_slug' => $this->duplicateInputSlug,
            'duplicate_entity_slug' => $this->duplicateEntitySlug,
            'entity_status' => $this->entityStatus->toArray(),
            'missing_entity_fields' => $this->missingEntityFields,
            'source_scope' => $this->sourceScope,
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerOccupationEntityInventoryIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career occupation entity inventory row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career occupation entity inventory row [%s] must be a list.', $key));
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
                throw new InvalidArgumentException(sprintf('Career occupation entity inventory row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
