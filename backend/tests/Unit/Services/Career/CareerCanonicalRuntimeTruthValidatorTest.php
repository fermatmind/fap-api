<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CareerCanonicalRuntimeTruthValidatorTest extends TestCase
{
    public function test_it_passes_when_all_canonical_runtime_surfaces_are_equal(): void
    {
        $result = app(CareerCanonicalRuntimeTruthValidator::class)->validate($this->truth([
            $this->item(),
        ]));

        $this->assertSame('pass', $result['status']);
        $this->assertSame(1, data_get($result, 'counts.fully_live'));
        $this->assertSame(0, data_get($result, 'counts.failures'));
    }

    public function test_it_blocks_projection_only_rows_missing_live_surfaces(): void
    {
        $item = $this->item([
            'llms_live' => false,
            'llms_full_live' => false,
            'fully_live' => false,
        ]);

        $result = app(CareerCanonicalRuntimeTruthValidator::class)->validate($this->truth([$item]));

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, data_get($result, 'counts.projection_only'));
        $this->assertSame(0, data_get($result, 'counts.fully_live'));
        $this->assertContains('projection_only', array_column($result['failures'], 'reason'));
    }

    public function test_it_blocks_surface_only_rows_without_projection_gate(): void
    {
        $item = $this->item([
            'projection_state' => CareerRuntimePublishProjectionService::STATE_BLOCKED,
            'release_gate_pass' => false,
            'dataset_visible' => true,
            'search_visible' => true,
            'route_exists' => true,
            'final_200' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'fully_live' => false,
        ]);

        $result = app(CareerCanonicalRuntimeTruthValidator::class)->validate($this->truth([$item]));

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, data_get($result, 'counts.dataset_only'));
        $this->assertSame(1, data_get($result, 'counts.search_only'));
        $this->assertSame(1, data_get($result, 'counts.route_only'));
        $this->assertSame(1, data_get($result, 'counts.sitemap_only'));
        $this->assertSame(1, data_get($result, 'counts.llms_only'));
        $this->assertSame(1, data_get($result, 'counts.llms_full_only'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function truth(array $items): array
    {
        return [
            'truth_kind' => CareerCanonicalRuntimeTruthExporter::TRUTH_KIND,
            'truth_version' => CareerCanonicalRuntimeTruthExporter::TRUTH_VERSION,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'actors',
            'locale' => 'en',
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'route_exists' => true,
            'final_200' => true,
            'robots_indexable' => true,
            'canonical_self' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'release_gate_pass' => true,
            'fully_live' => true,
        ], $overrides);
    }
}
