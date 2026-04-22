<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class ModifierRegistryCoverageTest extends TestCase
{
    private const TRAITS = ['O', 'C', 'E', 'A', 'N'];

    private const REQUIRED_INJECTIONS = [
        'hero_summary.headline_extension',
        'domain_deep_dive.intensity_sentence',
        'core_portrait.load_sentence',
        'norms_comparison.compare_sentence',
        'action_plan.urgency_sentence',
    ];

    public function test_all_trait_modifier_packs_have_five_sentence_level_gradients(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $seenIds = [];

        $this->assertSame(self::TRAITS, array_keys((array) $registry['modifiers']));

        foreach (self::TRAITS as $traitCode) {
            $pack = (array) $registry['modifiers'][$traitCode];
            $this->assertSame($traitCode, $pack['trait_code']);
            $expectedGradientIds = array_map(
                static fn (int $index): string => strtolower($traitCode).'_g'.$index,
                range(1, 5)
            );
            $this->assertSame($expectedGradientIds, array_keys((array) $pack['gradients']));

            foreach ($expectedGradientIds as $gradientId) {
                $this->assertNotContains($gradientId, $seenIds);
                $seenIds[] = $gradientId;
                $gradient = (array) data_get($pack, "gradients.{$gradientId}");
                $this->assertArrayNotHasKey('replace_map', $gradient);
                foreach (self::REQUIRED_INJECTIONS as $injectionKey) {
                    $this->assertNotSame('', trim((string) ($gradient['injections'][$injectionKey] ?? '')));
                }
            }
        }
    }
}
