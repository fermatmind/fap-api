<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerAliasResolutionBundle
{
    /**
     * @param  array{raw:string,normalized:string,locale:?string}  $query
     * @param  array<string, mixed>  $resolution
     */
    public function __construct(
        public readonly array $query,
        public readonly array $resolution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_alias_resolution',
            'bundle_version' => 'career.protocol.alias_resolution.v1',
            'query' => $this->query,
            'resolution' => $this->resolution,
        ];
    }
}
