<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchManifestArtifact
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, list<string>>  $groups
     * @param  list<array<string, mixed>>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $groups,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => 'career_launch_manifest',
            'artifact_version' => 'career.launch_manifest.export.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'groups' => $this->groups,
            'members' => $this->members,
        ];
    }
}
