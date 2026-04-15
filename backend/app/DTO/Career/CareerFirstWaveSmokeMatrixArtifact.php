<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveSmokeMatrixArtifact
{
    /**
     * @param  list<array<string, mixed>>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => 'career_smoke_matrix',
            'artifact_version' => 'career.smoke_matrix.export.v1',
            'scope' => $this->scope,
            'members' => $this->members,
        ];
    }
}
