<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerPublicResolutionPlan
{
    /**
     * @param  list<CareerPublicResolutionPlanRow>  $rows
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly ?string $checksum,
        public readonly array $rows,
    ) {
        if (trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('Career public resolution plan source_path is required.');
        }

        if (! array_is_list($this->rows)) {
            throw new InvalidArgumentException('Career public resolution plan rows must be a list.');
        }

        foreach ($this->rows as $row) {
            if (! $row instanceof CareerPublicResolutionPlanRow) {
                throw new InvalidArgumentException('Career public resolution plan rows must contain row DTOs.');
            }
        }
    }

    public function foundRows(): int
    {
        return count($this->rows);
    }

    /**
     * @return array{source_path: string, checksum: string|null, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'checksum' => $this->checksum,
            'rows' => array_map(
                static fn (CareerPublicResolutionPlanRow $row): array => $row->toArray(),
                $this->rows
            ),
        ];
    }
}
