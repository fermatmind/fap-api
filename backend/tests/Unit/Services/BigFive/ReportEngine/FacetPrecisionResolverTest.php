<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\FacetPrecisionResolver;
use Tests\TestCase;

final class FacetPrecisionResolverTest extends TestCase
{
    public function test_it_triggers_only_cross_band_hard_threshold_anomalies(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = ReportContext::fromArray((array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent'));

        $matches = app(FacetPrecisionResolver::class)->resolve($context, $registry);
        $ids = array_map(static fn ($match): string => $match->ruleId, $matches);

        $this->assertSame([
            'c1_high_with_c_low',
            'c5_low_with_c_low',
            'n1_high_spike',
            'n3_high_spike',
        ], $ids);
        foreach ($matches as $match) {
            $this->assertGreaterThanOrEqual(20, $match->deltaAbs);
            $this->assertSame(['facet_details'], $match->sectionTargets);
        }
    }
}
