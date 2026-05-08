<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CareerCanonicalRuntimeTruthExporterTest extends TestCase
{
    public function test_it_exports_only_public_canonical_projection_rows_with_runtime_surface_flags(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ],
            [
                'source_slug' => 'software-developers',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                'public_eligible' => false,
                'indexability' => 'not_public',
            ],
        ]));

        $truth = app(CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);

        $this->assertSame('career_canonical_runtime_truth', $truth['truth_kind']);
        $this->assertSame(2, data_get($truth, 'counts.canonical_projection_rows'));
        $this->assertSame(2, data_get($truth, 'counts.fully_live'));
        $this->assertSame(2, data_get($truth, 'excluded_counts_by_public_resolution_type.keep_non_public_with_policy'));

        foreach ($truth['items'] as $item) {
            $this->assertSame('actors', $item['slug']);
            $this->assertTrue($item['route_exists']);
            $this->assertTrue($item['final_200']);
            $this->assertTrue($item['robots_indexable']);
            $this->assertTrue($item['canonical_self']);
            $this->assertTrue($item['dataset_visible']);
            $this->assertTrue($item['search_visible']);
            $this->assertTrue($item['sitemap_live']);
            $this->assertTrue($item['llms_live']);
            $this->assertTrue($item['llms_full_live']);
            $this->assertTrue($item['release_gate_pass']);
            $this->assertTrue($item['fully_live']);
        }
    }

    public function test_it_keeps_surface_mismatch_visible_without_promoting_to_fully_live(): void
    {
        $projection = [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => [
                [
                    'slug' => 'actors',
                    'locale' => 'en',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                    'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
                    'detail_route_enabled' => true,
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'sitemap_live' => true,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'canonical_url' => 'https://fermatmind.com/en/career/jobs/actors',
                    'canonical_self' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                ],
            ],
        ];

        $truth = app(CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);

        $this->assertSame(1, data_get($truth, 'counts.route_exists'));
        $this->assertSame(1, data_get($truth, 'counts.sitemap_live'));
        $this->assertSame(0, data_get($truth, 'counts.llms_live'));
        $this->assertSame(0, data_get($truth, 'counts.fully_live'));
        $this->assertFalse($truth['items'][0]['fully_live']);
    }

    public function test_it_reports_published_candidate_rows_as_expected_pre_route_inventory(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actuaries',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
        ]));

        $truth = app(CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);

        $this->assertSame(2, data_get($truth, 'counts.published_candidate'));
        $this->assertSame(2, data_get($truth, 'counts.candidate_pre_route_expected_count'));
        $this->assertSame(2, data_get($truth, 'counts.candidate_release_gate_not_applicable_count'));
        $this->assertSame(0, data_get($truth, 'counts.candidate_unexpected_route_exposure_count'));
        $this->assertSame(0, data_get($truth, 'counts.candidate_unexpected_dataset_exposure_count'));
        $this->assertSame(0, data_get($truth, 'counts.candidate_unexpected_sitemap_exposure_count'));
        $this->assertSame(0, data_get($truth, 'counts.candidate_unexpected_llms_exposure_count'));

        foreach ($truth['items'] as $item) {
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE, $item['projection_state']);
            $this->assertFalse($item['route_exists']);
            $this->assertFalse($item['final_200']);
            $this->assertFalse($item['dataset_visible']);
            $this->assertFalse($item['search_visible']);
            $this->assertFalse($item['sitemap_live']);
            $this->assertFalse($item['llms_live']);
            $this->assertFalse($item['llms_full_live']);
            $this->assertFalse($item['robots_indexable']);
            $this->assertFalse($item['canonical_self']);
            $this->assertSame('expected_pre_route', $item['candidate_route_expectation']);
            $this->assertSame('not_applicable_before_promotion', $item['candidate_release_gate_applicability']);
            $this->assertTrue($item['candidate_pre_route_expected']);
            $this->assertSame([], $item['candidate_unexpected_exposures']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function ledger(array $rows): array
    {
        return [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'test',
            'scope' => 'test',
            'public_resolution' => [
                'rows' => $rows,
            ],
        ];
    }
}
