<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\RouteMatrix;

final readonly class BigFiveV2RouteMatrixRow
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        public string $combinationKey,
        public string $profileFamily,
        public string $profileKey,
        public string $interpretationScope,
        public array $data,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
