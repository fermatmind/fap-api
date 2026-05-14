<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class Career80TotalLiveAcceptanceResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
