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
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
                'sitemap_eligible' => true,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
        ]));

        $truth = app(CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);

        $this->assertSame(2, data_get($truth, 'counts.route_exists'));
        $this->assertSame(2, data_get($truth, 'counts.sitemap_live'));
        $this->assertSame(0, data_get($truth, 'counts.llms_live'));
        $this->assertSame(0, data_get($truth, 'counts.fully_live'));
        $this->assertFalse($truth['items'][0]['fully_live']);
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
