<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateContextArtifactRow
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly ?string $latestIndexState = null,
        public readonly ?string $publicFacingState = null,
        public readonly ?bool $indexEligible = null,
        public readonly ?string $changedAt = null,
        public readonly array $reasonCodes = [],
        public readonly array $evidence = [],
        public readonly array $raw = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertListOfStrings($this->reasonCodes, 'reason_codes');
        self::assertMap($this->evidence, 'evidence');
        self::assertMap($this->raw, 'raw');

        foreach ([
            'latest_index_state' => $this->latestIndexState,
            'public_facing_state' => $this->publicFacingState,
            'changed_at' => $this->changedAt,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }
    }

    /**
     * @return array{canonical_slug: string, latest_index_state: string|null, public_facing_state: string|null, index_eligible: bool|null, changed_at: string|null, reason_codes: list<string>, evidence: array<string, mixed>, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'latest_index_state' => $this->latestIndexState,
            'public_facing_state' => $this->publicFacingState,
            'index_eligible' => $this->indexEligible,
            'changed_at' => $this->changedAt,
            'reason_codes' => $this->reasonCodes,
            'evidence' => $this->evidence,
            'raw' => $this->raw,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career index-state context artifact row requires non-empty [%s].', $key));
        }
    }

    private static function assertMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career index-state context artifact row [%s] must be an object map.', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career index-state context artifact row [%s] must be a list.', $key));
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career index-state context artifact row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
