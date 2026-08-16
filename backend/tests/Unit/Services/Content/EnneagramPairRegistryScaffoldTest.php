<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramPairRegistryScaffoldTest extends TestCase
{
    public function test_pair_registry_includes_required_p0_ready_pairs_and_fallback_template(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        $entries = collect((array) data_get($pack, 'pair_registry.entries', []));
        $pairKeys = $entries->pluck('pair_key')->sort()->values()->all();
        $fallbackTemplate = (array) data_get($pack, 'pair_registry.fallback_template', []);

        $this->assertCount(36, $pairKeys);
        $this->assertSame('1_2', $pairKeys[0]);
        $this->assertSame('8_9', $pairKeys[35]);
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['fallback_policy'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['short_compare_copy'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['content_maturity'] ?? null) !== 'p0_ready'));
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['evidence_level'] ?? null) !== 'theory_based'));
        $this->assertSame(
            ['motivation_difference', 'observation_question', 'pressure_difference', 'relationship_difference', 'same_surface', 'work_difference'],
            collect($fallbackTemplate)->keys()->sort()->values()->all()
        );
    }
}
