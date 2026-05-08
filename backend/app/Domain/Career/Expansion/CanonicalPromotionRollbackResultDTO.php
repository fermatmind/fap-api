<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalPromotionRollbackResultDTO
{
    /**
     * @param  list<string>  $rollbackGroup
     * @param  list<array<string, mixed>>  $failures
     * @param  array<string, mixed>  $updatedManifest
     */
    public function __construct(
        public readonly string $status,
        public readonly string $strategy,
        public readonly string $targetState,
        public readonly array $rollbackGroup,
        public readonly array $updatedManifest,
        public readonly array $failures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'strategy' => $this->strategy,
            'target_state' => $this->targetState,
            'rollback_group' => $this->rollbackGroup,
            'updated_manifest' => $this->updatedManifest,
            'failures' => $this->failures,
            'read_only' => true,
            'writes_database' => false,
        ];
    }
}
