<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormSnapshot
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function hash(): string
    {
        return (string) ($this->payload['snapshot_hash'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->payload['snapshot_version'] ?? '');
    }
}
