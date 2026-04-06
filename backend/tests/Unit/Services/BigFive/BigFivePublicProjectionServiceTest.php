<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive;

use App\Models\Result;
use App\Services\BigFive\BigFivePublicProjectionService;
use Tests\TestCase;

final class BigFivePublicProjectionServiceTest extends TestCase
{
    public function test_it_builds_formal_big5_projection_fields_and_sections(): void
    {
        $result = new Result([
            'result_json' => [
                'normed_json' => [
                    'engine_version' => 'big5_ipipneo120_v3.0.0',
                    'raw_scores' => [
                        'domains_mean' => ['O' => 4.2, 'C' => 3.8, 'E' => 3.2, 'A' => 3.9, 'N' => 2.3],
                        'facets_mean' => ['N1' => 2.1, 'E1' => 3.4, 'O1' => 4.6],
                    ],
                    'scores_0_100' => [
                        'domains_percentile' => ['O' => 81, 'C' => 73, 'E' => 48, 'A' => 76, 'N' => 22],
                        'facets_percentile' => ['N1' => 18, 'E1' => 57, 'O1' => 93],
                    ],
                    'facts' => [
                        'domain_buckets' => ['O' => 'high', 'C' => 'high', 'E' => 'mid', 'A' => 'high', 'N' => 'low'],
                        'facet_buckets' => ['N1' => 'low', 'E1' => 'mid', 'O1' => 'high'],
                        'top_strength_facets' => ['O5', 'A3', 'C4'],
                        'top_growth_facets' => ['E3', 'E2', 'C5'],
                    ],
                    'tags' => ['big5:o_high', 'big5:c_high', 'profile:explorer'],
                ],
            ],
        ]);

        $projection = app(BigFivePublicProjectionService::class)->buildFromResult($result, 'zh-CN', 'full', false);

        $this->assertSame('big5.public_projection.v1', $projection['schema_version']);
        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_values(array_map(
            static fn (array $trait): string => (string) ($trait['key'] ?? ''),
            (array) ($projection['trait_vector'] ?? [])
        )));
        $this->assertSame('high', data_get($projection, 'trait_bands.O'));
        $this->assertSame('low', data_get($projection, 'trait_bands.N'));
        $this->assertSame('O', data_get($projection, 'dominant_traits.0.key'));
        $this->assertCount(30, (array) ($projection['facet_vector'] ?? []));
        $this->assertSame('N1', data_get($projection, 'facet_vector.0.key'));
        $this->assertSame('N', data_get($projection, 'facet_vector.0.domain'));
        $this->assertSame(18, data_get($projection, 'facet_vector.0.percentile'));
        $this->assertSame(2.1, data_get($projection, 'facet_vector.0.mean'));
        $this->assertSame('low', data_get($projection, 'facet_vector.0.bucket'));
        $this->assertSame('O1', data_get($projection, 'facet_vector.2.key'));
        $this->assertSame(93, data_get($projection, 'facet_vector.2.percentile'));
        $this->assertContains('profile:explorer', (array) ($projection['variant_keys'] ?? []));
        $this->assertSame('exploratory', data_get($projection, 'scene_fingerprint.novelty'));
        $this->assertSame('traits.overview', data_get($projection, 'ordered_section_keys.0'));
        $this->assertSame('career.work_style', data_get($projection, 'ordered_section_keys.3'));
        $this->assertSame('growth.next_actions', data_get($projection, 'ordered_section_keys.4'));
        $this->assertNotSame('', (string) data_get($projection, 'explainability_summary.headline', ''));
        $this->assertSame('N', data_get($projection, 'action_plan_summary.focus_trait'));
        $this->assertCount(9, (array) ($projection['sections'] ?? []));
        $this->assertSame('paid', data_get($projection, 'sections.2.access_level'));
    }

    public function test_it_keeps_trait_vector_in_ocean_order_and_breaks_percentile_ties_stably(): void
    {
        $result = new Result([
            'result_json' => [
                'normed_json' => [
                    'engine_version' => 'big5_ipipneo120_v3.0.0',
                    'raw_scores' => [
                        'domains_mean' => ['O' => 3.0, 'C' => 3.0, 'E' => 3.0, 'A' => 3.0, 'N' => 3.0],
                        'facets_mean' => ['N1' => 3.0, 'E1' => 3.0, 'O1' => 3.0],
                    ],
                    'scores_0_100' => [
                        'domains_percentile' => ['O' => 70, 'C' => 70, 'E' => 55, 'A' => 70, 'N' => 55],
                        'facets_percentile' => ['N1' => 51, 'E1' => 49, 'O1' => 52],
                    ],
                    'facts' => [
                        'domain_buckets' => ['O' => 'high', 'C' => 'high', 'E' => 'mid', 'A' => 'high', 'N' => 'mid'],
                        'facet_buckets' => ['N1' => 'mid', 'E1' => 'mid', 'O1' => 'mid'],
                        'top_strength_facets' => ['O2', 'C3', 'A4'],
                        'top_growth_facets' => ['E2', 'N3', 'O1'],
                    ],
                    'tags' => ['profile:explorer'],
                ],
            ],
        ]);

        $projection = app(BigFivePublicProjectionService::class)->buildFromResult($result, 'en', 'free', true);

        $this->assertSame(
            ['O', 'C', 'E', 'A', 'N'],
            array_values(array_map(
                static fn (array $trait): string => (string) ($trait['key'] ?? ''),
                (array) ($projection['trait_vector'] ?? [])
            ))
        );
        $this->assertSame(['O', 'C', 'A'], array_values(array_map(
            static fn (array $trait): string => (string) ($trait['key'] ?? ''),
            (array) ($projection['dominant_traits'] ?? [])
        )));
        $this->assertCount(30, (array) ($projection['facet_vector'] ?? []));
        $this->assertSame('E', data_get($projection, 'action_plan_summary.focus_trait'));
    }
}
