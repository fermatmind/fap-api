<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramTheoryHintSafetyTest extends TestCase
{
    public function test_theory_hints_are_marked_non_hard_judgement(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $entries = collect((array) data_get($loader->loadRegistryPack(), 'theory_hint_registry.entries', []));

        $this->assertCount(4, $entries);
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['hard_judgement_allowed'] ?? null) !== false));
        $this->assertSame(
            ['arrow_growth_reference_placeholder', 'level_spectrum_placeholder', 'subtype_future_module_placeholder', 'wing_hint'],
            $entries->pluck('theory_key')->sort()->values()->all()
        );
    }
}
