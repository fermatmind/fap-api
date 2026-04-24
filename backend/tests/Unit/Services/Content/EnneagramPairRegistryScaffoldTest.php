<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramPairRegistryScaffoldTest extends TestCase
{
    public function test_pair_registry_includes_required_p0_pairs(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        $entries = collect((array) data_get($pack, 'pair_registry.entries', []));
        $pairKeys = $entries->pluck('pair_key')->sort()->values()->all();

        $this->assertSame([
            '1_3',
            '1_6',
            '1_8',
            '2_3',
            '2_9',
            '3_7',
            '3_8',
            '4_5',
            '4_9',
            '5_6',
            '5_9',
            '6_1',
            '6_9',
            '7_3',
            '8_1',
        ], $pairKeys);
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['fallback_policy'] ?? '')) === ''));
    }
}
