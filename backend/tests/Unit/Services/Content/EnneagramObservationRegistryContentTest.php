<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramObservationRegistryContentTest extends TestCase
{
    public function test_observation_registry_exposes_p0_ready_day1_to_day7_scripts(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $entries = collect((array) data_get($loader->loadRegistryPack(), 'observation_registry.entries', []));

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $entries->pluck('day')->sort()->values()->all());
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['title'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['example'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['boundary_copy'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['content_maturity'] ?? null) !== 'p0_ready'));
        $this->assertSame('Day 7｜深度共鸣投票', (string) $entries->firstWhere('day', 7)['title']);
    }
}
