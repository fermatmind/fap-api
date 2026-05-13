<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerEntityContextArtifact
{
    /**
     * @param  array<string, mixed>  $source
     * @param  list<CareerEntityContextArtifactRow>  $rows
     * @param  list<CareerEntityContextArtifactIssue>  $issues
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly ?string $schemaVersion,
        public readonly array $source,
        public readonly array $rows,
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->sourcePath, 'source_path');
        self::assertMap($this->source, 'source');
        self::assertRows($this->rows);
        self::assertIssues($this->issues);

        if ($this->schemaVersion !== null) {
            self::assertNonEmptyString($this->schemaVersion, 'schema_version');
        }
    }

    /**
     * @return array<string, CareerEntityContextArtifactRow>
     */
    public function rowsBySlug(): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            $rows[$row->canonicalSlug] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, list<CareerEntityContextArtifactIssue>>
     */
    public function issuesBySlug(): array
    {
        $issues = [];
        foreach ($this->issues as $issue) {
            if ($issue->canonicalSlug === null) {
                continue;
            }

            $issues[$issue->canonicalSlug] ??= [];
            $issues[$issue->canonicalSlug][] = $issue;
        }

        return $issues;
    }

    /**
     * @return list<CareerEntityContextArtifactIssue>
     */
    public function globalIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (CareerEntityContextArtifactIssue $issue): bool => $issue->canonicalSlug === null
        ));
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
     * @return array{source_path: string, schema_version: string|null, source: array<string, mixed>, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'schema_version' => $this->schemaVersion,
            'source' => $this->source,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerEntityContextArtifactRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerEntityContextArtifactIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career entity context artifact requires non-empty [%s].', $key));
        }
    }

    private static function assertMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career entity context artifact [%s] must be an object map.', $key));
        }
    }

    /**
     * @param  list<CareerEntityContextArtifactRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career entity context artifact rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerEntityContextArtifactRow) {
                throw new InvalidArgumentException('Career entity context artifact rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerEntityContextArtifactIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career entity context artifact issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerEntityContextArtifactIssue) {
                throw new InvalidArgumentException('Career entity context artifact issues must contain issue DTOs.');
            }
        }
    }
}
