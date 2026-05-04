<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Import\CareerSelectedDisplayAssetMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class CareerImportSelectedDisplayAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:import-selected-display-assets', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);
        $exitCode = Artisan::call('career:import-selected-display-assets', [
            '--file' => $workbook,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--slugs is required', Artisan::output());
    }

    #[Test]
    public function dry_run_and_force_together_are_rejected(): void
    {
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists', [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--dry-run and --force cannot be used together.', implode(' ', $report['errors']));
    }

    #[Test]
    public function default_dry_run_generates_payloads_without_writing_database_rows(): void
    {
        foreach ($this->selectedRows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->selectedRows());
        $before = $this->tableCounts();

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists,registered-nurses,accountants-and-auditors');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(3, $report['would_write_count']);
        $this->assertSame(0, $report['created_count']);
        $this->assertSame(3, $report['validated_count']);

        foreach ($report['items'] as $item) {
            $this->assertTrue($item['would_write']);
            $this->assertSame(24, $item['payload_summary']['component_order_count']);
            $this->assertTrue($item['payload_summary']['has_zh_page']);
            $this->assertTrue($item['payload_summary']['has_en_page']);
            $this->assertSame([], $item['payload_summary']['public_payload_forbidden_keys_found']);
        }
    }

    #[Test]
    public function d5_selected_slugs_dry_run_generates_payloads_without_writing_database_rows(): void
    {
        foreach ($this->d5Rows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->d5Rows());
        $before = $this->tableCounts();

        [$exitCode, $report] = $this->runImport($workbook, implode(',', array_column($this->d5Rows(), 'Slug')), [
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame('dry_run', $report['mode']);
        $this->assertSame(8, $report['validated_count']);
        $this->assertSame(8, $report['would_write_count']);
        $this->assertFalse($report['did_write']);

        foreach ($report['items'] as $item) {
            $this->assertTrue($item['would_write']);
            $this->assertSame(24, $item['payload_summary']['component_order_count']);
            $this->assertTrue($item['payload_summary']['has_zh_page']);
            $this->assertTrue($item['payload_summary']['has_en_page']);
            $this->assertSame([], $item['payload_summary']['public_payload_forbidden_keys_found']);
        }
    }

    #[Test]
    public function d8_active_slugs_dry_run_generates_payloads_without_importing_manual_hold(): void
    {
        foreach ($this->d8Rows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->d8Rows());
        $before = $this->tableCounts();

        [$exitCode, $report] = $this->runImport($workbook, implode(',', array_column($this->d8Rows(), 'Slug')), [
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame('dry_run', $report['mode']);
        $this->assertSame(19, $report['validated_count']);
        $this->assertSame(19, $report['would_write_count']);
        $this->assertFalse($report['did_write']);

        foreach ($report['items'] as $item) {
            $this->assertNotSame('software-developers', $item['slug']);
            $this->assertTrue($item['would_write']);
            $this->assertSame(24, $item['payload_summary']['component_order_count']);
            $this->assertTrue($item['payload_summary']['has_zh_page']);
            $this->assertTrue($item['payload_summary']['has_en_page']);
            $this->assertSame([], $item['payload_summary']['public_payload_forbidden_keys_found']);
        }

        [$exitCode, $report] = $this->runImport(
            $this->writeWorkbook([
                $this->row('software-developers', title: 'Software Developers', cnTitle: '软件开发人员', soc: '15-1252', onet: '15-1252.00'),
            ]),
            'software-developers',
            ['--dry-run' => true],
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported slug(s) for selected display asset import: software-developers.', implode(' ', $report['errors']));
    }

    #[Test]
    public function force_writes_exactly_three_display_asset_rows(): void
    {
        foreach ($this->selectedRows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists,registered-nurses,accountants-and-auditors', [
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertFalse($report['read_only']);
        $this->assertTrue($report['writes_database']);
        $this->assertTrue($report['did_write']);
        $this->assertSame(3, $report['created_count']);
        $this->assertSame(3, Occupation::query()->count());
        $this->assertSame(6, OccupationCrosswalk::query()->count());
        $this->assertSame(3, CareerJobDisplayAsset::query()->count());

        foreach ($this->selectedRows() as $row) {
            $this->assertDatabaseHas('career_job_display_assets', [
                'canonical_slug' => $row['Slug'],
                'asset_version' => 'v4.2',
                'template_version' => 'v4.2',
                'asset_type' => 'career_job_public_display',
                'asset_role' => 'formal_pilot_master',
                'status' => 'ready_for_pilot',
            ]);
        }
    }

    #[Test]
    public function force_writes_d5_display_assets_only_and_is_idempotent(): void
    {
        foreach ($this->d5Rows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->d5Rows());
        $slugs = implode(',', array_column($this->d5Rows(), 'Slug'));

        [$exitCode, $report] = $this->runImport($workbook, $slugs, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertTrue($report['did_write']);
        $this->assertSame(8, $report['created_count']);
        $this->assertSame(0, $report['updated_count']);
        $this->assertSame(8, Occupation::query()->count());
        $this->assertSame(16, OccupationCrosswalk::query()->count());
        $this->assertSame(8, CareerJobDisplayAsset::query()->count());

        [$exitCode, $report] = $this->runImport($workbook, $slugs, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_count']);
        $this->assertSame(8, $report['updated_count']);
        $this->assertSame(8, Occupation::query()->count());
        $this->assertSame(16, OccupationCrosswalk::query()->count());
        $this->assertSame(8, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function force_writes_d8_display_assets_only_with_lineage_and_is_idempotent(): void
    {
        foreach ($this->d8Rows() as $row) {
            $this->createAuthorityOccupation($row['Slug'], $row['SOC_Code'], $row['O_NET_Code']);
        }
        $workbook = $this->writeWorkbook($this->d8Rows());
        $slugs = implode(',', array_column($this->d8Rows(), 'Slug'));

        [$exitCode, $report] = $this->runImport($workbook, $slugs, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertTrue($report['did_write']);
        $this->assertSame(19, $report['created_count']);
        $this->assertSame(0, $report['updated_count']);
        $this->assertSame(19, Occupation::query()->count());
        $this->assertSame(38, OccupationCrosswalk::query()->count());
        $this->assertSame(19, CareerJobDisplayAsset::query()->count());
        $this->assertDatabaseMissing('career_job_display_assets', ['canonical_slug' => 'software-developers']);

        foreach ($this->d8Rows() as $row) {
            $asset = CareerJobDisplayAsset::query()
                ->where('canonical_slug', $row['Slug'])
                ->where('asset_version', 'v4.2')
                ->firstOrFail();
            $metadata = $asset->metadata_json;

            $this->assertSame('display.surface.v1', $asset->surface_version);
            $this->assertSame('v4.2', $asset->template_version);
            $this->assertSame('career_job_public_display', $asset->asset_type);
            $this->assertSame('ready_for_pilot', $asset->status);
            $this->assertCount(24, $asset->component_order_json);
            $this->assertIsArray(data_get($asset->page_payload_json, 'page.zh'));
            $this->assertIsArray(data_get($asset->page_payload_json, 'page.en'));
            $this->assertSame('career:import-selected-display-assets', data_get($metadata, 'command'));
            $this->assertSame(hash_file('sha256', $workbook), data_get($metadata, 'workbook_sha256'));
            $this->assertIsInt(data_get($metadata, 'row_number'));
            $this->assertIsString(data_get($metadata, 'row_fingerprint'));
            $this->assertSame(false, data_get($metadata, 'release_gates.sitemap'));
            $this->assertStringNotContainsString('"@type":"Product"', json_encode($asset->structured_data_json, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('release_gates', json_encode($asset->page_payload_json, JSON_THROW_ON_ERROR));
        }

        [$exitCode, $report] = $this->runImport($workbook, $slugs, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_count']);
        $this->assertSame(19, $report['updated_count']);
        $this->assertSame(19, Occupation::query()->count());
        $this->assertSame(38, OccupationCrosswalk::query()->count());
        $this->assertSame(19, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function repeated_force_is_idempotent(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        $this->runImport($workbook, 'data-scientists', ['--force' => true]);
        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_count']);
        $this->assertSame(1, $report['updated_count']);
        $this->assertSame(1, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function missing_authority_rows_fail_without_creating_occupations_or_crosswalks(): void
    {
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Occupation is missing', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function missing_soc_or_onet_crosswalk_fails(): void
    {
        $this->createOccupation('data-scientists');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing us_soc crosswalk must match 15-2051.', implode(' ', $report['errors']));

        $this->createSoc(Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail(), '15-2051');
        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing onet_soc_2019 crosswalk must match 15-2051.00.', implode(' ', $report['errors']));
    }

    #[Test]
    public function workbook_soc_or_onet_mismatch_fails(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');

        $workbook = $this->writeWorkbook([$this->row('data-scientists', soc: '15-9999')]);
        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('SOC_Code must be 15-2051.', implode(' ', $report['errors']));

        $workbook = $this->writeWorkbook([$this->row('data-scientists', onet: '15-9999.00')]);
        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('O_NET_Code must be 15-2051.00.', implode(' ', $report['errors']));
    }

    #[Test]
    public function slug_outside_selected_allowlist_fails(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers', soc: '15-1252', onet: '15-1252.00')]);

        [$exitCode, $report] = $this->runImport($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
    }

    #[Test]
    public function unsafe_schema_or_forbidden_public_keys_fail(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $row = $this->row('data-scientists');
        $row['EN_Occupation_Schema_JSON'] = $this->encodeJson(['@type' => 'Product', 'name' => 'Bad', 'occupationalCategory' => '15-2051']);
        $row['Claim_Level_Source_Refs'] = $this->encodeJson([
            'tracking_json' => ['url' => 'https://www.bls.gov/example', 'claim' => 'salary wage growth outlook jobs employment'],
            'interpretation' => ['label' => 'FermatMind interpretation', 'url' => 'https://www.onetonline.org/link/details/15-2051.00'],
            'jobs' => ['url' => 'https://www.bls.gov/emp/', 'claim' => 'jobs employment'],
        ]);
        $row['EN_Comparison_Block'] = $this->encodeJson([
            'release_gates' => ['sitemap' => false],
        ]);
        $workbook = $this->writeWorkbook([$row]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertContains('page_payload_json.page.en.adjacent_career_comparison_table.release_gates', data_get($report, 'items.0.payload_summary.public_payload_forbidden_keys_found', []));
        $errors = implode(' ', $report['errors']);
        $this->assertStringContainsString('EN_Occupation_Schema_JSON must not include Product schema.', $errors);
        $this->assertStringContainsString('Forbidden public payload keys found', $errors);
    }

    #[Test]
    public function biomedical_engineers_product_substring_blocker_is_rejected_if_reintroduced(): void
    {
        $this->createAuthorityOccupation('biomedical-engineers', '17-2031', '17-2031.00');
        $row = $this->row(
            'biomedical-engineers',
            title: 'Bioengineers and biomedical engineers',
            cnTitle: '生物工程师与生物医学工程师',
            soc: '17-2031',
            onet: '17-2031.00',
        );
        $row['EN_Occupation_Schema_JSON'] = $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => 'Bioengineers and biomedical engineers',
            'occupationalCategory' => '17-2031',
            'description' => 'Design systems and products for healthcare settings.',
        ]);
        $workbook = $this->writeWorkbook([$row]);

        [$exitCode, $report] = $this->runImport($workbook, 'biomedical-engineers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'EN_Occupation_Schema_JSON must not include Product schema.',
            implode(' ', $report['errors']),
        );
    }

    #[Test]
    public function hidden_faq_schema_fails(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $row = $this->row('data-scientists');
        $faq = json_decode($row['EN_FAQ_SCHEMA_JSON'], true, 512, JSON_THROW_ON_ERROR);
        $faq['hidden_faq'] = [['name' => 'Do not show']];
        $row['EN_FAQ_SCHEMA_JSON'] = $this->encodeJson($faq);
        $workbook = $this->writeWorkbook([$row]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('en_faq must not contain hidden FAQ schema.', implode(' ', $report['errors']));
    }

    #[Test]
    public function command_created_selected_asset_is_available_to_display_surface_builder(): void
    {
        $occupation = $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runImport($workbook, 'data-scientists', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');
        $this->assertIsArray($surface);
        $this->assertSame('data-scientists', $surface['subject']['canonical_slug']);
        $this->assertSame('display.surface.v1', $surface['surface_version']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runImport(string $file, string $slugs, array $options = []): array
    {
        $exitCode = Artisan::call('career:import-selected-display-assets', array_merge([
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'occupations' => Occupation::query()->count(),
            'occupation_crosswalks' => OccupationCrosswalk::query()->count(),
            'career_job_display_assets' => CareerJobDisplayAsset::query()->count(),
        ];
    }

    private function createAuthorityOccupation(string $slug, string $soc, string $onet): Occupation
    {
        $occupation = $this->createOccupation($slug);
        $this->createSoc($occupation, $soc);
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => $onet,
            'source_title' => $occupation->canonical_title_en,
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);

        return $occupation;
    }

    private function createOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'pilot-family'],
            [
                'title_en' => 'Pilot Family',
                'title_zh' => '试点职业族',
            ],
        );

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

    private function createSoc(Occupation $occupation, string $soc): void
    {
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => $soc,
            'source_title' => $occupation->canonical_title_en,
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function selectedRows(): array
    {
        return [
            $this->row('data-scientists'),
            $this->row('registered-nurses', title: 'Registered Nurses', cnTitle: '注册护士', soc: '29-1141', onet: '29-1141.00'),
            $this->row('accountants-and-auditors', title: 'Accountants and Auditors', cnTitle: '会计与审计人员', soc: '13-2011', onet: '13-2011.00'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function d5Rows(): array
    {
        return [
            $this->row('actuaries', title: 'Actuaries', cnTitle: '精算师', soc: '15-2011', onet: '15-2011.00'),
            $this->row('financial-analysts', title: 'Financial and Investment Analysts', cnTitle: '金融与投资分析师', soc: '13-2051', onet: '13-2051.00'),
            $this->row('high-school-teachers', title: 'Secondary School Teachers, Except Special and Career/Technical Education', cnTitle: '高中教师（不含特殊与职业技术教育）', soc: '25-2031', onet: '25-2031.00'),
            $this->row('market-research-analysts', title: 'Market Research Analysts and Marketing Specialists', cnTitle: '市场研究分析师与营销专员', soc: '13-1161', onet: '13-1161.00'),
            $this->row('architectural-and-engineering-managers', title: 'Architectural and Engineering Managers', cnTitle: '建筑与工程经理', soc: '11-9041', onet: '11-9041.00'),
            $this->row('civil-engineers', title: 'Civil Engineers', cnTitle: '土木工程师', soc: '17-2051', onet: '17-2051.00'),
            $this->row('biomedical-engineers', title: 'Bioengineers and Biomedical Engineers', cnTitle: '生物工程师与生物医学工程师', soc: '17-2031', onet: '17-2031.00'),
            $this->row('dentists', title: 'Dentists, General', cnTitle: '普通牙医', soc: '29-1021', onet: '29-1021.00'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function d8Rows(): array
    {
        return [
            $this->row('web-developers', title: 'Web Developers', cnTitle: '网页开发人员', soc: '15-1254', onet: '15-1254.00'),
            $this->row('marketing-managers', title: 'Marketing Managers', cnTitle: '营销经理', soc: '11-2021', onet: '11-2021.00'),
            $this->row('lawyers', title: 'Lawyers', cnTitle: '律师', soc: '23-1011', onet: '23-1011.00'),
            $this->row('pharmacists', title: 'Pharmacists', cnTitle: '药剂师', soc: '29-1051', onet: '29-1051.00'),
            $this->row('acupuncturists', title: 'Acupuncturists', cnTitle: '针灸师', soc: '29-1291', onet: '29-1291.00'),
            $this->row('business-intelligence-analysts', title: 'Business Intelligence Analysts', cnTitle: '商业智能分析师', soc: '15-2051', onet: '15-2051.01'),
            $this->row('clinical-data-managers', title: 'Clinical Data Managers', cnTitle: '临床数据经理', soc: '15-2051', onet: '15-2051.02'),
            $this->row('budget-analysts', title: 'Budget Analysts', cnTitle: '预算分析师', soc: '13-2031', onet: '13-2031.00'),
            $this->row('human-resources-managers', title: 'Human Resources Managers', cnTitle: '人力资源经理', soc: '11-3121', onet: '11-3121.00'),
            $this->row('administrative-services-managers', title: 'Administrative Services Managers', cnTitle: '行政服务经理', soc: '11-3012', onet: '11-3012.00'),
            $this->row('advertising-and-promotions-managers', title: 'Advertising and Promotions Managers', cnTitle: '广告与促销经理', soc: '11-2011', onet: '11-2011.00'),
            $this->row('architects', title: 'Architects, Except Landscape and Naval', cnTitle: '建筑师（不含景观与船舶）', soc: '17-1011', onet: '17-1011.00'),
            $this->row('air-traffic-controllers', title: 'Air Traffic Controllers', cnTitle: '空中交通管制员', soc: '53-2021', onet: '53-2021.00'),
            $this->row('airline-and-commercial-pilots', title: 'Airline and Commercial Pilots', cnTitle: '航空公司与商业飞行员', soc: '53-2011', onet: '53-2011.00'),
            $this->row('chemists-and-materials-scientists', title: 'Chemists', cnTitle: '化学家', soc: '19-2031', onet: '19-2031.00'),
            $this->row('clinical-laboratory-technologists-and-technicians', title: 'Medical and Clinical Laboratory Technologists', cnTitle: '医学与临床实验室技师', soc: '29-2011', onet: '29-2011.00'),
            $this->row('community-health-workers', title: 'Community Health Workers', cnTitle: '社区健康工作者', soc: '21-1094', onet: '21-1094.00'),
            $this->row('compensation-and-benefits-managers', title: 'Compensation and Benefits Managers', cnTitle: '薪酬与福利经理', soc: '11-3111', onet: '11-3111.00'),
            $this->row('career-and-technical-education-teachers', title: 'Career/Technical Education Teachers, Secondary School', cnTitle: '中学职业/技术教育教师', soc: '25-2032', onet: '25-2032.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Data Scientists',
        string $cnTitle = '数据科学家',
        string $soc = '15-2051',
        string $onet = '15-2051.00',
    ): array {
        $row = array_fill_keys(CareerSelectedDisplayAssetMapper::REQUIRED_HEADERS, '');
        $row['Asset_Version'] = 'v4.2';
        $row['Locale'] = 'bilingual';
        $row['Slug'] = $slug;
        $row['Job_ID'] = $slug;
        $row['SOC_Code'] = $soc;
        $row['O_NET_Code'] = $onet;
        $row['EN_Title'] = $title;
        $row['CN_Title'] = $cnTitle;
        $row['Content_Status'] = 'approved';
        $row['Review_State'] = 'human_reviewed';
        $row['Release_Status'] = 'ready_for_pilot';
        $row['QA_Status'] = 'ready_for_technical_validation';
        $row['Last_Reviewed'] = '2026-05-01';
        $row['Next_Review_Due'] = '2026-11-01';
        $row['EN_SEO_Title'] = $title.' Career Guide';
        $row['EN_SEO_Description'] = 'Career decision evidence.';
        $row['CN_SEO_Title'] = $cnTitle.'职业判断';
        $row['CN_SEO_Description'] = '职业判断证据。';
        $row['EN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['CN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['Search_Intent_Type'] = $this->encodeJson(['type' => 'career_decision']);
        $row['EN_H1'] = $title;
        $row['CN_H1'] = $cnTitle;
        $row['EN_Quick_Answer'] = 'Quick answer';
        $row['CN_Quick_Answer'] = '快速回答';
        $row['EN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
        $row['CN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
        $row['CN_Salary_Data_Type'] = 'reference';
        $row['CN_Snapshot_Data_Limitation'] = 'sample-only';
        $row['EN_Definition'] = 'Definition';
        $row['CN_Definition'] = '定义';
        $row['EN_Responsibilities'] = $this->encodeJson(['items' => ['Analyze data']]);
        $row['CN_Responsibilities'] = $this->encodeJson(['items' => ['分析数据']]);
        $row['EN_Comparison_Block'] = $this->encodeJson(['rows' => [['label' => 'adjacent']]]);
        $row['CN_Comparison_Block'] = $this->encodeJson(['rows' => [['label' => '相邻职业']]]);
        $row['EN_How_To_Decide_Fit'] = $this->encodeJson(['items' => ['Check interests']]);
        $row['CN_How_To_Decide_Fit'] = $this->encodeJson(['items' => ['检查兴趣']]);
        $row['EN_RIASEC_Fit'] = $this->encodeJson(['primary' => 'Investigative']);
        $row['CN_RIASEC_Fit'] = $this->encodeJson(['primary' => '研究型']);
        $row['EN_Personality_Fit'] = $this->encodeJson(['traits' => ['high openness']]);
        $row['CN_Personality_Fit'] = $this->encodeJson(['traits' => ['开放性较高']]);
        $row['EN_Caveat'] = 'Caveat';
        $row['CN_Caveat'] = '提醒';
        $row['EN_Next_Steps'] = 'Next steps';
        $row['CN_Next_Steps'] = '下一步';
        $row['AI_Exposure_Score_Raw'] = '8.2';
        $row['AI_Exposure_Score_Normalized'] = '82';
        $row['AI_Exposure_Label'] = 'medium';
        $row['AI_Exposure_Source'] = 'FermatMind interpretation';
        $row['AI_Exposure_Explanation'] = 'FermatMind interpretation string';
        $row['EN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['CN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['EN_Occupation_Schema_JSON'] = $this->occupationSchema($title, $soc);
        $row['CN_Occupation_Schema_JSON'] = $this->occupationSchema($cnTitle, $soc);
        $row['Claim_Level_Source_Refs'] = $this->sourceRefs($onet);
        $row['EN_Internal_Links'] = $this->internalLinks('en');
        $row['CN_Internal_Links'] = $this->internalLinks('zh');
        $row['Primary_CTA_Label'] = 'Take the Holland / RIASEC Career Interest Test';
        $row['Primary_CTA_URL'] = '/en/tests/holland-career-interest-test-riasec';
        $row['Primary_CTA_Target_Action'] = 'start_riasec_test';
        $row['Secondary_CTA_Label'] = 'Secondary';
        $row['Secondary_CTA_URL'] = $this->encodeJson(['/en/tests/holland-career-interest-test-riasec']);
        $row['Entry_Surface'] = 'career_job_detail';
        $row['Source_Page_Type'] = 'career_job_detail';
        $row['Subject_Type'] = 'job_slug';
        $row['Subject_Slug'] = $slug;
        $row['Primary_Test_Slug'] = 'holland-career-interest-test-riasec';
        $row['Ready_For_Sitemap'] = 'false';
        $row['Ready_For_LLMS'] = 'false';
        $row['Ready_For_Paid'] = 'false';

        return $row;
    }

    private function faq(): string
    {
        return $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Question 1', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
                ['@type' => 'Question', 'name' => 'Question 2', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
                ['@type' => 'Question', 'name' => 'Question 3', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
            ],
        ]);
    }

    private function occupationSchema(string $name, string $category): string
    {
        return $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => $name,
            'occupationalCategory' => $category,
        ]);
    }

    private function sourceRefs(string $onet): string
    {
        return $this->encodeJson([
            'soc_onet_identity' => [
                'source_urls' => [
                    'https://www.onetonline.org/link/details/'.$onet,
                    'https://www.bls.gov/ooh/example.htm',
                ],
                'claims' => ['official occupation identity'],
            ],
            'us_employment_wage_outlook' => [
                'url' => 'https://www.bls.gov/ooh/example.htm',
                'claims' => ['2024 jobs: 1,000', '2024 median salary/wage/pay: $100,000', 'growth outlook: 5%'],
            ],
            'fermatmind_interpretation' => [
                'label' => 'FermatMind interpretation',
                'usage' => 'FermatMind synthesis of official occupational sources and market-signal references.',
            ],
        ]);
    }

    private function internalLinks(string $locale): string
    {
        return $this->encodeJson([
            'related_tests' => [
                '/'.$locale.'/tests/holland-career-interest-test-riasec',
                '/'.$locale.'/tests/mbti-personality-test-16-personality-types',
            ],
            'related_jobs' => [],
            'related_guides' => [],
            'validation_policy' => [
                'related_tests' => 'stable_routes_allowed',
                'related_jobs' => 'requires_later_live_validation',
                'related_guides' => 'requires_later_live_validation',
                'render_policy' => 'hide_or_plain_text_if_unvalidated',
            ],
        ]);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeWorkbook(array $rows): string
    {
        $headers = CareerSelectedDisplayAssetMapper::REQUIRED_HEADERS;
        $path = $this->tempDir().'/career_assets.xlsx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Career_Assets_v4_1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows, $headers));
        $this->assertTrue($zip->close());

        return $path;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $headers
     */
    private function sheetXml(array $rows, array $headers): string
    {
        $xmlRows = [$this->xmlRow(1, $headers)];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = $row[$header] ?? '';
            }
            $xmlRows[] = $this->xmlRow($index + 2, $values);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  list<string>  $values
     */
    private function xmlRow(int $rowNumber, array $values): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $ref = $this->columnName($index + 1).$rowNumber;
            $cells[] = '<c r="'.$ref.'" t="inlineStr"><is><t>'.$this->escape($value).'</t></is></c>';
        }

        return '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(($number % 26) + 65).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/career-import-selected-display-assets-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
