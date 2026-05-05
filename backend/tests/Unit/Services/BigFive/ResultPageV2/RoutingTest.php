<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2BandMapper;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteMatrixLookup;
use InvalidArgumentException;
use Tests\TestCase;

final class RoutingTest extends TestCase
{
    public function test_band_mapper_uses_b5_content_five_band_boundaries(): void
    {
        $mapper = new BigFiveV2BandMapper();

        $cases = [
            0 => 1,
            19 => 1,
            20 => 2,
            39 => 2,
            40 => 3,
            59 => 3,
            60 => 4,
            79 => 4,
            80 => 5,
            100 => 5,
        ];

        foreach ($cases as $percentile => $expectedBand) {
            $this->assertSame($expectedBand, $mapper->mapPercentile($percentile), (string) $percentile);
        }
    }

    public function test_o59_score_result_maps_to_reconciled_route_key(): void
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromScoreResult($this->scoreResult([
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ]));

        $this->assertNotNull($routeInput);
        $this->assertSame([
            'O' => 3,
            'C' => 2,
            'E' => 2,
            'A' => 3,
            'N' => 4,
        ], $routeInput->domainRouteBands);
        $this->assertSame('O3_C2_E2_A3_N4', $routeInput->combinationKey);
        $this->assertNotSame('O3_C2_E1_A3_N4', $routeInput->combinationKey);
    }

    public function test_missing_domain_percentile_fails_closed(): void
    {
        $adapter = new BigFiveV2ProjectionRouteInputAdapter();
        $scoreResult = $this->scoreResult([
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
        ]);

        $this->assertNull($adapter->fromScoreResult($scoreResult));
        $this->assertContains('missing domain percentile: N', $adapter->errors());
    }

    public function test_out_of_range_percentile_fails_closed(): void
    {
        $adapter = new BigFiveV2ProjectionRouteInputAdapter();
        $scoreResult = $this->scoreResult([
            'O' => 101,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ]);

        $this->assertNull($adapter->fromScoreResult($scoreResult));
        $this->assertContains('percentile must be within 0..100', $adapter->errors());
    }

    public function test_locked_or_redacted_projection_fails_closed(): void
    {
        $adapter = new BigFiveV2ProjectionRouteInputAdapter();

        $this->assertNull($adapter->fromProjection([
            '_meta' => [
                'redacted' => true,
                'locked' => true,
            ],
            'trait_vector' => [
                ['key' => 'O', 'band' => 'mid'],
                ['key' => 'C', 'band' => 'low'],
                ['key' => 'E', 'band' => 'low'],
                ['key' => 'A', 'band' => 'mid'],
                ['key' => 'N', 'band' => 'high'],
            ],
        ]));
        $this->assertContains('projection is locked or redacted', $adapter->errors());
    }

    public function test_full_projection_percentiles_can_build_route_input_without_trait_bands(): void
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromProjection([
            '_meta' => [
                'schema_version' => 'big5.public_projection.v1',
            ],
            'trait_bands' => [
                'O' => 'mid',
                'C' => 'low',
                'E' => 'low',
                'A' => 'mid',
                'N' => 'high',
            ],
            'trait_vector' => [
                ['key' => 'O', 'percentile' => 59, 'band' => 'mid'],
                ['key' => 'C', 'percentile' => 32, 'band' => 'low'],
                ['key' => 'E', 'percentile' => 20, 'band' => 'low'],
                ['key' => 'A', 'percentile' => 55, 'band' => 'mid'],
                ['key' => 'N', 'percentile' => 68, 'band' => 'high'],
            ],
            'facet_vector' => [
                ['key' => 'N1', 'percentile' => 82, 'bucket' => 'high'],
            ],
            'quality' => ['level' => 'A'],
            'norms' => ['status' => 'CALIBRATED'],
        ]);

        $this->assertNotNull($routeInput);
        $this->assertSame('O3_C2_E2_A3_N4', $routeInput->combinationKey);
        $this->assertSame([['key' => 'N1', 'percentile' => 82, 'bucket' => 'high']], $routeInput->facetRouteSignals);
    }

    public function test_degraded_quality_and_missing_norms_carry_suppression_hints(): void
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromScoreResult($this->scoreResult(
            [
                'O' => 59,
                'C' => 32,
                'E' => 20,
                'A' => 55,
                'N' => 68,
            ],
            quality: ['level' => 'D'],
            norms: ['status' => 'MISSING'],
        ));

        $this->assertNotNull($routeInput);
        $this->assertSame('degraded', $routeInput->qualityStatus);
        $this->assertSame('missing', $routeInput->normStatus);
        $this->assertSame(['quality_degraded', 'norm_missing'], $routeInput->suppressionHints);
    }

    public function test_route_matrix_lookup_returns_existing_row_and_does_not_fabricate_fallback(): void
    {
        $lookup = new BigFiveV2RouteMatrixLookup();
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromScoreResult($this->scoreResult([
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ]));
        $this->assertNotNull($routeInput);

        $row = $lookup->lookup($routeInput);
        $this->assertNotNull($row);
        $this->assertSame(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY, $row->combinationKey);
        $this->assertSame('sensitive_independent_thinker', $row->profileKey);

        $this->assertNull($lookup->lookup('O0_C2_E2_A3_N4'));
    }

    public function test_invalid_percentile_type_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('percentile must be an integer from 0 to 100');

        (new BigFiveV2BandMapper())->mapPercentile('high');
    }

    /**
     * @param  array<string,mixed>  $domainPercentiles
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $norms
     * @return array<string,mixed>
     */
    private function scoreResult(array $domainPercentiles, array $quality = ['level' => 'A'], array $norms = ['status' => 'CALIBRATED']): array
    {
        return [
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => $domainPercentiles,
                'facets_percentile' => [
                    'N1' => 82,
                    'C1' => 24,
                ],
            ],
            'quality' => $quality,
            'norms' => $norms,
        ];
    }
}
