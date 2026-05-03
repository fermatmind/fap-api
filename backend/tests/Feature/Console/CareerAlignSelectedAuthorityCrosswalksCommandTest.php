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

final class CareerAlignSelectedAuthorityCrosswalksCommandTest extends TestCase
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
        'actuaries',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'architectural-and-engineering-managers',
        'civil-engineers',
        'biomedical-engineers',
        'dentists',
    ];

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:align-selected-authority-crosswalks', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('actuaries')]);
        $exitCode = Artisan::call('career:align-selected-authority-crosswalks', [
            '--file' => $workbook,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--slugs is required', Artisan::output());
    }

    #[Test]
    public function dry_run_and_force_together_are_rejected(): void
    {
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries', [
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
        $workbook = $this->writeWorkbook([$this->row('software-developers', soc: '15-1252', onet: '15-1252.00')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
    }

    #[Test]
    public function default_dry_run_writes_zero_rows_and_reports_selected_create_plan(): void
    {
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::SELECTED_SLUGS));

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(8, $report['validated_count']);
        $this->assertSame(8, $report['would_create_occupation_count']);
        $this->assertSame(16, $report['would_create_crosswalk_count']);
        $this->assertFalse($report['display_assets_created']);
        $this->assertFalse($report['release_gates_changed']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function explicit_dry_run_writes_zero_rows(): void
    {
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $report['would_create_occupation_count']);
        $this->assertSame(2, $report['would_create_crosswalk_count']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function force_creates_exactly_eight_occupations_and_sixteen_crosswalks(): void
    {
        $this->createProtectedOccupations();
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::SELECTED_SLUGS), ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertFalse($report['read_only']);
        $this->assertTrue($report['writes_database']);
        $this->assertSame(8, $report['created_occupation_count']);
        $this->assertSame(16, $report['created_crosswalk_count']);
        $this->assertSame(12, Occupation::query()->count());
        $this->assertSame(24, OccupationCrosswalk::query()->count());
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
                'notes' => 'PR-D5b selected authority occupation and crosswalk alignment',
            ]);
            $this->assertDatabaseHas('occupation_crosswalks', [
                'occupation_id' => $occupation->id,
                'source_system' => 'onet_soc_2019',
                'source_code' => $row['O_NET_Code'],
                'source_title' => $row['EN_Title'],
                'mapping_type' => 'direct_match',
                'notes' => 'PR-D5b selected authority occupation and crosswalk alignment',
            ]);
        }

        foreach (['actors', 'data-scientists', 'registered-nurses', 'accountants-and-auditors'] as $slug) {
            $this->assertDatabaseHas('occupations', [
                'canonical_slug' => $slug,
                'canonical_title_en' => 'Protected '.$slug,
            ]);
        }
    }

    #[Test]
    public function repeated_force_is_idempotent(): void
    {
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        $this->runAlign($workbook, 'actuaries', ['--force' => true]);
        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries', ['--force' => true]);

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

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Allowlisted slug actuaries was not found in workbook.', implode(' ', $report['errors']));

        $duplicateWorkbook = $this->writeWorkbook([
            $this->row('actuaries'),
            $this->row('actuaries'),
        ]);

        [$exitCode, $report] = $this->runAlign($duplicateWorkbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Allowlisted slug actuaries appears more than once in workbook.', implode(' ', $report['errors']));
    }

    #[Test]
    public function invalid_soc_and_onet_values_are_rejected(): void
    {
        $cases = [
            [$this->row('actuaries', soc: '', onet: '15-2011.00'), 'SOC_Code is missing.'],
            [$this->row('actuaries', soc: 'CN-15-2011', onet: '15-2011.00'), 'SOC_Code must be 15-2011.'],
            [$this->row('actuaries', soc: '15-2011', onet: ''), 'O_NET_Code is missing.'],
            [$this->row('actuaries', soc: '15-2011', onet: 'not_applicable_cn_occupation'), 'O_NET_Code must be 15-2011.00.'],
            [$this->row('actuaries', soc: '15-2011', onet: 'multiple_onet_occupations'), 'O_NET_Code must be 15-2011.00.'],
            [$this->row('actuaries', soc: '15-2011', onet: 'BLS_BROAD_GROUP'), 'O_NET_Code must be 15-2011.00.'],
        ];

        foreach ($cases as [$row, $expectedError]) {
            $workbook = $this->writeWorkbook([$row]);

            [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString($expectedError, implode(' ', $report['errors']));
        }
    }

    #[Test]
    public function conflicting_existing_crosswalks_are_rejected(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createCrosswalk($occupation, 'us_soc', '15-9999');
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing us_soc crosswalk conflicts with workbook SOC_Code.', implode(' ', $report['errors']));

        OccupationCrosswalk::query()->delete();
        $this->createCrosswalk($occupation, 'us_soc', '15-2011');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-9999.00');

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.', implode(' ', $report['errors']));
    }

    #[Test]
    public function duplicate_existing_crosswalks_are_rejected(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createCrosswalk($occupation, 'us_soc', '15-2011');
        $this->createCrosswalk($occupation, 'us_soc', '15-2011');
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing us_soc crosswalks found.', implode(' ', $report['errors']));

        OccupationCrosswalk::query()->delete();
        $this->createCrosswalk($occupation, 'us_soc', '15-2011');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-2011.00');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '15-2011.00');

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing onet_soc_2019 crosswalks found.', implode(' ', $report['errors']));
    }

    #[Test]
    public function existing_matching_occupation_with_missing_crosswalks_is_handled_safely(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createCrosswalk($occupation, 'us_soc', '15-2011');
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_occupation_count']);
        $this->assertSame(1, $report['created_crosswalk_count']);
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function docx_fallback_absence_of_authority_is_not_treated_as_existing_authority(): void
    {
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'actuaries');

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
        $exitCode = Artisan::call('career:align-selected-authority-crosswalks', array_merge([
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function createProtectedOccupations(): void
    {
        foreach (['actors', 'data-scientists', 'registered-nurses', 'accountants-and-auditors'] as $slug) {
            $occupation = $this->createOccupation($slug, 'Protected '.$slug, 'Protected '.$slug);
            $this->createCrosswalk($occupation, 'us_soc', '00-0000');
            $this->createCrosswalk($occupation, 'onet_soc_2019', '00-0000.00');
        }
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
            $this->row('actuaries', title: 'Actuaries', titleZh: '精算师', soc: '15-2011', onet: '15-2011.00'),
            $this->row('financial-analysts', title: 'Financial Analysts', titleZh: '金融分析师', soc: '13-2051', onet: '13-2051.00'),
            $this->row('high-school-teachers', title: 'High School Teachers', titleZh: '高中教师', soc: '25-2031', onet: '25-2031.00'),
            $this->row('market-research-analysts', title: 'Market Research Analysts', titleZh: '市场研究分析师', soc: '13-1161', onet: '13-1161.00'),
            $this->row('architectural-and-engineering-managers', title: 'Architectural and Engineering Managers', titleZh: '建筑和工程经理', soc: '11-9041', onet: '11-9041.00'),
            $this->row('civil-engineers', title: 'Civil Engineers', titleZh: '土木工程师', soc: '17-2051', onet: '17-2051.00'),
            $this->row('biomedical-engineers', title: 'Biomedical Engineers', titleZh: '生物医学工程师', soc: '17-2031', onet: '17-2031.00'),
            $this->row('dentists', title: 'Dentists', titleZh: '牙医', soc: '29-1021', onet: '29-1021.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Actuaries',
        string $titleZh = '精算师',
        string $soc = '15-2011',
        string $onet = '15-2011.00',
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
        $dir = sys_get_temp_dir().'/career-align-selected-authority-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
