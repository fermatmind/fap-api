<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final readonly class CareerRuntimeCandidatePrepResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function planned(): bool
    {
        return ($this->payload['status'] ?? null) === 'planned';
    }
}
