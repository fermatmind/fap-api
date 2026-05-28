<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerImportDetailReadyReplacementAuthorityCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'career:import-detail-ready-replacement-authority';

    private const CONFIRM = 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_CONTROLLED_IMPORT_APPROVED';

    #[Test]
    public function dry_run_validates_package_without_writing_authority_rows(): void
    {
        $this->createReplacementOccupation();

        [$exitCode, $report] = $this->runImport();

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['would_write']);
        $this->assertFalse($report['did_write']);
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(1, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function apply_requires_exact_confirmation_phrase(): void
    {
        $this->createReplacementOccupation();

        [$exitCode, $report] = $this->runImport(['--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--confirm must equal', implode(' ', $report['errors']));
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(1, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function confirmed_apply_writes_only_crosswalk_display_asset_and_import_run(): void
    {
        $occupation = $this->createReplacementOccupation();

        [$exitCode, $report] = $this->runImport([
            '--apply' => true,
            '--confirm' => self::CONFIRM,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('apply', $report['mode']);
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['did_write']);
        $this->assertFalse($report['runtime_promotion_performed']);
        $this->assertSame(0, $report['index_state_rows_written']);
        $this->assertSame(1, CareerImportRun::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());

        $this->assertDatabaseHas('occupation_crosswalks', [
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => '15-1299',
            'source_title' => 'Computer Occupations, All Other',
            'mapping_type' => 'same_soc_family_from_onet_soc_2019',
        ]);
        $this->assertDatabaseHas('career_job_display_assets', [
            'occupation_id' => $occupation->id,
            'canonical_slug' => 'computer-occupations-all-other',
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
        ]);

        $asset = CareerJobDisplayAsset::query()->firstOrFail();
        $this->assertIsArray($asset->page_payload_json['page']['en'] ?? null);
        $this->assertIsArray($asset->page_payload_json['page']['zh'] ?? null);
        $this->assertSame(24, count($asset->component_order_json));
        $this->assertFalse((bool) ($asset->metadata_json['runtime_promotion_performed'] ?? true));
    }

    #[Test]
    public function repeated_apply_updates_same_authority_rows_without_duplicates(): void
    {
        $this->createReplacementOccupation();

        $this->runImport(['--apply' => true, '--confirm' => self::CONFIRM]);
        [$exitCode, $report] = $this->runImport(['--apply' => true, '--confirm' => self::CONFIRM]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, CareerImportRun::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function missing_observed_onet_authority_blocks_import(): void
    {
        $this->createReplacementOccupation(withOnetCrosswalk: false);

        [$exitCode, $report] = $this->runImport([
            '--apply' => true,
            '--confirm' => self::CONFIRM,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['observed_onet_crosswalk_valid']);
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function indexed_occupation_blocks_replacement_import(): void
    {
        $occupation = $this->createReplacementOccupation();
        IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => 'indexable',
            'index_eligible' => true,
            'canonical_path' => '/en/career/jobs/computer-occupations-all-other',
            'reason_codes' => [],
            'changed_at' => now(),
        ]);

        [$exitCode, $report] = $this->runImport([
            '--apply' => true,
            '--confirm' => self::CONFIRM,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $report['existing_index_state_rows']);
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function runImport(array $options = []): array
    {
        $exitCode = Artisan::call(self::COMMAND, array_merge([
            '--json' => true,
        ], $options));

        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, Artisan::output());

        return [$exitCode, $report];
    }

    private function createReplacementOccupation(bool $withOnetCrosswalk = true): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'computer-occupations',
            'title_en' => 'Computer Occupations',
            'title_zh' => '计算机职业',
        ]);

        $occupation = Occupation::query()->create([
            'id' => '019da904-3dbb-723b-ace7-532fb069b486',
            'family_id' => $family->id,
            'canonical_slug' => 'computer-occupations-all-other',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'directory_candidate',
            'canonical_title_en' => 'Computer Occupations, All Other',
            'canonical_title_zh' => '计算机',
            'search_h1_zh' => '计算机相关职业（其他）',
        ]);

        if ($withOnetCrosswalk) {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => 'onet_soc_2019',
                'source_code' => '15-1299.00',
                'source_title' => 'Computer Occupations, All Other',
                'mapping_type' => 'directory_candidate',
                'confidence_score' => 0.5,
            ]);
        }

        return $occupation;
    }
}
