<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\RouteMatrix;

final readonly class BigFiveV2RouteMatrixParseResult
{
    /**
     * @param  array<string,BigFiveV2RouteMatrixRow>  $rowsByCombinationKey
     * @param  array<string,int>  $rowCountsByShard
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $matrixPath,
        public array $rowsByCombinationKey,
        public array $rowCountsByShard,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function rowCount(): int
    {
        return count($this->rowsByCombinationKey);
    }

    public function row(string $combinationKey): ?BigFiveV2RouteMatrixRow
    {
        return $this->rowsByCombinationKey[$combinationKey] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'matrix_path' => $this->matrixPath,
            'valid' => $this->isValid(),
            'row_count' => $this->rowCount(),
            'row_counts_by_shard' => $this->rowCountsByShard,
            'errors' => $this->errors,
        ];
    }
}
