<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Resolver\FacetPrecisionResolver;
use Tests\TestCase;

final class FacetPrecisionResolverThresholdTest extends TestCase
{
    public function test_it_requires_delta_and_cross_band_for_single_facet_rules(): void
    {
        $resolver = app(FacetPrecisionResolver::class);

        $this->assertSame([], $resolver->resolve($this->context(59, 78), $this->registry()));
        $this->assertSame([], $resolver->resolve($this->context(80, 100), $this->registry()));

        $matches = $resolver->resolve($this->context(59, 80), $this->registry());

        $this->assertSame(['o1_high_with_o_mid'], array_map(static fn ($match): string => $match->ruleId, $matches));
    }

    /**
     * @return array<string,mixed>
     */
    private function registry(): array
    {
        return [
            'facet_precision' => [
                'O' => [
                    'rules' => [[
                        'rule_id' => 'o1_high_with_o_mid',
                        'facet_code' => 'O1',
                        'anomaly_type' => 'positive_spike',
                        'when' => [
                            'domain_percentile_min' => 35,
                            'facet_percentile_min' => 75,
                            'delta_abs_min' => 20,
                            'cross_band_required' => true,
                        ],
                        'priority_weight' => 100,
                        'section_targets' => ['facet_details'],
                        'copy' => ['title' => 't', 'body' => 'b', 'why_it_matters' => 'w'],
                    ]],
                ],
                'C' => ['rules' => []],
                'E' => ['rules' => []],
                'A' => ['rules' => []],
                'N' => ['rules' => []],
            ],
        ];
    }

    private function context(int $domainPercentile, int $facetPercentile): ReportContext
    {
        return ReportContext::fromArray([
            'locale' => 'zh-CN',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_90',
            'score_vector' => [
                'domains' => [
                    'O' => ['percentile' => $domainPercentile, 'band' => 'mid', 'gradient_id' => 'o_g3'],
                    'C' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'c_g3'],
                    'E' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'e_g3'],
                    'A' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'a_g3'],
                    'N' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'n_g3'],
                ],
                'facets' => [
                    'O1' => ['percentile' => $facetPercentile, 'domain' => 'O'],
                ],
            ],
        ]);
    }
}
