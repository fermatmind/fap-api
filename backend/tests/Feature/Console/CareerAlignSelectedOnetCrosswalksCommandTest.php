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

final class CareerAlignSelectedOnetCrosswalksCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const HEADERS = [
        'Slug',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
    ];

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:align-selected-onet-crosswalks', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);
        $exitCode = Artisan::call('career:align-selected-onet-crosswalks', [
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

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists', [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('--dry-run and --force cannot be used together.', implode(' ', $report['errors']));
    }

    #[Test]
    public function default_dry_run_writes_zero_rows_and_reports_would_create(): void
    {
        $this->createOccupationWithSoc('data-scientists', '15-2051');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(1, $report['would_create_count']);
        $this->assertSame(0, $report['created_count']);
        $this->assertTrue($report['items'][0]['would_create']);
        $this->assertSame(1, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function force_creates_exactly_one_onet_crosswalk_per_selected_slug(): void
    {
        foreach ($this->selectedRows() as $row) {
            $this->createOccupationWithSoc($row['Slug'], $row['SOC_Code']);
        }
        $workbook = $this->writeWorkbook($this->selectedRows());

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists,registered-nurses,accountants-and-auditors', [
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertFalse($report['read_only']);
        $this->assertTrue($report['writes_database']);
        $this->assertSame(3, $report['created_count']);
        $this->assertSame(3, Occupation::query()->count());
        $this->assertSame(6, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());

        foreach ($this->selectedRows() as $row) {
            $occupation = Occupation::query()->where('canonical_slug', $row['Slug'])->firstOrFail();
            $this->assertDatabaseHas('occupation_crosswalks', [
                'occupation_id' => $occupation->id,
                'source_system' => 'onet_soc_2019',
                'source_code' => $row['O_NET_Code'],
                'source_title' => $row['EN_Title'],
                'mapping_type' => 'direct_match',
                'notes' => 'PR-D2b selected second-pilot O*NET alignment',
            ]);
        }
    }

    #[Test]
    public function repeated_force_is_idempotent(): void
    {
        $this->createOccupationWithSoc('data-scientists', '15-2051');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        $this->runAlign($workbook, 'data-scientists', ['--force' => true]);
        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists', ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['created_count']);
        $this->assertSame(1, $report['already_exists_count']);
        $this->assertSame(2, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function missing_occupation_fails_without_creating_one(): void
    {
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Occupation is missing', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function missing_us_soc_crosswalk_fails(): void
    {
        $this->createOccupation('data-scientists');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing us_soc crosswalk is missing.', implode(' ', $report['errors']));
    }

    #[Test]
    public function mismatched_workbook_soc_fails(): void
    {
        $this->createOccupationWithSoc('data-scientists', '15-2051');
        $workbook = $this->writeWorkbook([$this->row('data-scientists', soc: '15-9999')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('SOC_Code must be 15-2051.', implode(' ', $report['errors']));
    }

    #[Test]
    public function bad_workbook_onet_fails(): void
    {
        $this->createOccupationWithSoc('data-scientists', '15-2051');
        $workbook = $this->writeWorkbook([$this->row('data-scientists', onet: 'multiple_onet_occupations')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('O_NET_Code must be 15-2051.00.', implode(' ', $report['errors']));
        $this->assertStringContainsString('O_NET_Code must be a normal O*NET code.', implode(' ', $report['errors']));
    }

    #[Test]
    public function slug_outside_selected_allowlist_fails(): void
    {
        $workbook = $this->writeWorkbook([$this->row('software-developers', soc: '15-1252', onet: '15-1252.00')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'software-developers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
    }

    #[Test]
    public function duplicate_existing_onet_crosswalks_fail(): void
    {
        $occupation = $this->createOccupationWithSoc('data-scientists', '15-2051');
        $this->createOnet($occupation, '15-2051.00');
        $this->createOnet($occupation, '15-2051.00');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing onet_soc_2019 crosswalks found.', implode(' ', $report['errors']));
    }

    #[Test]
    public function conflicting_existing_onet_crosswalk_fails(): void
    {
        $occupation = $this->createOccupationWithSoc('data-scientists', '15-2051');
        $this->createOnet($occupation, '15-9999.00');
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.', implode(' ', $report['errors']));
    }

    #[Test]
    public function non_selected_workbook_rows_are_ignored(): void
    {
        $this->createOccupationWithSoc('data-scientists', '15-2051');
        $workbook = $this->writeWorkbook([
            $this->row('data-scientists'),
            $this->row('software-developers', soc: '15-1252', onet: '15-1252.00'),
        ]);

        [$exitCode, $report] = $this->runAlign($workbook, 'data-scientists');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $report['validated_count']);
        $this->assertSame('data-scientists', $report['items'][0]['slug']);
        $this->assertStringNotContainsString('software-developers', json_encode($report['items'], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runAlign(string $file, string $slugs, array $options = []): array
    {
        $exitCode = Artisan::call('career:align-selected-onet-crosswalks', array_merge([
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function createOccupationWithSoc(string $slug, string $soc): Occupation
    {
        $occupation = $this->createOccupation($slug);
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => $soc,
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

    private function createOnet(Occupation $occupation, string $onet): void
    {
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => $onet,
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
            $this->row('registered-nurses', title: 'Registered Nurses', soc: '29-1141', onet: '29-1141.00'),
            $this->row('accountants-and-auditors', title: 'Accountants and Auditors', soc: '13-2011', onet: '13-2011.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Data Scientists',
        string $soc = '15-2051',
        string $onet = '15-2051.00',
    ): array {
        return [
            'Slug' => $slug,
            'SOC_Code' => $soc,
            'O_NET_Code' => $onet,
            'EN_Title' => $title,
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
        $dir = sys_get_temp_dir().'/career-align-selected-onet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
