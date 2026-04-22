<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\ActionMatrixResolver;
use Tests\TestCase;

final class ActionMatrixResolverTest extends TestCase
{
    public function test_it_outputs_matching_actions_by_scenario(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = ReportContext::fromArray((array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent'));

        $matrix = app(ActionMatrixResolver::class)->resolve($context, $registry);

        $this->assertSame(['workplace', 'stress_recovery', 'personal_growth'], array_keys($matrix));
        $this->assertCount(4, $matrix['workplace']);
        $this->assertCount(4, $matrix['stress_recovery']);
        $this->assertCount(4, $matrix['personal_growth']);
        $this->assertSame('n_work_continue_risk_sensing_60_79', $matrix['workplace'][0]->ruleId);
    }
}
