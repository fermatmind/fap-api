<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerValidateDisplayAssetLineageCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_complete_display_asset_lineage_without_writing(): void
    {
        $asset = $this->createDisplayAsset('actuaries');

        [$exitCode, $report] = $this->runLineage('actuaries');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['display_assets_changed']);
        $this->assertFalse($report['release_states_changed']);
        $this->assertSame(1, $report['validated_count']);
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertSame($asset->id, $report['items'][0]['display_asset_id']);
        $this->assertSame('complete', $report['items'][0]['lineage_status']);
        $this->assertSame('career:import-selected-display-assets', $report['items'][0]['lineage']['import_command']);
        $this->assertSame('sha-123', $report['items'][0]['lineage']['workbook_sha256']);
        $this->assertIsString($report['items'][0]['api_surface_hash']);
        $this->assertSame('pending', $report['items'][0]['web_validation_status']);
        $this->assertSame('actuaries', $report['items'][0]['rollback_target']['canonical_slug']);
    }

    #[Test]
    public function it_reports_missing_or_incomplete_lineage_as_no_go(): void
    {
        $this->createDisplayAsset('actuaries', metadata: [
            'command' => 'career:import-selected-display-assets',
        ]);

        [$exitCode, $report] = $this->runLineage('actuaries,dentists');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('no_go', $report['decision']);
        $this->assertSame(2, $report['validated_count']);
        $this->assertSame(0, $report['summary']['complete']);
        $this->assertSame(2, $report['summary']['incomplete']);
        $this->assertSame(1, $report['summary']['missing_display_assets']);
        $this->assertSame('incomplete', $report['items'][0]['lineage_status']);
        $this->assertContains('missing_workbook_sha256', $report['items'][0]['blockers']);
        $this->assertSame('missing_display_asset', $report['items'][1]['lineage_status']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['sitemap_changed']);
        $this->assertFalse($report['llms_changed']);
    }

    #[Test]
    public function it_requires_explicit_slugs(): void
    {
        [$exitCode, $report] = $this->runLineage('');

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--slugs is required.', implode(' ', $report['errors']));
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function createDisplayAsset(string $slug, ?array $metadata = null): CareerJobDisplayAsset
    {
        $occupation = $this->createOccupation($slug);

        return CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => ['hero', 'faq_block'],
            'page_payload_json' => ['page' => ['content' => ['hero' => ['title' => $slug]]]],
            'seo_payload_json' => ['title' => $slug],
            'sources_json' => ['source_card' => ['items' => ['BLS']]],
            'structured_data_json' => ['schema_rules' => ['faq_visible_only' => true]],
            'implementation_contract_json' => ['component_order_count' => 24],
            'metadata_json' => $metadata ?? [
                'command' => 'career:import-selected-display-assets',
                'validator_version' => 'career_selected_display_asset_import_v0.1',
                'mapper_version' => 'career_selected_display_asset_mapper_v0.1',
                'workbook_basename' => '第九版_d5_ready.xlsx',
                'workbook_sha256' => 'sha-123',
                'slug' => $slug,
                'row_number' => 4,
                'display_import_stage' => 'second_pilot_selected',
                'release_gates' => [
                    'sitemap' => false,
                    'llms' => false,
                    'paid' => false,
                    'backlink' => false,
                ],
                'web_validation' => [
                    'status' => 'pending',
                ],
            ],
        ]);
    }

    private function createOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'lineage-family-'.$slug,
            'title_en' => 'Lineage Family',
            'title_zh' => 'Lineage Family',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => str_replace('-', ' ', $slug),
            'canonical_title_zh' => str_replace('-', ' ', $slug),
            'search_h1_zh' => str_replace('-', ' ', $slug),
        ]);
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function runLineage(string $slugs): array
    {
        $exitCode = Artisan::call('career:validate-display-asset-lineage', [
            '--slugs' => $slugs,
            '--json' => true,
        ]);

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }
}
