<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetAuthority
{
    /**
     * @param  list<CareerFirstWaveDatasetMember>  $members
     * @param  array<string, mixed>  $aggregate
     */
    public function __construct(
        public readonly CareerFirstWaveDatasetDescriptor $descriptor,
        public readonly array $aggregate,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => 'career_first_wave_dataset_authority',
            'authority_version' => 'career.dataset_authority.first_wave.v1',
            'descriptor' => $this->descriptor->toArray(),
            'aggregate' => $this->aggregate,
            'members' => array_map(
                static fn (CareerFirstWaveDatasetMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
