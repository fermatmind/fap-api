<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class AtomicRegistryCoverageTest extends TestCase
{
    private const TRAITS = ['O', 'C', 'E', 'A', 'N'];

    private const BANDS = ['low', 'mid', 'high'];

    private const REQUIRED_SLOTS = [
        'hero_summary.headline',
        'hero_summary.body_core',
        'domains_overview.snapshot_line',
        'domain_deep_dive.definition',
        'domain_deep_dive.strengths',
        'domain_deep_dive.costs',
        'domain_deep_dive.daily_life',
        'core_portrait.identity',
        'core_portrait.default_style',
        'norms_comparison.relative_meaning',
        'action_plan.priority_hint',
    ];

    public function test_all_trait_atomic_packs_have_required_band_dossiers(): void
    {
        $registry = app(RegistryLoader::class)->load();

        $this->assertSame(self::TRAITS, array_keys((array) $registry['atomic']));

        foreach (self::TRAITS as $traitCode) {
            $pack = (array) $registry['atomic'][$traitCode];
            $this->assertSame($traitCode, $pack['trait_code']);
            $this->assertSame(self::BANDS, array_keys((array) $pack['bands']));

            foreach (self::BANDS as $band) {
                $slots = (array) data_get($pack, "bands.{$band}.slots");
                foreach (self::REQUIRED_SLOTS as $slotPath) {
                    $value = data_get($slots, $slotPath);
                    $this->assertNotEmpty($value, "{$traitCode}.{$band}.{$slotPath} is missing");
                }
            }
        }
    }
}
