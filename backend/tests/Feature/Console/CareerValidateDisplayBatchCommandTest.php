<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class CareerValidateDisplayBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const HEADERS = [
        'Asset_Version',
        'Locale',
        'Slug',
        'Job_ID',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
        'Content_Status',
        'Review_State',
        'Release_Status',
        'Last_Reviewed',
        'Next_Review_Due',
        'EN_SEO_Title',
        'EN_SEO_Description',
        'CN_SEO_Title',
        'CN_SEO_Description',
        'EN_Target_Queries',
        'CN_Target_Queries',
        'Search_Intent_Type',
        'EN_H1',
        'CN_H1',
        'EN_Quick_Answer',
        'CN_Quick_Answer',
        'EN_Snapshot_Data',
        'CN_Snapshot_Data',
        'CN_Salary_Data_Type',
        'CN_Snapshot_Data_Limitation',
        'EN_Definition',
        'CN_Definition',
        'EN_Responsibilities',
        'CN_Responsibilities',
        'EN_Comparison_Block',
        'CN_Comparison_Block',
        'EN_How_To_Decide_Fit',
        'CN_How_To_Decide_Fit',
        'EN_RIASEC_Fit',
        'CN_RIASEC_Fit',
        'EN_Personality_Fit',
        'CN_Personality_Fit',
        'EN_Caveat',
        'CN_Caveat',
        'EN_Next_Steps',
        'CN_Next_Steps',
        'AI_Exposure_Score_Raw',
        'AI_Exposure_Score_Normalized',
        'AI_Exposure_Label',
        'AI_Exposure_Source',
        'AI_Exposure_Explanation',
        'EN_FAQ_SCHEMA_JSON',
        'CN_FAQ_SCHEMA_JSON',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'EN_Internal_Links',
        'CN_Internal_Links',
        'Primary_CTA_Label',
        'Primary_CTA_URL',
        'Primary_CTA_Target_Action',
        'Secondary_CTA_Label',
        'Secondary_CTA_URL',
        'Entry_Surface',
        'Source_Page_Type',
        'Subject_Type',
        'Subject_Slug',
        'Primary_Test_Slug',
        'Ready_For_Sitemap',
        'Ready_For_LLMS',
        'Ready_For_Paid',
        'QA_Status',
    ];

    #[Test]
    public function it_requires_file_and_slugs(): void
    {
        $exitCode = Artisan::call('career:validate-display-batch', [
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--file is required.', Artisan::output());

        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);
        $exitCode = Artisan::call('career:validate-display-batch', [
            '--file' => $workbook,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--slugs is required', Artisan::output());
    }

    #[Test]
    public function it_validates_only_explicit_allowlisted_slugs_without_writing_database_rows(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $workbook = $this->writeWorkbook([
            $this->row('data-scientists'),
            $this->row('actuaries', title: 'Actuaries', soc: '15-2011', onet: '15-2011.00'),
        ]);

        $before = $this->tableCounts();
        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists');
        $after = $this->tableCounts();

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $after);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame(['data-scientists'], $report['allowlisted_slugs']);
        $this->assertSame(1, $report['validated_count']);
        $this->assertSame('data-scientists', $report['items'][0]['identity']['slug']);
        $this->assertStringNotContainsString('actuaries', json_encode($report['items'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_current_blockers_for_second_pilot_candidate_slugs(): void
    {
        $this->createAuthorityOccupation('data-scientists', '15-2051', '15-2051.00');
        $this->createAuthorityOccupation('registered-nurses', '29-1141', '29-1141.00');
        $this->createAuthorityOccupation('accountants-and-auditors', '13-2011', '13-2011.00');

        $workbook = $this->writeWorkbook([
            $this->row('data-scientists'),
            $this->row('registered-nurses', title: 'Registered nurses', soc: '29-1141', onet: '29-1141.00'),
            $this->row('accountants-and-auditors', title: 'Accountants and Auditors', cnTitle: '会计与审计人员', soc: '13-2011', onet: '13-2011.00', schemaValid: true, linksStrict: true, sourceStrict: false),
        ]);

        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists,registered-nurses,accountants-and-auditors');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $items = collect($report['items'])->keyBy('identity.slug');

        foreach (['data-scientists', 'registered-nurses'] as $slug) {
            $item = $items->get($slug);
            $this->assertSame('occupation_backed', $item['authority_gate']['public_API_state']);
            $this->assertTrue($item['authority_gate']['occupation_exists']);
            $this->assertTrue($item['authority_gate']['SOC_crosswalk_exists']);
            $this->assertTrue($item['authority_gate']['O_NET_crosswalk_exists']);
            $this->assertSame('authority_ready_content_blocked', $item['recommended_status']);
            $this->assertFalse($item['schema_gate']['Occupation_valid']);
            $this->assertFalse($item['link_gate']['unvalidated_jobs_guides_not_counted_as_ready']);
            $this->assertFalse($item['cta_gate']['conforms_to_post_actors_allowed_pattern']);
        }

        $accountants = $items->get('accountants-and-auditors');
        $this->assertSame('occupation_backed', $accountants['authority_gate']['public_API_state']);
        $this->assertTrue($accountants['schema_gate']['Occupation_valid']);
        $this->assertTrue($accountants['link_gate']['related_tests_stable']);
        $this->assertFalse($accountants['source_gate']['salary_growth_job_facts_have_source']);
        $this->assertFalse($accountants['cta_gate']['conforms_to_post_actors_allowed_pattern']);
        $this->assertSame('authority_ready_content_blocked', $accountants['recommended_status']);

        foreach ($report['items'] as $item) {
            $this->assertFalse($item['release_gate']['ready_for_sitemap']);
            $this->assertFalse($item['release_gate']['ready_for_llms']);
            $this->assertFalse($item['release_gate']['ready_for_paid']);
            $this->assertFalse($item['release_gate']['ready_for_backlink']);
        }
    }

    #[Test]
    public function it_fails_when_allowlisted_slug_is_missing_from_workbook(): void
    {
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists,registered-nurses');

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertSame(['registered-nurses'], $report['missing_slugs']);
    }

    #[Test]
    public function it_continues_with_no_go_report_when_authority_database_is_unavailable(): void
    {
        Http::fake([
            'https://api.fermatmind.com/api/v0.5/career/jobs/data-scientists*' => Http::response([
                'identity' => ['occupation_uuid' => 'career_job:data-scientists'],
                'seo_contract' => ['reason_codes' => ['docx_baseline_authority']],
                'provenance_meta' => ['content_version' => 'docx_342_career_batch'],
                'ontology' => ['crosswalks' => []],
            ], 200),
        ]);

        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        config(['career_display_batch_validator.force_authority_unavailable' => true]);
        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('no_go', $report['decision']);
        $item = $report['items'][0];
        $this->assertSame('public_api_fallback', $item['authority_gate']['authority_source']);
        $this->assertSame('authority_unavailable', $item['authority_gate']['authority_state']);
        $this->assertSame('docx_fallback', $item['authority_gate']['api_state']);
        $this->assertSame(0, $item['scores']['authority_score']);
        $this->assertSame('blocked_authority_unavailable', $item['recommended_status']);
        $this->assertNotSame('ready_for_second_pilot_validation', $item['recommended_status']);
        $this->assertFalse($item['release_gate']['ready_for_sitemap']);
        $this->assertFalse($item['release_gate']['ready_for_llms']);
        $this->assertFalse($item['release_gate']['ready_for_paid']);
        $this->assertFalse($item['release_gate']['ready_for_backlink']);
    }

    #[Test]
    public function it_marks_authority_unavailable_when_database_and_public_fallback_are_unavailable(): void
    {
        Http::fake([
            'https://api.fermatmind.com/*' => Http::response(null, 503),
        ]);

        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        config(['career_display_batch_validator.force_authority_unavailable' => true]);
        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists');

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('no_go', $report['decision']);
        $item = $report['items'][0];
        $this->assertSame('authority_unavailable', $item['authority_gate']['authority_state']);
        $this->assertSame('local_db_unavailable', $item['authority_gate']['authority_source']);
        $this->assertSame(0, $item['scores']['authority_score']);
        $this->assertSame('blocked_authority_unavailable', $item['recommended_status']);
        $this->assertSame(1, $report['summary']['blocked_authority_unavailable']);
        $this->assertFalse($item['release_gate']['ready_for_sitemap']);
        $this->assertFalse($item['release_gate']['ready_for_llms']);
        $this->assertFalse($item['release_gate']['ready_for_paid']);
        $this->assertFalse($item['release_gate']['ready_for_backlink']);
    }

    #[Test]
    public function it_fails_strict_authority_mode_when_database_is_unavailable(): void
    {
        Http::fake();
        $workbook = $this->writeWorkbook([$this->row('data-scientists')]);

        config(['career_display_batch_validator.force_authority_unavailable' => true]);
        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists', strictAuthority: true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertSame('blocked_authority_unavailable', $report['items'][0]['recommended_status']);
        $this->assertSame(['Local authority DB is unavailable and --strict-authority was requested.'], $report['errors']);
    }

    #[Test]
    public function it_fails_invalid_workbook_headers(): void
    {
        $headers = self::HEADERS;
        unset($headers[array_search('Primary_CTA_URL', $headers, true)]);
        $workbook = $this->writeWorkbook([$this->row('data-scientists')], array_values($headers));

        [$exitCode, $report] = $this->runValidator($workbook, 'data-scientists');

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('Workbook is missing required headers: Primary_CTA_URL.', $report['errors'][0]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runValidator(string $file, string $slugs, bool $strictAuthority = false): array
    {
        $exitCode = Artisan::call('career:validate-display-batch', [
            '--file' => $file,
            '--slugs' => $slugs,
            '--json' => true,
            '--strict-authority' => $strictAuthority,
        ]);

        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, Artisan::output());

        return [$exitCode, $report];
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
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'pilot-family'],
            [
                'title_en' => 'Pilot Family',
                'title_zh' => '试点职业族',
            ],
        );

        $occupation = Occupation::query()->create([
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

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => $soc,
            'source_title' => $occupation->canonical_title_en,
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);

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

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Data scientists',
        string $cnTitle = 'Data scientists',
        string $soc = '15-2051',
        string $onet = '15-2051.00',
        bool $schemaValid = false,
        bool $linksStrict = false,
        bool $sourceStrict = true,
    ): array {
        $row = array_fill_keys(self::HEADERS, '');
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
        $row['EN_H1'] = $title;
        $row['CN_H1'] = $cnTitle;
        $row['EN_Quick_Answer'] = 'Quick answer';
        $row['CN_Quick_Answer'] = '快速回答';
        $row['EN_Definition'] = 'Definition';
        $row['CN_Definition'] = '定义';
        $row['EN_Caveat'] = 'Caveat';
        $row['CN_Caveat'] = '提醒';
        $row['EN_Next_Steps'] = 'Next steps';
        $row['CN_Next_Steps'] = '下一步';
        $row['EN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['CN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['Search_Intent_Type'] = $this->encodeJson(['type' => 'career_decision']);
        $row['EN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
        $row['CN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
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
        $row['AI_Exposure_Explanation'] = $this->encodeJson(['summary' => 'FermatMind interpretation']);
        $row['EN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['CN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['EN_Occupation_Schema_JSON'] = $this->occupationSchema($title, $schemaValid ? $soc : '');
        $row['CN_Occupation_Schema_JSON'] = $this->occupationSchema($cnTitle, $schemaValid ? $soc : '');
        $row['Claim_Level_Source_Refs'] = $sourceStrict
            ? $this->encodeJson([
                'salary' => ['url' => 'https://www.bls.gov/oes/current/example.htm', 'claim' => 'salary wage'],
                'growth' => ['url' => 'https://www.bls.gov/emp/tables/occupational-projections-and-characteristics.htm', 'claim' => 'growth outlook'],
                'jobs' => ['url' => 'https://www.onetonline.org/link/details/'.$onet, 'claim' => 'jobs employment'],
                'interpretation' => ['label' => 'FermatMind interpretation'],
            ])
            : $this->encodeJson(['source' => 'bls_accountants_ooh']);
        $row['EN_Internal_Links'] = $linksStrict
            ? $this->encodeJson([
                'related_tests' => ['/en/tests/holland-career-interest-test-riasec'],
                'related_jobs' => ['/en/career/jobs/financial-analysts'],
                'validation_policy' => 'final 200 + canonical self + no noindex required',
            ])
            : $this->encodeJson(['/en/tests/holland-career-interest-test-riasec']);
        $row['CN_Internal_Links'] = $linksStrict
            ? $this->encodeJson([
                'related_tests' => ['/zh/tests/holland-career-interest-test-riasec'],
                'related_jobs' => ['/zh/career/jobs/financial-analysts'],
                'validation_policy' => 'final 200 + canonical self + no noindex required',
            ])
            : $this->encodeJson(['/zh/tests/holland-career-interest-test-riasec']);
        $row['Primary_CTA_Label'] = 'Take the Holland / RIASEC Career Interest Test';
        $row['Primary_CTA_URL'] = '/en/tests/holland-career-interest-test-riasec';
        $row['Primary_CTA_Target_Action'] = 'start_click';
        $row['Secondary_CTA_Label'] = 'Secondary';
        $row['Secondary_CTA_URL'] = $this->encodeJson(['/en/tests/holland-career-interest-test-riasec']);
        $row['Entry_Surface'] = 'career_job_detail';
        $row['Source_Page_Type'] = 'career_job_detail';
        $row['Subject_Type'] = 'career_job';
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
        return $this->encodeJson(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => $name,
            'occupationalCategory' => $category,
        ], static fn (mixed $value): bool => $value !== ''));
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
        $dir = sys_get_temp_dir().'/career-display-batch-validator-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
