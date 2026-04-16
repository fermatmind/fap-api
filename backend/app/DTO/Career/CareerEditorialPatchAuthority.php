<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerEditorialPatchAuthority
{
    /**
     * @param  list<array<string, mixed>>  $patches
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $patches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => 'career_editorial_patch_authority',
            'authority_version' => 'career.editorial_patch.authority.v1',
            'scope' => $this->scope,
            'count' => count($this->patches),
            'patches' => $this->patches,
        ];
    }
}
