<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityReport
{
    /**
     * @param  array<string, int>  $byReason
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly string $scope,
        public readonly int $expectedOccupations,
        public readonly int $auditedOccupations,
        public readonly int $eligibleCount,
        public readonly int $blockedCount,
        public readonly array $byReason = [],
        public readonly array $rows = [],
        public readonly array $sidecars = [],
    ) {
        self::assertReportStatus($this->status);
        CareerCanonicalEligibilityScope::assertValid($this->scope);
        self::assertNonNegativeInt($this->expectedOccupations, 'expected_occupations');
        self::assertNonNegativeInt($this->auditedOccupations, 'audited_occupations');
        self::assertNonNegativeInt($this->eligibleCount, 'eligible_count');
        self::assertNonNegativeInt($this->blockedCount, 'blocked_count');
        self::assertByReason($this->byReason);
        self::assertRows($this->rows);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            status: self::requiredString($value, 'status'),
            scope: self::requiredString($value, 'scope'),
            expectedOccupations: self::requiredInt($value, 'expected_occupations'),
            auditedOccupations: self::requiredInt($value, 'audited_occupations'),
            eligibleCount: self::requiredInt($value, 'eligible_count'),
            blockedCount: self::requiredInt($value, 'blocked_count'),
            byReason: self::optionalByReason($value, 'by_reason'),
            rows: self::optionalRows($value, 'rows'),
            sidecars: self::optionalSidecars($value, 'sidecars'),
        );
    }

    /**
     * @return array{status: string, scope: string, expected_occupations: int, audited_occupations: int, eligible_count: int, blocked_count: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'scope' => $this->scope,
            'expected_occupations' => $this->expectedOccupations,
            'audited_occupations' => $this->auditedOccupations,
            'eligible_count' => $this->eligibleCount,
            'blocked_count' => $this->blockedCount,
            'by_reason' => $this->byReason,
            'rows' => array_map(
                static fn (CareerCanonicalEligibilityAuditRow $row): array => $row->toArray(),
                $this->rows
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @return list<CareerCanonicalEligibilitySidecar>
     */
    public function sidecarsThatBlockTrain(): array
    {
        return array_values(array_filter(
            $this->sidecars,
            static fn (CareerCanonicalEligibilitySidecar $sidecar): bool => ! $sidecar->canContinueTrain()
        ));
    }

    public function canContinueTrain(): bool
    {
        return $this->sidecarsThatBlockTrain() === [];
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     * @return array<string, int>
     */
    public static function byReasonFromRows(array $rows): array
    {
        self::assertRows($rows);

        $counts = [];
        foreach ($rows as $row) {
            foreach ($row->reasons as $reason) {
                if (! is_string($reason) || trim($reason) === '') {
                    throw new InvalidArgumentException('Career canonical eligibility row reasons must contain non-empty strings.');
                }

                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    private static function assertReportStatus(string $value): void
    {
        if (! in_array($value, [
            CareerCanonicalEligibilityStatus::PASS,
            CareerCanonicalEligibilityStatus::FAIL,
            CareerCanonicalEligibilityStatus::BLOCKED,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility report status [%s].', $value));
        }
    }

    private static function assertNonNegativeInt(int $value, string $key): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility report [%s] must be non-negative.', $key));
        }
    }

    /**
     * @param  array<string, int>  $byReason
     */
    private static function assertByReason(array $byReason): void
    {
        if (array_is_list($byReason) && $byReason !== []) {
            throw new InvalidArgumentException('Career canonical eligibility report by_reason must be an object map.');
        }

        foreach ($byReason as $reason => $count) {
            if (! is_string($reason) || trim($reason) === '' || ! is_int($count) || $count < 0) {
                throw new InvalidArgumentException('Career canonical eligibility report by_reason must map non-empty reasons to non-negative integer counts.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career canonical eligibility report rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerCanonicalEligibilityAuditRow) {
                throw new InvalidArgumentException('Career canonical eligibility report rows must contain audit row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career canonical eligibility report sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career canonical eligibility report sidecars must contain sidecar DTOs.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (! array_key_exists($key, $value) || ! is_string($value[$key]) || trim($value[$key]) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility report requires non-empty [%s].', $key));
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredInt(array $value, string $key): int
    {
        if (! array_key_exists($key, $value) || ! is_int($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility report requires integer [%s].', $key));
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, int>
     */
    private static function optionalByReason(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key])) {
            throw new InvalidArgumentException('Career canonical eligibility report by_reason must be an object map.');
        }

        self::assertByReason($value[$key]);

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private static function optionalRows(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key]) || ! array_is_list($value[$key])) {
            throw new InvalidArgumentException('Career canonical eligibility report rows must be a list.');
        }

        return array_map(static function (mixed $row): CareerCanonicalEligibilityAuditRow {
            if ($row instanceof CareerCanonicalEligibilityAuditRow) {
                return $row;
            }

            if (! is_array($row)) {
                throw new InvalidArgumentException('Career canonical eligibility report row item must be an object.');
            }

            return CareerCanonicalEligibilityAuditRow::fromArray($row);
        }, $value[$key]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<CareerCanonicalEligibilitySidecar>
     */
    private static function optionalSidecars(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key]) || ! array_is_list($value[$key])) {
            throw new InvalidArgumentException('Career canonical eligibility report sidecars must be a list.');
        }

        return array_map(static function (mixed $sidecar): CareerCanonicalEligibilitySidecar {
            if ($sidecar instanceof CareerCanonicalEligibilitySidecar) {
                return $sidecar;
            }

            if (! is_array($sidecar)) {
                throw new InvalidArgumentException('Career canonical eligibility report sidecar item must be an object.');
            }

            return CareerCanonicalEligibilitySidecar::fromArray($sidecar);
        }, $value[$key]);
    }
}
