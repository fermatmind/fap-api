<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\FacetPrecisionResolver;
use Tests\TestCase;

final class FacetPrecisionResolverCompoundRuleTest extends TestCase
{
    public function test_c_order_maintainer_split_compound_rule_resolves(): void
    {
        $matches = $this->resolveFixture('context_c_order_maintainer_split.json');

        $this->assertContains('c_order_maintainer_split', array_map(static fn ($match): string => $match->ruleId, $matches));
        $match = collect($matches)->firstWhere('ruleId', 'c_order_maintainer_split');

        $this->assertTrue($match->isCompound);
        $this->assertSame(['C1', 'C2', 'C4', 'C5'], $match->facetCodes);
    }

    public function test_e_warm_but_reserved_split_compound_rule_resolves(): void
    {
        $matches = $this->resolveFixture('context_e_warm_but_reserved.json');

        $this->assertContains('e_warm_but_reserved_split', array_map(static fn ($match): string => $match->ruleId, $matches));
        $match = collect($matches)->firstWhere('ruleId', 'e_warm_but_reserved_split');

        $this->assertTrue($match->isCompound);
        $this->assertSame(['E1', 'E2', 'E3', 'E5'], $match->facetCodes);
    }

    /**
     * @return list<object>
     */
    private function resolveFixture(string $file): array
    {
        $context = json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/facet_contexts/{$file}")), true);

        return app(FacetPrecisionResolver::class)->resolve(
            ReportContext::fromArray(is_array($context) ? $context : []),
            app(RegistryLoader::class)->load(),
        );
    }
}
