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

final class CareerAlignCareerAuthorityBatchCommandTest extends TestCase
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
    private const COHORT_SLUGS = [
        'acute-care-nurses',
        'adapted-physical-education-specialists',
        'administrative-law-judges-adjudicators-and-hearing-officers',
        'adult-basic-education-adult-secondary-education-and-english-as-a-second-language-instructors',
        'adult-literacy-and-ged-teachers',
        'advanced-practice-psychiatric-nurses',
        'advertising-promotions-and-marketing-managers',
        'advertising-sales-agents',
        'aerospace-engineering-and-operations-technicians',
        'agents-and-business-managers-of-artists-performers-and-athletes',
        'agricultural-and-food-science-technicians',
        'agricultural-equipment-operators',
        'agricultural-sciences-teachers-postsecondary',
        'aircraft-and-avionics-equipment-mechanics-and-technicians',
        'aircraft-cargo-handling-supervisors',
        'aircraft-launch-and-recovery-officers',
        'aircraft-launch-and-recovery-specialists',
        'aircraft-mechanics-and-service-technicians',
        'aircraft-service-attendants',
        'aircraft-structure-surfaces-rigging-and-systems-assemblers',
        'airfield-operations-specialists',
        'airline-pilots-copilots-and-flight-engineers',
        'allergists-and-immunologists',
        'ambulance-drivers-and-attendants-except-emergency-medical-technicians',
        'amusement-and-recreation-attendants',
        'anesthesiologist-assistants',
        'anesthesiologists',
        'animal-care-and-service-workers',
        'animal-caretakers',
        'animal-control-workers',
    ];

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:align-career-authority-batch', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('acute-care-nurses')]);
        $exitCode = Artisan::call('career:align-career-authority-batch', [
            '--file' => $workbook,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--slugs is required', Artisan::output());
    }

    #[Test]
    public function protected_manual_hold_and_cn_slugs_are_rejected_before_writes(): void
    {
        $workbook = $this->writeWorkbook([
            $this->row('actors', soc: '27-2011', onet: '27-2011.00'),
            $this->row('software-developers', soc: '15-1252', onet: '15-1252.00'),
            $this->row('cn-1-01-00-01', soc: '11-1011', onet: '11-1011.00'),
        ]);

        foreach ([
            'actors' => 'Protected validated slug(s)',
            'software-developers' => 'Manual-hold slug(s)',
            'cn-1-01-00-01' => 'CN proxy slug(s)',
        ] as $slug => $expected) {
            [$exitCode, $report] = $this->runAlign($workbook, $slug);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString($expected, implode(' ', $report['errors']));
            $this->assertSame(0, Occupation::query()->count());
            $this->assertSame(0, OccupationCrosswalk::query()->count());
        }
    }

    #[Test]
    public function dry_run_reports_mixed_create_plan_without_writing(): void
    {
        $this->seedExpectedExistingD10Authority();
        $workbook = $this->writeWorkbook($this->cohortRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::COHORT_SLUGS), ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(30, $report['validated_count']);
        $this->assertSame(7, $report['would_create_occupation_count']);
        $this->assertSame(37, $report['would_create_crosswalk_count']);
        $this->assertFalse($report['display_assets_created']);
        $this->assertFalse($report['release_gates_changed']);
        $this->assertSame(23, Occupation::query()->count());
        $this->assertSame(23, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function force_creates_only_missing_authority_rows_and_is_idempotent(): void
    {
        $this->seedExpectedExistingD10Authority();
        $workbook = $this->writeWorkbook($this->cohortRows());

        [$exitCode, $report] = $this->runAlign($workbook, implode(',', self::COHORT_SLUGS), ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(7, $report['created_occupation_count']);
        $this->assertSame(37, $report['created_crosswalk_count']);
        $this->assertSame(30, Occupation::query()->count());
        $this->assertSame(60, OccupationCrosswalk::query()->count());
        $this->assertSame(0, CareerJobDisplayAsset::query()->count());

        [$exitCode, $secondReport] = $this->runAlign($workbook, implode(',', self::COHORT_SLUGS), ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($secondReport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $secondReport['created_occupation_count']);
        $this->assertSame(0, $secondReport['created_crosswalk_count']);
        $this->assertSame(30, Occupation::query()->count());
        $this->assertSame(60, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function missing_duplicate_proxy_and_conflicting_rows_fail(): void
    {
        $workbook = $this->writeWorkbook([$this->row('acute-care-nurses')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'animal-caretakers');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Requested slug animal-caretakers was not found in workbook.', implode(' ', $report['errors']));

        $duplicateWorkbook = $this->writeWorkbook([
            $this->row('acute-care-nurses'),
            $this->row('acute-care-nurses'),
        ]);

        [$exitCode, $report] = $this->runAlign($duplicateWorkbook, 'acute-care-nurses');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Requested slug acute-care-nurses appears more than once in workbook.', implode(' ', $report['errors']));

        $proxyWorkbook = $this->writeWorkbook([$this->row('acute-care-nurses', onet: 'multiple_onet_occupations')]);

        [$exitCode, $report] = $this->runAlign($proxyWorkbook, 'acute-care-nurses');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('O_NET_Code must be a normal direct O*NET code.', implode(' ', $report['errors']));
    }

    #[Test]
    public function conflicting_and_duplicate_existing_crosswalks_are_rejected(): void
    {
        $occupation = $this->createOccupation('acute-care-nurses');
        $this->createCrosswalk($occupation, 'us_soc', '29-9999');
        $workbook = $this->writeWorkbook([$this->row('acute-care-nurses')]);

        [$exitCode, $report] = $this->runAlign($workbook, 'acute-care-nurses');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Existing us_soc crosswalk conflicts with workbook SOC_Code.', implode(' ', $report['errors']));

        OccupationCrosswalk::query()->delete();
        $this->createCrosswalk($occupation, 'us_soc', '29-1141');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '29-1141.01');
        $this->createCrosswalk($occupation, 'onet_soc_2019', '29-1141.01');

        [$exitCode, $report] = $this->runAlign($workbook, 'acute-care-nurses');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate existing onet_soc_2019 crosswalks found.', implode(' ', $report['errors']));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runAlign(string $file, string $slugs, array $options = []): array
    {
        $exitCode = Artisan::call('career:align-career-authority-batch', array_merge([
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function seedExpectedExistingD10Authority(): void
    {
        foreach ($this->cohortRows() as $index => $row) {
            if ($index < 23) {
                $occupation = $this->createOccupation($row['Slug'], $row['EN_Title'], $row['CN_Title']);
                $this->createCrosswalk($occupation, 'onet_soc_2019', $row['O_NET_Code']);
            }
        }
    }

    private function createOccupation(string $slug, ?string $titleEn = null, ?string $titleZh = null): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'career-upload-test-family'],
            [
                'title_en' => 'Career Upload Test Family',
                'title_zh' => '职业上传测试族',
            ],
        );

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'directory_draft',
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
    private function cohortRows(): array
    {
        return [
            $this->row('acute-care-nurses', 'Acute Care Nurses', '急症护理护士', '29-1141', '29-1141.01'),
            $this->row('adapted-physical-education-specialists', 'Adapted Physical Education Specialists', '适应性体育教育专家', '25-2059', '25-2059.01'),
            $this->row('administrative-law-judges-adjudicators-and-hearing-officers', 'Administrative Law Judges, Adjudicators, and Hearing Officers', '行政法法官、裁决员与听证官', '23-1021', '23-1021.00'),
            $this->row('adult-basic-education-adult-secondary-education-and-english-as-a-second-language-instructors', 'Adult Basic Education, Adult Secondary Education, and English as a Second Language Instructors', '成人基础教育、成人中等教育与ESL教师', '25-3011', '25-3011.00'),
            $this->row('adult-literacy-and-ged-teachers', 'Adult Basic and Secondary Education and ESL Teachers', '成人基础与中等教育及ESL教师', '25-3011', '25-3011.00'),
            $this->row('advanced-practice-psychiatric-nurses', 'Advanced Practice Psychiatric Nurses', '高级实践精神科护士', '29-1141', '29-1141.02'),
            $this->row('advertising-promotions-and-marketing-managers', 'Advertising, Promotions, and Marketing Managers', '广告、促销与市场经理', '11-2021', '11-2021.00'),
            $this->row('advertising-sales-agents', 'Advertising Sales Agents', '广告销售代表', '41-3011', '41-3011.00'),
            $this->row('aerospace-engineering-and-operations-technicians', 'Aerospace Engineering and Operations Technologists and Technicians', '航空航天工程与运营技术员', '17-3021', '17-3021.00'),
            $this->row('agents-and-business-managers-of-artists-performers-and-athletes', 'Agents and Business Managers of Artists, Performers, and Athletes', '艺术家、表演者与运动员经纪/商务经理', '13-1011', '13-1011.00'),
            $this->row('agricultural-and-food-science-technicians', 'Agricultural and Food Science Technicians', '农业与食品科学技术员', '19-4012', '19-4012.00'),
            $this->row('agricultural-equipment-operators', 'Agricultural Equipment Operators', '农业设备操作员', '45-2091', '45-2091.00'),
            $this->row('agricultural-sciences-teachers-postsecondary', 'Agricultural Sciences Teachers, Postsecondary', '高校农业科学教师', '25-1041', '25-1041.00'),
            $this->row('aircraft-and-avionics-equipment-mechanics-and-technicians', 'Aircraft and Avionics Equipment Mechanics and Technicians', '飞机与航空电子设备维修技师', '49-3011', '49-3011.00'),
            $this->row('aircraft-cargo-handling-supervisors', 'Aircraft Cargo Handling Supervisors', '飞机货物装卸主管', '53-1041', '53-1041.00'),
            $this->row('aircraft-launch-and-recovery-officers', 'Aircraft Launch and Recovery Officers', '飞机发射与回收军官', '55-1012', '55-1012.00'),
            $this->row('aircraft-launch-and-recovery-specialists', 'Aircraft Launch and Recovery Specialists', '飞机发射与回收专员', '55-3012', '55-3012.00'),
            $this->row('aircraft-mechanics-and-service-technicians', 'Aircraft Mechanics and Service Technicians', '飞机机械师与维修技术员', '49-3011', '49-3011.00'),
            $this->row('aircraft-service-attendants', 'Aircraft Service Attendants', '飞机服务员/地面服务人员', '53-6032', '53-6032.00'),
            $this->row('aircraft-structure-surfaces-rigging-and-systems-assemblers', 'Aircraft Structure, Surfaces, Rigging, and Systems Assemblers', '飞机结构、表面、索具与系统装配工', '51-2011', '51-2011.00'),
            $this->row('airfield-operations-specialists', 'Airfield Operations Specialists', '机场运行专员', '53-2022', '53-2022.00'),
            $this->row('airline-pilots-copilots-and-flight-engineers', 'Airline Pilots, Copilots, and Flight Engineers', '航空公司飞行员、副驾驶与飞行工程师', '53-2011', '53-2011.00'),
            $this->row('allergists-and-immunologists', 'Allergists and Immunologists', '过敏与免疫专科医师', '29-1229', '29-1229.01'),
            $this->row('ambulance-drivers-and-attendants-except-emergency-medical-technicians', 'Ambulance Drivers and Attendants, Except Emergency Medical Technicians', '救护车司机与随车服务员（不含急救医疗技术员）', '53-3011', '53-3011.00'),
            $this->row('amusement-and-recreation-attendants', 'Amusement and Recreation Attendants', '娱乐与休闲服务员', '39-3091', '39-3091.00'),
            $this->row('anesthesiologist-assistants', 'Anesthesiologist Assistants', '麻醉医师助理', '29-1071', '29-1071.01'),
            $this->row('anesthesiologists', 'Anesthesiologists', '麻醉医师', '29-1211', '29-1211.00'),
            $this->row('animal-care-and-service-workers', 'Animal Care and Service Workers', '动物照护与服务人员', '39-2021', '39-2021.00'),
            $this->row('animal-caretakers', 'Animal Caretakers', '动物照护员', '39-2021', '39-2021.00'),
            $this->row('animal-control-workers', 'Animal Control Workers', '动物管制员', '33-9011', '33-9011.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Acute Care Nurses',
        string $titleZh = '急症护理护士',
        string $soc = '29-1141',
        string $onet = '29-1141.01',
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
     */
    private function writeWorkbook(array $rows): string
    {
        $path = $this->tempDir().'/career_authority_batch.xlsx';
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
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
        $this->assertTrue($zip->close());

        return $path;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xmlRows = [$this->xmlRow(1, self::HEADERS)];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach (self::HEADERS as $header) {
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

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/career-align-authority-batch-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
