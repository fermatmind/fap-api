<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class CareerFullDisplayWorkbookValidatorCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const HEADERS = [
        'Asset_Version', 'Locale', 'Slug', 'Job_ID', 'SOC_Code', 'O_NET_Code', 'EN_Title', 'CN_Title',
        'Content_Status', 'Review_State', 'Release_Status', 'Last_Reviewed', 'Next_Review_Due',
        'EN_SEO_Title', 'EN_SEO_Description', 'CN_SEO_Title', 'CN_SEO_Description',
        'EN_Target_Queries', 'CN_Target_Queries', 'Search_Intent_Type', 'EN_H1', 'CN_H1',
        'EN_Quick_Answer', 'CN_Quick_Answer', 'EN_Snapshot_Data', 'CN_Snapshot_Data',
        'CN_Salary_Data_Type', 'CN_Snapshot_Data_Limitation', 'EN_Definition', 'CN_Definition',
        'EN_Responsibilities', 'CN_Responsibilities', 'EN_Comparison_Block', 'CN_Comparison_Block',
        'EN_How_To_Decide_Fit', 'CN_How_To_Decide_Fit', 'EN_RIASEC_Fit', 'CN_RIASEC_Fit',
        'EN_Personality_Fit', 'CN_Personality_Fit', 'EN_Caveat', 'CN_Caveat', 'EN_Next_Steps',
        'CN_Next_Steps', 'AI_Exposure_Score_Raw', 'AI_Exposure_Score_Normalized', 'AI_Exposure_Label',
        'AI_Exposure_Source', 'AI_Exposure_Explanation', 'EN_FAQ_SCHEMA_JSON', 'CN_FAQ_SCHEMA_JSON',
        'EN_Occupation_Schema_JSON', 'CN_Occupation_Schema_JSON', 'Claim_Level_Source_Refs',
        'EN_Internal_Links', 'CN_Internal_Links', 'Primary_CTA_Label', 'Primary_CTA_URL',
        'Primary_CTA_Target_Action', 'Secondary_CTA_Label', 'Secondary_CTA_URL', 'Entry_Surface',
        'Source_Page_Type', 'Subject_Type', 'Subject_Slug', 'Primary_Test_Slug', 'Ready_For_Sitemap',
        'Ready_For_LLMS', 'Ready_For_Paid', 'QA_Status',
    ];

    #[Test]
    public function it_delegates_to_read_only_full_workbook_validation(): void
    {
        $workbook = $this->writeWorkbook([$this->row('actuaries')]);

        $exitCode = Artisan::call('career:validate-full-display-workbook', [
            '--file' => $workbook,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('career:validate-display-batch', $report['command']);
        $this->assertSame('full_workbook', $report['scan_scope']);
        $this->assertSame(1, $report['validated_count']);
        $this->assertFalse($report['writes_database']);
        $this->assertSame('actuaries', $report['d5_repair_presence'][0]['slug']);
        $this->assertTrue($report['d5_repair_presence'][0]['cta_ok']);
        $this->assertTrue($report['d5_repair_presence'][0]['fermat_label_ok']);
        $this->assertTrue($report['d5_repair_presence'][0]['links_ok']);
        $this->assertTrue($report['d5_repair_presence'][0]['product_absent']);
        $this->assertSame('career.crosswalk_mode_policy.v1', $report['crosswalk_policy_summary']['taxonomy_version']);
        $this->assertSame(1, $report['crosswalk_policy_summary']['modes']['exact']);
        $this->assertSame('exact', $report['items'][0]['crosswalk_policy']['mode']);
        $this->assertSame('auto_safe', $report['items'][0]['crosswalk_policy']['release_bucket']);
        $this->assertTrue($report['items'][0]['crosswalk_policy']['display_import_allowed']);
        $this->assertSame('missing', $report['trust_fact_review_summary']['fact_review_ledger_status']);
        $this->assertTrue($report['trust_fact_review_summary']['sitemap_llms_blocked_until_fact_review_passes']);
        $this->assertSame('blocked', $report['items'][0]['trust_fact_review']['claim_level_evidence_status']);
        $this->assertContains('missing_fact_review_ledger', $report['items'][0]['trust_fact_review']['blockers']);
        $this->assertFalse($report['items'][0]['trust_fact_review']['sitemap_llms_release_ready']);
        $this->assertSame('partially', $report['strategic_architecture_gap_scan']['executive_decision']['current_d5_d6_pipeline_aligned_with_long_term_career_architecture']);
        $this->assertCount(5, $report['strategic_architecture_gap_scan']['gap_matrix']);
    }

    /**
     * @return array<string, string>
     */
    private function row(string $slug): array
    {
        $row = array_fill_keys(self::HEADERS, '');
        $row['Asset_Version'] = 'v4.2';
        $row['Locale'] = 'bilingual';
        $row['Slug'] = $slug;
        $row['Job_ID'] = $slug;
        $row['SOC_Code'] = '15-2011';
        $row['O_NET_Code'] = '15-2011.00';
        $row['EN_Title'] = 'Actuaries';
        $row['CN_Title'] = '精算师';
        $row['Content_Status'] = 'approved';
        $row['Review_State'] = 'human_reviewed';
        $row['Release_Status'] = 'ready_for_pilot';
        $row['QA_Status'] = 'ready_for_technical_validation';
        $row['EN_H1'] = 'Actuaries';
        $row['CN_H1'] = '精算师';
        $row['EN_Quick_Answer'] = 'Quick answer';
        $row['CN_Quick_Answer'] = '快速回答';
        $row['EN_Definition'] = 'Definition';
        $row['CN_Definition'] = '定义';
        $row['EN_Caveat'] = 'Caveat';
        $row['CN_Caveat'] = '提醒';
        $row['EN_Next_Steps'] = 'Next steps';
        $row['CN_Next_Steps'] = '下一步';
        foreach (['EN_Target_Queries', 'CN_Target_Queries', 'Search_Intent_Type', 'EN_Snapshot_Data', 'CN_Snapshot_Data',
            'EN_Responsibilities', 'CN_Responsibilities', 'EN_Comparison_Block', 'CN_Comparison_Block',
            'EN_How_To_Decide_Fit', 'CN_How_To_Decide_Fit', 'EN_RIASEC_Fit', 'CN_RIASEC_Fit',
            'EN_Personality_Fit', 'CN_Personality_Fit', 'AI_Exposure_Explanation'] as $field) {
            $row[$field] = $this->encodeJson(['value' => 'present']);
        }
        $row['EN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['CN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['EN_Occupation_Schema_JSON'] = $this->occupation();
        $row['CN_Occupation_Schema_JSON'] = $this->occupation();
        $row['Claim_Level_Source_Refs'] = $this->encodeJson([
            'salary' => ['url' => 'https://www.bls.gov/oes/current/example.htm', 'claim' => 'salary wage'],
            'growth' => ['url' => 'https://www.bls.gov/emp/tables/occupational-projections-and-characteristics.htm', 'claim' => 'growth outlook'],
            'jobs' => ['url' => 'https://www.onetonline.org/link/details/15-2011.00', 'claim' => 'jobs employment'],
            'fermatmind_interpretation' => [
                'label' => 'FermatMind interpretation',
                'usage' => 'FermatMind synthesis; not an official occupational fact source.',
            ],
        ]);
        $row['EN_Internal_Links'] = $this->links('/en');
        $row['CN_Internal_Links'] = $this->links('/zh');
        $row['Primary_CTA_Label'] = 'Take the Holland / RIASEC Career Interest Test';
        $row['Primary_CTA_URL'] = '/en/tests/holland-career-interest-test-riasec';
        $row['Primary_CTA_Target_Action'] = 'start_riasec_test';
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
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Question 1'],
                ['@type' => 'Question', 'name' => 'Question 2'],
                ['@type' => 'Question', 'name' => 'Question 3'],
            ],
        ]);
    }

    private function occupation(): string
    {
        return $this->encodeJson(['@type' => 'Occupation', 'name' => 'Actuaries', 'occupationalCategory' => '15-2011']);
    }

    private function links(string $locale): string
    {
        return $this->encodeJson([
            'related_tests' => [$locale.'/tests/holland-career-interest-test-riasec'],
            'related_jobs' => [],
            'related_guides' => [],
            'validation_policy' => ['render_policy' => 'hide_or_plain_text_if_unvalidated'],
        ]);
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeWorkbook(array $rows): string
    {
        $path = sys_get_temp_dir().'/career-full-display-validator-'.bin2hex(random_bytes(6)).'.xlsx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Career_Assets_v4_1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
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
            $xmlRows[] = $this->xmlRow($index + 2, array_map(static fn (string $header): string => $row[$header] ?? '', self::HEADERS));
        }

        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    }

    /**
     * @param  list<string>  $values
     */
    private function xmlRow(int $rowNumber, array $values): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = '<c r="'.$this->columnName($index + 1).$rowNumber.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</t></is></c>';
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
}
