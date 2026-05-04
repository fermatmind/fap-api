<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class CareerAlignD8AuthorityCrosswalksCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const HEADERS = [
        'Slug',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
    ];

    /** @var list<string> */
    private const SELECTED_SLUGS = [
        'software-developers',
        'web-developers',
        'marketing-managers',
        'lawyers',
        'pharmacists',
        'acupuncturists',
        'business-intelligence-analysts',
        'clinical-data-managers',
        'budget-analysts',
        'human-resources-managers',
        'administrative-services-managers',
        'advertising-and-promotions-managers',
        'architects',
        'air-traffic-controllers',
        'airline-and-commercial-pilots',
        'chemists-and-materials-scientists',
        'clinical-laboratory-technologists-and-technicians',
        'community-health-workers',
        'compensation-and-benefits-managers',
        'career-and-technical-education-teachers',
    ];

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:align-d8-authority-crosswalks', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('software-developers')]);
        $exitCode = Artisan::call('career:align-d8-authority-crosswalks', [
            '--file' => $workbook,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--slugs is required', Artisan::output());
    }

    #[Test]
    public function dry_run_and_force_together_are_rejected(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers', [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--dry-run and --force cannot be used together.', implode(' ', $report['errors']));
    }

    #[Test]
    public function non_selected_slug_fails(): void
    {
        $workbook = $this->writeWorkbook([$this->row('veterinarians', soc: '29-1131', onet: '29-1131.00')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'veterinarians');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
    }

    #[Test]
    public function default_dry_run_writes_zero_rows_and_reports_d8_create_plan(): void
    {
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::SELECTED_SLUGS));

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(20, $report['validated_count']);
        $this->assertSame(20, $report['would_create_occupation_count']);
        $this->assertSame(40, $report['would_create_crosswalk_count']);
        $this->assertFalse($report['display_assets_created']);
        $this->assertFalse($report['release_gates_changed']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function explicit_dry_run_writes_zero_rows(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $report['would_create_occupation_count']);
        $this->assertSame(2, $report['would_create_crosswalk_count']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function force_creates_exactly_twenty_occupations_and_forty_crosswalks(): void
    {
        $this->createProtectedOccupations();
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::SELECTED_SLUGS), ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertFalse($report['read_only']);
        $this->assertTrue($report['writes_database']);
        $this->assertSame(20, $report['created_occupation_count']);
        $this->assertSame(40, $report['created_crosswalk_count']);
        $this->assertSame(32, Occupation::query()->count());
        $this->assertSame(64, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());

        foreach ($this->selectedRows() as $row) {
            $occupation = Occupation::query()->where('canonical_slug', $row['Slug'])->firstOrFail();
            $this->assertSame($row['EN_Title'], $occupation->canonical_title_en);
            $this->assertSame($row['CN_Title'], $occupation->canonical_title_zh);
            $this->assertDatabaseHas('occupation_crosswalks', [
                'occupation_id' => $occupation->id,
                'source_system' => 'us_soc',
                'source_code' => $row['SOC_Code'],
                'source_title' => $row['EN_Title'],
                'mapping_type' => 'direct_match',
                'notes' => 'PR-D8b selected authority occupation and crosswalk alignment',
            ]);
            $this->assertDatabaseHas('occupation_crosswalks', [
                'occupation_id' => $occupation->id,
                'source_system' => 'onet_soc_2019',
                'source_code' => $row['O_NET_Code'],
                'source_title' => $row['EN_Title'],
                'mapping_type' => 'direct_match',
                'notes' => 'PR-D8b selected authority occupation and crosswalk alignment',
            ]);
        }

        foreach ($this->protectedSlugs() as $slug) {
            $this->assertDatabaseHas('occupations', [
                'canonical_slug' => $slug,
                'canonical_title_en' => 'Protected '.$slug,
            ]);
        }
    }

    #[Test]
    public function repeated_force_is_idempotent(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        $this->runAlign($workbook, 'software-developers', ['--force' => true]);
        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_occupation_count']);
        $this->assertSame(0, $report['created_crosswalk_count']);
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function missing_and_duplicate_workbook_rows_fail(): void
    {
        $workbook = $this->writeWorkbook([$this->row('financial-analysts')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Allowlisted slug software-developers was not found in workbook.', implode(' ', $report['errors']));

        $duplicateWorkbook = $this->writeWorkbook([
            $this->row('software-developers'),
            $this->row('software-developers'),
        ]);

        [$exitCode, $report] = $this->runAlign($duplicateWorkbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Allowlisted slug software-developers appears more than once in workbook.', implode(' ', $report['errors']));
    }

    #[Test]
    public function invalid_soc_and_onet_values_are_rejected(): void
    {
        $cases = [
            [$this->row('software-developers', soc: '', onet: '15-1253.00'), 'SOC_Code is missing.'],
            [$this->row('software-developers', soc: 'CN-15-1253', onet: '15-1253.00'), 'SOC_Code must be 15-1253.'],
            [$this->row('software-developers', soc: '15-1253', onet: ''), 'O_NET_Code is missing.'],
            [$this->row('software-developers', soc: '15-1253', onet: 'not_applicable_cn_occupation'), 'O_NET_Code must be 15-1253.00.'],
            [$this->row('software-developers', soc: '15-1253', onet: 'multiple_onet_occupations'), 'O_NET_Code must be 15-1253.00.'],
            [$this->row('software-developers', soc: '15-1253', onet: 'BLS_BROAD_GROUP'), 'O_NET_Code must be 15-1253.00.'],
        ];

        foreach ($cases as [$row, $expectedError]) {
            $workbook = $this->writeWorkbook([$row]);

            [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString($expectedError, implode(' ', $report['errors']));
        }
    }

    #[Test]
    public function conflicting_existing_crosswalks_are_rejected(): void
    {
        $occupation = $this->createOccupation('software-developers');
        $this->createCrosswalk($occupation, 'us_soc', '15-9999');
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing us_soc crosswalk conflicts with workbook SOC_Code.', implode(' ', $report['errors']));

        OccupationCrosswalk::query()->delete();
        $this->createCrosswalk($occupation, 'us_soc', '15-1253');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-9999.00');

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.', implode(' ', $report['errors']));
    }

    #[Test]
    public function duplicate_existing_crosswalks_are_rejected(): void
    {
        $occupation = $this->createOccupation('software-developers');
        $this->createCrosswalk($occupation, 'us_soc', '15-1253');
        $this->createCrosswalk($occupation, 'us_soc', '15-1253');
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing us_soc crosswalks found.', implode(' ', $report['errors']));

        OccupationCrosswalk::query()->delete();
        $this->createCrosswalk($occupation, 'us_soc', '15-1253');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-1253.00');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-1253.00');

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing onet_soc_2019 crosswalks found.', implode(' ', $report['errors']));
    }

    #[Test]
    public function existing_matching_occupation_with_missing_crosswalks_is_handled_safely(): void
    {
        $occupation = $this->createOccupation('software-developers');
        $this->createCrosswalk($occupation, 'us_soc', '15-1253');
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_occupation_count']);
        $this->assertSame(1, $report['created_crosswalk_count']);
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function docx_fallback_absence_of_authority_is_not_treated_as_existing_authority(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('local_db', $report['items'][0]['authority_source']);
        $this->assertFalse($report['items'][0]['occupation_found']);
        $this->assertNull($report['items'][0]['occupation_id']);
        $this->assertTrue($report['items'][0]['would_create_occupation']);
        $this->assertSame(1, $report['would_create_occupation_count']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runAlign(string $file, string $slugs, array $options = []): array
    {
        $exitCode = Artisan::call('career:align-d8-authority-crosswalks', array_merge([
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function createProtectedOccupations(): void
    {
        foreach ($this->protectedSlugs() as $slug) {
            $occupation = $this->createOccupation($slug, 'Protected '.$slug, 'Protected '.$slug);
            $this->createCrosswalk($occupation, 'us_soc', '00-0000');
            $this->createCrosswalk($occupation, 'onet_soc_2019', '00-0000.00');
        }
    }

    /**
     * @return list<string>
     */
    private function protectedSlugs(): array
    {
        return [
            'actors',
            'data-scientists',
            'registered-nurses',
            'accountants-and-auditors',
            'actuaries',
            'financial-analysts',
            'high-school-teachers',
            'market-research-analysts',
            'architectural-and-engineering-managers',
            'civil-engineers',
            'biomedical-engineers',
            'dentists',
        ];
    }

    private function createOccupation(string $slug, ?string $titleEn = null, ?string $titleZh = null): Occupation
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
            'canonical_title_en' => $titleEn ?? str_replace('-', ' ', $slug),
            'canonical_title_zh' => $titleZh ?? str_replace('-', ' ', $slug),
            'search_h1_zh' => $titleZh ?? str_replace('-', ' ', $slug),
        ]);
    }

    private function createCrosswalk(Occupation $occupation, string $system, string $code): void
    {
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => $system,
            'source_code' => $code,
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
            $this->row('software-developers', title: 'Software Developers', titleZh: '软件开发人员', soc: '15-1253', onet: '15-1253.00'),
            $this->row('web-developers', title: 'Web Developers', titleZh: '网页开发人员', soc: '15-1254', onet: '15-1254.00'),
            $this->row('marketing-managers', title: 'Marketing Managers', titleZh: '营销经理', soc: '11-2021', onet: '11-2021.00'),
            $this->row('lawyers', title: 'Lawyers', titleZh: '律师', soc: '23-1011', onet: '23-1011.00'),
            $this->row('pharmacists', title: 'Pharmacists', titleZh: '药剂师', soc: '29-1051', onet: '29-1051.00'),
            $this->row('acupuncturists', title: 'Acupuncturists', titleZh: '针灸师', soc: '29-1291', onet: '29-1291.00'),
            $this->row('business-intelligence-analysts', title: 'Business Intelligence Analysts', titleZh: '商业智能分析师', soc: '15-2051', onet: '15-2051.01'),
            $this->row('clinical-data-managers', title: 'Clinical Data Managers', titleZh: '临床数据经理', soc: '15-2051', onet: '15-2051.02'),
            $this->row('budget-analysts', title: 'Budget Analysts', titleZh: '预算分析师', soc: '13-2031', onet: '13-2031.00'),
            $this->row('human-resources-managers', title: 'Human Resources Managers', titleZh: '人力资源经理', soc: '11-3121', onet: '11-3121.00'),
            $this->row('administrative-services-managers', title: 'Administrative Services Managers', titleZh: '行政服务经理', soc: '11-3012', onet: '11-3012.00'),
            $this->row('advertising-and-promotions-managers', title: 'Advertising and Promotions Managers', titleZh: '广告与促销经理', soc: '11-2011', onet: '11-2011.00'),
            $this->row('architects', title: 'Architects', titleZh: '建筑师', soc: '17-1011', onet: '17-1011.00'),
            $this->row('air-traffic-controllers', title: 'Air Traffic Controllers', titleZh: '空中交通管制员', soc: '53-2021', onet: '53-2021.00'),
            $this->row('airline-and-commercial-pilots', title: 'Airline and Commercial Pilots', titleZh: '航空公司与商业飞行员', soc: '53-2011', onet: '53-2011.00'),
            $this->row('chemists-and-materials-scientists', title: 'Chemists and Materials Scientists', titleZh: '化学家与材料科学家', soc: '19-2031', onet: '19-2031.00'),
            $this->row('clinical-laboratory-technologists-and-technicians', title: 'Clinical Laboratory Technologists and Technicians', titleZh: '临床实验室技师和技术员', soc: '29-2011', onet: '29-2011.00'),
            $this->row('community-health-workers', title: 'Community Health Workers', titleZh: '社区健康工作者', soc: '21-1094', onet: '21-1094.00'),
            $this->row('compensation-and-benefits-managers', title: 'Compensation and Benefits Managers', titleZh: '薪酬与福利经理', soc: '11-3111', onet: '11-3111.00'),
            $this->row('career-and-technical-education-teachers', title: 'Career and Technical Education Teachers', titleZh: '职业与技术教育教师', soc: '25-2032', onet: '25-2032.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Software Developers',
        string $titleZh = '软件开发人员',
        string $soc = '15-1253',
        string $onet = '15-1253.00',
    ): array {
        return [
            'Slug' => $slug,
            'SOC_Code' => $soc,
            'O_NET_Code' => $onet,
            'EN_Title' => $title,
            'CN_Title' => $titleZh,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $headers
     */
    private function writeWorkbook(array $rows, ?array $headers = null): string
    {
        $headers ??= self::HEADERS;
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
        $dir = sys_get_temp_dir().'/career-align-d8-authority-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
