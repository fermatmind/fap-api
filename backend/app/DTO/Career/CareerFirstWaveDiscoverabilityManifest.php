<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDiscoverabilityManifest
{
    /**
     * @param  list<array<string, mixed>>  $routes
     */
    public function __construct(
        public readonly string $manifestVersion,
        public readonly string $scope,
        public readonly array $routes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manifest_kind' => 'career_first_wave_discoverability_manifest',
            'manifest_version' => $this->manifestVersion,
            'scope' => $this->scope,
            'routes' => $this->routes,
        ];
    }
}
