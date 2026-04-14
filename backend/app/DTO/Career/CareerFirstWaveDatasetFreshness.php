<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetFreshness
{
    public function __construct(
        public readonly ?string $compiledAt,
        public readonly ?string $lastSubstantiveUpdateAt,
        public readonly ?string $manifestGeneratedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'compiled_at' => $this->compiledAt,
            'last_substantive_update_at' => $this->lastSubstantiveUpdateAt,
            'manifest_generated_at' => $this->manifestGeneratedAt,
        ];
    }
}
