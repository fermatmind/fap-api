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

final class CareerImportDetailReadyReplacementAuthoritySourceCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'career:import-detail-ready-replacement-authority-source';

    private const CONFIRM = 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT_APPROVED';

    #[Test]
    public function dry_run_validates_source_package_without_writing_authority_rows(): void
    {
        [$exitCode, $report] = $this->runImport();

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('digital-forensics-analysts', $report['target_slug']);
        $this->assertTrue($report['would_write']);
        $this->assertFalse($report['did_write']);
        $this->assertTrue($report['family_valid']);
        $this->assertTrue($report['occupation_valid']);
        $this->assertTrue($report['onet_crosswalk_valid']);
        $this->assertTrue($report['us_soc_crosswalk_valid']);
        $this->assertTrue($report['display_asset_valid']);
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, OccupationFamily::query()->count());
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function apply_requires_exact_confirmation_phrase(): void
    {
        [$exitCode, $report] = $this->runImport(['--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--confirm must equal', implode(' ', $report['errors']));
        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function confirmed_apply_writes_only_source_authority_rows_without_runtime_promotion(): void
    {
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
        $this->assertSame(1, OccupationFamily::query()->count());
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());

        $occupation = Occupation::query()->where('canonical_slug', 'digital-forensics-analysts')->firstOrFail();
        $this->assertDatabaseHas('occupation_families', [
            'canonical_slug' => 'computer-and-information-technology',
        ]);
        $this->assertDatabaseHas('occupation_crosswalks', [
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => '15-1299.06',
            'mapping_type' => 'direct_onet_soc_2019',
        ]);
        $this->assertDatabaseHas('occupation_crosswalks', [
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => '15-1299',
            'mapping_type' => 'same_soc_family_from_onet_soc_2019',
        ]);
        $this->assertDatabaseHas('career_job_display_assets', [
            'occupation_id' => $occupation->id,
            'canonical_slug' => 'digital-forensics-analysts',
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
        ]);

        $asset = CareerJobDisplayAsset::query()->firstOrFail();
        $this->assertSame(24, count($asset->component_order_json));
        $this->assertIsArray($asset->page_payload_json['page']['en'] ?? null);
        $this->assertIsArray($asset->page_payload_json['page']['zh'] ?? null);
        $this->assertFalse((bool) ($asset->metadata_json['runtime_promotion_performed'] ?? true));
    }

    #[Test]
    public function repeated_apply_updates_same_source_authority_rows_without_duplicates(): void
    {
        $this->runImport(['--apply' => true, '--confirm' => self::CONFIRM]);
        [$exitCode, $report] = $this->runImport(['--apply' => true, '--confirm' => self::CONFIRM]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, CareerImportRun::query()->count());
        $this->assertSame(1, OccupationFamily::query()->count());
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
        $this->assertSame(0, IndexState::query()->count());
    }

    #[Test]
    public function indexed_existing_occupation_blocks_source_import(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'computer-and-information-technology',
            'title_en' => 'Computer and Information Technology',
            'title_zh' => '计算机与信息技术',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'digital-forensics-analysts',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'onet_soc_2019_direct',
            'canonical_title_en' => 'Digital Forensics Analysts',
            'canonical_title_zh' => '数字取证分析师',
            'search_h1_zh' => '数字取证分析师',
        ]);
        IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => 'indexable',
            'index_eligible' => true,
            'canonical_path' => '/en/career/jobs/digital-forensics-analysts',
            'reason_codes' => [],
            'changed_at' => now(),
        ]);

        [$exitCode, $report] = $this->runImport(['--apply' => true, '--confirm' => self::CONFIRM]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertSame(1, $report['existing_indexable_state_rows']);
        $this->assertStringContainsString('already indexable occupation', implode(' ', $report['errors']));
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
}
