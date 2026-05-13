<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CandidateSelectionRow
{
    public const STATUS_READY = 'ready';

    public const STATUS_NEAR_ELIGIBLE = 'near_eligible';

    public const STATUS_EXCLUDED_HARD_BLOCKER = 'excluded_hard_blocker';

    /**
     * @param  list<string>  $locales
     * @param  list<string>  $reasons
     * @param  list<string>  $hardBlockers
     * @param  array<string, string>  $layerStatuses
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly int $rank,
        public readonly int $score,
        public readonly string $candidateStatus,
        public readonly bool $selected,
        public readonly bool $hardBlocked,
        public readonly int $passedLocaleCount,
        public readonly int $blockedLocaleCount,
        public readonly array $locales = [],
        public readonly array $reasons = [],
        public readonly array $hardBlockers = [],
        public readonly array $layerStatuses = [],
        public readonly array $evidence = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        if ($this->rank < 0 || $this->score < 0 || $this->passedLocaleCount < 0 || $this->blockedLocaleCount < 0) {
            throw new InvalidArgumentException('Career 80 candidate selection numeric fields must be non-negative.');
        }
        self::assertValidStatus($this->candidateStatus);
        self::assertListOfStrings($this->locales, 'locales');
        self::assertListOfStrings($this->reasons, 'reasons');
        self::assertListOfStrings($this->hardBlockers, 'hard_blockers');
        self::assertLayerStatuses($this->layerStatuses);
        self::assertList($this->evidence, 'evidence');
    }

    /**
     * @return array{canonical_slug: string, rank: int, score: int, candidate_status: string, selected: bool, hard_blocked: bool, passed_locale_count: int, blocked_locale_count: int, locales: list<string>, reasons: list<string>, hard_blockers: list<string>, layer_statuses: array<string, string>, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'rank' => $this->rank,
            'score' => $this->score,
            'candidate_status' => $this->candidateStatus,
            'selected' => $this->selected,
            'hard_blocked' => $this->hardBlocked,
            'passed_locale_count' => $this->passedLocaleCount,
            'blocked_locale_count' => $this->blockedLocaleCount,
            'locales' => $this->locales,
            'reasons' => $this->reasons,
            'hard_blockers' => $this->hardBlockers,
            'layer_statuses' => $this->layerStatuses,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_READY,
            self::STATUS_NEAR_ELIGIBLE,
            self::STATUS_EXCLUDED_HARD_BLOCKER,
        ];
    }

    private static function assertValidStatus(string $value): void
    {
        if (! in_array($value, self::statuses(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid Career 80 candidate selection status [%s].', $value));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 80 candidate selection row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 80 candidate selection row [%s] must be a list.', $key));
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
                throw new InvalidArgumentException(sprintf('Career 80 candidate selection row [%s] must contain non-empty strings.', $key));
            }
        }
    }

    /**
     * @param  array<string, string>  $value
     */
    private static function assertLayerStatuses(array $value): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException('Career 80 candidate selection layer_statuses must be an object map.');
        }

        foreach ($value as $layer => $status) {
            if (! is_string($layer) || trim($layer) === '' || ! is_string($status) || trim($status) === '') {
                throw new InvalidArgumentException('Career 80 candidate selection layer_statuses must map non-empty strings.');
            }
        }
    }
}
