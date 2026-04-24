<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramGroupRegistryCoverageTest extends TestCase
{
    public function test_group_registry_covers_centers_stances_and_harmonics(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        $entries = collect((array) data_get($pack, 'group_registry.entries', []));
        $keys = $entries
            ->map(fn ($entry): string => (string) ($entry['group_type'] ?? '').':'.(string) ($entry['group_key'] ?? ''))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'center:body',
            'center:head',
            'center:heart',
            'harmonic:competency',
            'harmonic:positive_outlook',
            'harmonic:reactive',
            'stance:assertive',
            'stance:compliant',
            'stance:withdrawn',
        ], $keys);
    }
}
