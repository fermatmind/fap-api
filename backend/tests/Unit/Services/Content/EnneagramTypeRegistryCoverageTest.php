<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramTypeRegistryCoverageTest extends TestCase
{
    public function test_type_registry_covers_1_to_9_at_scaffold_level(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        $entries = (array) data_get($pack, 'type_registry.entries', []);
        $typeIds = collect($entries)->pluck('type_id')->sort()->values()->all();

        $this->assertCount(9, $entries);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $typeIds);
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => trim((string) ($entry['seven_day_question'] ?? '')) === ''));
    }
}
