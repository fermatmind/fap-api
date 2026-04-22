<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\SynergyCandidateResolver;
use App\Services\BigFive\ReportEngine\Resolver\SynergyResolutionService;
use Tests\TestCase;

final class SynergyResolutionServiceTest extends TestCase
{
    public function test_it_collects_and_resolves_the_n_high_e_low_synergy(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = ReportContext::fromArray((array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent'));

        $candidates = app(SynergyCandidateResolver::class)->collect($context, $registry);
        $selected = app(SynergyResolutionService::class)->resolve($candidates, 2);

        $this->assertCount(1, $selected);
        $this->assertSame('n_high_x_e_low', $selected[0]->synergyId);
        $this->assertSame('stress_activation', $selected[0]->mutexGroup);
        $this->assertSame(['o_high_x_n_high'], $selected[0]->mutualExcludes);
    }
}
