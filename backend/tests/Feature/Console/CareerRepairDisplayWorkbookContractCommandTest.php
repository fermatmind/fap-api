<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class CareerRepairDisplayWorkbookContractCommandTest extends TestCase
{
    /** @var list<string> */
    private const HEADERS = [
        'Slug',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'Primary_CTA_Target_Action',
    ];

    #[Test]
    public function it_reports_contract_repairs_without_writing_by_default(): void
    {
        $workbook = $this->writeWorkbook([
            $this->dirtyRow('shipping-receiving-and-inventory-clerks'),
        ]);
        $output = sys_get_temp_dir().'/career-contract-repair-'.bin2hex(random_bytes(4)).'.xlsx';

        $exitCode = Artisan::call('career:repair-display-workbook-contract', [
            '--file' => $workbook,
            '--output' => $output,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertFalse($report['execute']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['cms_mutation']);
        $this->assertSame('dry_run_changes_available', $report['decision']);
        $this->assertSame(1, $report['changed_rows']);
        $this->assertSame(4, $report['changed_cells']);
        $this->assertSame(1, $report['repairs_by_field']['Primary_CTA_Target_Action']);
        $this->assertSame(1, $report['repairs_by_field']['Claim_Level_Source_Refs']);
        $this->assertSame(1, $report['repairs_by_field']['EN_Occupation_Schema_JSON']);
        $this->assertSame(1, $report['repairs_by_field']['CN_Occupation_Schema_JSON']);
        $this->assertFileDoesNotExist($output);
    }

    #[Test]
    public function it_writes_a_repaired_workbook_that_is_clean_on_second_pass(): void
    {
        $workbook = $this->writeWorkbook([
            $this->dirtyRow('shipping-receiving-and-inventory-clerks'),
        ]);
        $output = sys_get_temp_dir().'/career-contract-repair-'.bin2hex(random_bytes(4)).'.xlsx';
        $reportOutput = sys_get_temp_dir().'/career-contract-repair-'.bin2hex(random_bytes(4)).'.json';

        $exitCode = Artisan::call('career:repair-display-workbook-contract', [
            '--file' => $workbook,
            '--output' => $output,
            '--report-output' => $reportOutput,
            '--execute' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($report['execute']);
        $this->assertSame('repaired_artifact_written', $report['decision']);
        $this->assertFileExists($output);
        $this->assertFileExists($reportOutput);
        $this->assertSame($report['output_sha256'], hash_file('sha256', $output));

        $secondExitCode = Artisan::call('career:repair-display-workbook-contract', [
            '--file' => $output,
            '--json' => true,
        ]);
        $secondReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExitCode, json_encode($secondReport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('already_clean', $secondReport['decision']);
        $this->assertSame(0, $secondReport['changed_rows']);
        $this->assertSame(0, $secondReport['changed_cells']);
    }

    /**
     * @return array<string, string>
     */
    private function dirtyRow(string $slug): array
    {
        return [
            'Slug' => $slug,
            'EN_Occupation_Schema_JSON' => $this->encodeJson([
                '@type' => 'Product',
                'name' => 'Shipping, Receiving, And Inventory Clerks',
                'occupationalCategory' => '43-5071',
                'job_posting_sample' => 'job posting sample terms',
            ]),
            'CN_Occupation_Schema_JSON' => $this->encodeJson([
                '@type' => ['Thing', 'Product'],
                'name' => '发运、收货、库存文员',
                'occupationalCategory' => '43-5071',
                'sample' => '招聘样本',
            ]),
            'Claim_Level_Source_Refs' => $this->encodeJson([
                'salary' => ['url' => 'https://www.bls.gov/oes/current/example.htm', 'claim' => 'salary wage'],
                'growth' => ['url' => 'https://www.bls.gov/emp/tables/occupational-projections-and-characteristics.htm', 'claim' => 'growth outlook'],
                'jobs' => ['url' => 'https://www.onetonline.org/link/details/43-5071.00', 'claim' => 'jobs employment'],
            ]),
            'Primary_CTA_Target_Action' => 'open_test',
        ];
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
        $path = sys_get_temp_dir().'/career-contract-repair-source-'.bin2hex(random_bytes(6)).'.xlsx';
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
