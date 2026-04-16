<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerAssetBatchManifest
{
    /**
     * @param  list<CareerAssetBatchManifestMember>  $members
     */
    public function __construct(
        public readonly string $batchKind,
        public readonly string $batchVersion,
        public readonly string $batchKey,
        public readonly string $scope,
        public readonly int $memberCount,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_kind' => $this->batchKind,
            'batch_version' => $this->batchVersion,
            'batch_key' => $this->batchKey,
            'scope' => $this->scope,
            'member_count' => $this->memberCount,
            'members' => array_map(
                static fn (CareerAssetBatchManifestMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
