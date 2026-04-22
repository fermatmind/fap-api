<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\AtomicBlockResolver;
use App\Services\BigFive\ReportEngine\Resolver\ModifierInjector;
use Tests\TestCase;

final class ModifierInjectorTest extends TestCase
{
    public function test_it_injects_sentence_level_slots_without_replace_maps(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $fixture = (array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent');
        $context = ReportContext::fromArray($fixture);

        $blocks = app(AtomicBlockResolver::class)->resolve($context, $registry);
        $blocks = app(ModifierInjector::class)->inject($context, $blocks, $registry);

        $hero = $blocks['hero_summary'][0]->toArray();
        $this->assertSame(
            '——这意味着你更快感到不对劲，内部负荷上升更早。',
            data_get($hero, 'resolved_copy.injections.headline_extension')
        );
        $this->assertSame(['modifiers/N.json#gradients.n_g4'], data_get($hero, 'provenance.modifier_refs'));
        $this->assertArrayNotHasKey('replace_map', (array) data_get($registry, 'modifiers.N.gradients.n_g4'));
    }
}
