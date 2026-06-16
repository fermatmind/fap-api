<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerJobSalaryAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CareerSalaryAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_asset_sidecar_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('career_job_salary_assets'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'asset_payload_json'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'asset_row_hash'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'preview_allowlisted'));
    }

    public function test_importer_dry_run_validates_preview_rows_without_writing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['validated_preview_rows']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
    }

    public function test_importer_force_writes_staging_preview_rows_only(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 2);
        $this->assertDatabaseHas('career_job_salary_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
        ]);
    }

    public function test_preview_api_projects_allowlisted_staging_asset_when_enabled(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('salary_asset_v1.locale', 'zh-CN')
            ->assertJsonPath('lineage.status', CareerJobSalaryAsset::STATUS_STAGING_PREVIEW);
    }

    public function test_preview_api_fails_closed_when_disabled_or_not_allowlisted(): void
    {
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        Config::set('career_salary_assets.staging_preview_enabled', false);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertNotFound();

        Config::set('career_salary_assets.staging_preview_enabled', true);
        Config::set('career_salary_assets.preview_slugs', ['actuaries']);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertNotFound();
    }

    private function seedOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'salary-preview-'.$slug,
            'title_en' => 'Salary Preview',
            'title_zh' => '薪资预览',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Accountants and Auditors',
            'canonical_title_zh' => '会计师和审计师',
            'search_h1_zh' => '会计师和审计师',
            'structural_stability' => 0.9,
            'task_prototype_signature' => ['analysis' => 0.8],
            'market_semantics_gap' => 0.1,
            'regulatory_divergence' => 0.1,
            'toolchain_divergence' => 0.1,
            'skill_gap_threshold' => 0.4,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeJsonl(array $rows): string
    {
        $path = storage_path('framework/testing/salary-preview-'.bin2hex(random_bytes(4)).'.jsonl');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, implode('', array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            $rows
        )));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRow(string $slug, string $locale): array
    {
        $hash = hash('sha256', $slug.'|'.$locale);

        return [
            'asset_type' => 'career_job_salary_asset',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'slug' => $slug,
            'locale' => $locale,
            'occupation' => [
                'title_en' => 'Accountants and Auditors',
                'title_zh' => '会计师和审计师',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
            ],
            'heading' => $locale === 'zh-CN' ? '会计师和审计师薪资与就业参考' : 'Accountants and Auditors salary reference',
            'summary' => [
                'headline' => 'Salary reference',
                'short_answer' => 'China uses recruitment-market evidence, not official wage data.',
                'confidence_label' => 'medium',
            ],
            'china_recruitment_reference' => [
                'heading' => '中国招聘市场参考',
                'evidence_status' => 'calculable',
                'display_monthly_range_cny' => '约 ¥6,000–10,000/月',
                'body' => $locale === 'zh-CN'
                    ? '中国大陆部分只使用招聘市场证据，不是官方职业中位薪资，也不是个人收入预测。'
                    : 'China salary content uses recruitment-market evidence and is not official Chinese wage data.',
                'data_boundary' => 'This is a China recruitment-market reference; it is not an official Chinese single-occupation median wage.',
                'facts' => [
                    'monthly_cny_low_observed' => 6000,
                    'monthly_cny_high_observed' => 10000,
                    'monthly_cny_average_observed' => null,
                    'monthly_cny_p25' => null,
                    'monthly_cny_median' => null,
                    'monthly_cny_p75' => null,
                ],
                'limitations' => ['Recruitment samples are not official wage statistics.'],
            ],
            'us_official_reference' => ['status' => 'available', 'facts' => ['median_annual_usd' => 81680]],
            'uk_reference' => ['status' => 'available', 'facts' => ['starter_annual_gbp' => 25000]],
            'eu_context_boundary' => ['status' => 'macro_context_only'],
            'salary_drivers' => array_fill(0, 5, ['factor' => 'Scope', 'description' => 'Role boundary changes pay.']),
            'reader_guidance' => array_fill(0, 4, 'Read China values as recruitment-market references only.'),
            'forbidden_claims' => [],
            'sources' => [[
                'market' => 'CN',
                'name' => 'JobUI',
                'url' => 'https://example.test/salary',
                'source_id' => 'cn_001',
            ]],
            'evidence_used' => ['cn_evidence_ids' => ['cn_001']],
            'derived_from_estimate' => [
                'source_estimate_file' => 'career_job_salary_estimates_1046_v3_6.jsonl',
                'estimate_schema_version' => 'career_job_salary_estimate_v3_6',
                'estimate_row_hash' => str_repeat('b', 64),
            ],
            'research_notes' => [],
            'audit_fields' => [
                'schema_version' => 'career_job_salary_asset_v3_6',
                'generated_at' => '2026-06-15T18:10:45Z',
                'ready_for_codex_audit' => true,
                'row_hash' => $hash,
            ],
        ];
    }
}
