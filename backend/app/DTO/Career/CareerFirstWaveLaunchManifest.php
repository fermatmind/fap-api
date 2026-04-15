<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchManifest
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, list<string>>  $groups
     * @param  list<CareerFirstWaveLaunchManifestMember>  $members
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
            'manifest_kind' => 'career_first_wave_launch_manifest',
            'manifest_version' => 'career.launch_manifest.first_wave.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'groups' => $this->groups,
            'members' => array_map(
                static fn (CareerFirstWaveLaunchManifestMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
