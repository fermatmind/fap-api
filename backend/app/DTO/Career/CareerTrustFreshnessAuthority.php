<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerTrustFreshnessAuthority
{
    /**
     * @param  list<CareerTrustFreshnessMember>  $members
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
            'authority_kind' => 'career_trust_freshness_authority',
            'authority_version' => 'career.trust_freshness.v1',
            'scope' => $this->scope,
            'members' => array_map(
                static fn (CareerTrustFreshnessMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
