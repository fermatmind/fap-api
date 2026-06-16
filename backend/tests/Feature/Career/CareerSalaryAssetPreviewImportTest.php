<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobSalaryAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerSalaryAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedRuntimeProjectionAuthority([]);
    }

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
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
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
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
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

    public function test_importer_force_blocks_when_career_job_bundle_authority_is_missing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-authority-fail.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('missing_career_job_bundle_authority', implode(' ', $decoded['errors']));
        $this->assertSame(0, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_preview_api_projects_allowlisted_staging_asset_when_enabled(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $row['sources'][0]['name'] = '//';
        $row['sources'][0]['url'] = 'https://www.jobui.com/salary/quanguo-kuaiji/';
        $row['sources'][0]['used_for'] = 'CN evidence cn_001: internal ledger wording must not be reader-facing.';
        $row['china_recruitment_reference']['facts']['range_source_evidence_ids'] = ['cn_001'];
        $row['us_official_reference']['source_ids'] = ['us_001'];
        $row['uk_reference']['source_id'] = 'uk_001';
        $row['eu_context_boundary']['source_id'] = 'eu_001';
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

        $response = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('salary_asset_v1.locale', 'zh-CN');

        $payload = $response->json();
        $this->assertArrayNotHasKey('lineage', $payload);
        $asset = $payload['salary_asset_v1'];
        foreach (['research_notes', 'audit_fields', 'evidence_used', 'derived_from_estimate', 'forbidden_claims'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $asset);
        }

        $this->assertSame('职友集/JobUI', $asset['sources'][0]['name']);
        $this->assertSame('中国招聘市场参考', $asset['sources'][0]['used_for']);
        $this->assertArrayNotHasKey('source_id', $asset['sources'][0]);
        $this->assertArrayNotHasKey('range_source_evidence_ids', $asset['china_recruitment_reference']['facts']);
        $this->assertArrayNotHasKey('source_ids', $asset['us_official_reference']);
        $this->assertArrayNotHasKey('source_id', $asset['uk_reference']);
        $this->assertArrayNotHasKey('source_id', $asset['eu_context_boundary']);
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

    public function test_importer_blocks_reader_facing_editorial_quality_failures(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $zh = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $en = $this->assetRow('accountants-and-auditors', 'en');
        foreach ([&$zh, &$en] as &$row) {
            $row['sources'][0]['name'] = '//';
            $row['salary_drivers'] = array_fill(0, 5, [
                'factor' => '岗位边界',
                'description' => '会计师和审计师 的薪资会随具体岗位标题、职责范围和相邻岗位口径变化。',
            ]);
            $row['reader_guidance'] = array_fill(0, 4, '中国薪资只读作招聘市场样本信号，不读作官方全国职业工资。');
        }
        unset($row);

        $file = $this->writeJsonl([$zh, $en]);
        $report = storage_path('framework/testing/salary-preview-editorial-gate-fail.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertSame(0, $decoded['editorial_quality_gate']['ready_row_count']);
        $errors = implode(' ', $decoded['errors']);
        $this->assertStringContainsString('salary_preview_editorial_gate', $errors);
        $this->assertStringContainsString('reader-safe source label', $errors);
        $this->assertStringContainsString('generic description', $errors);
        $this->assertStringContainsString('generic sentence', $errors);
    }

    private function seedCareerJobBundleAuthority(string $slug): void
    {
        $this->seedRuntimeProjectionAuthority([$slug]);
        app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'zh-CN');
        app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'en');
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedRuntimeProjectionAuthority(array $slugs): void
    {
        $detailRouteEnabled = [];
        $robotsIndexable = [];
        $releaseGatePass = [];
        $items = [];

        foreach ($slugs as $slug) {
            $normalizedSlug = strtolower(trim($slug));
            if ($normalizedSlug === '') {
                continue;
            }

            $detailRouteEnabled[$normalizedSlug] = true;
            $robotsIndexable[$normalizedSlug] = true;
            $releaseGatePass[$normalizedSlug] = true;
            foreach (['en', 'zh'] as $locale) {
                $items[$normalizedSlug.'|'.$locale] = [
                    'slug' => $normalizedSlug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'runtime_publish_state' => 'published',
                ];
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                detailRouteEnabled: $detailRouteEnabled,
                robotsIndexable: $robotsIndexable,
                releaseGatePass: $releaseGatePass,
                items: $items,
            ),
        );
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
            'salary_drivers' => $locale === 'zh-CN'
                ? [
                    ['factor' => '审计季节性', 'description' => '审计旺季的加班、出差和项目密度会影响总收入与补贴结构。'],
                    ['factor' => '证书与签字责任', 'description' => 'CPA、税务经验和是否承担签字或复核责任会改变岗位定价。'],
                    ['factor' => '行业账务复杂度', 'description' => '制造、金融、互联网或跨境业务的准则复杂度不同，薪酬带宽也不同。'],
                    ['factor' => '系统能力', 'description' => '熟悉 ERP、合并报表、成本核算和数据分析工具的候选人通常更有议价空间。'],
                    ['factor' => '机构类型', 'description' => '事务所、企业财务、内审和咨询岗位的绩效奖金与晋升节奏不同。'],
                ]
                : [
                    ['factor' => 'Audit season load', 'description' => 'Busy-season overtime, travel, and project density can change total compensation and allowances.'],
                    ['factor' => 'Licensure and sign-off responsibility', 'description' => 'CPA status, tax exposure, and review or sign-off accountability materially affect pay.'],
                    ['factor' => 'Industry accounting complexity', 'description' => 'Manufacturing, finance, internet, and cross-border reporting roles price accounting complexity differently.'],
                    ['factor' => 'Systems capability', 'description' => 'ERP, consolidation, cost accounting, and analytics skills can improve negotiation leverage.'],
                    ['factor' => 'Employer setting', 'description' => 'Public accounting, corporate finance, internal audit, and advisory roles use different bonus and promotion models.'],
                ],
            'reader_guidance' => $locale === 'zh-CN'
                ? [
                    '先分清样本是审计、税务、企业财务还是内审岗位，再比较薪资。',
                    '中国区间只代表招聘市场样本，不代表官方职业工资或个人收入预测。',
                    '美国和英国数据要按 SOC、职业 profile 和统计年份边界阅读。',
                    '比较 offer 时同时看忙季强度、证书要求、出差、奖金和晋升路径。',
                ]
                : [
                    'Separate audit, tax, corporate accounting, and internal-audit roles before comparing pay.',
                    'Read the China range only as recruitment-market evidence, not an official wage or personal prediction.',
                    'Read US and UK figures within their SOC, profile, source-year, and coverage boundaries.',
                    'Compare offers alongside busy-season load, certification requirements, travel, bonuses, and promotion path.',
                ],
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
