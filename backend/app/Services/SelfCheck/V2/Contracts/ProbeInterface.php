<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Contracts;

interface ProbeInterface
{
    public function name(): string;

    /**
     * @return array<string,mixed>
     */
    public function probe(bool $verbose = false): array;
}
