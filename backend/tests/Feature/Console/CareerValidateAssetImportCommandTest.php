<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\Import\CareerAssetImportValidator;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Career\CareerAssetImportValidatorTest;
use ZipArchive;

final class CareerValidateAssetImportCommandTest extends TestCase
{
    #[Test]
    public function it_outputs_json_report_for_a_valid_career_asset_workbook_without_database_writes(): void
    {
        $dir = $this->tempDir();
        $workbook = $dir.'/career_assets.xlsx';
        $output = $dir.'/report.json';
        $this->writeWorkbook($workbook, CareerAssetImportValidator::expectedHeaders(), [
            CareerAssetImportValidatorTest::placeholderRow('accountants-and-auditors'),
            CareerAssetImportValidatorTest::actorsRow(),
        ]);

        $exitCode = Artisan::call('career:validate-asset-import', [
            '--file' => $workbook,
            '--json' => true,
            '--output' => $output,
        ]);
        $consoleOutput = Artisan::output();

        $this->assertSame(0, $exitCode, $consoleOutput);
        $this->assertStringContainsString('"validator_version": "career_asset_import_validator_v0.1"', $consoleOutput);
        $this->assertStringContainsString('"total_rows_processed": 2', $consoleOutput);
        $this->assertStringContainsString('"actors_integrity_pass": true', $consoleOutput);
        $this->assertStringContainsString('"needs_source_code": 1', $consoleOutput);
        $this->assertStringContainsString('"import_decision": "pass_for_database_import_test"', $consoleOutput);

        $report = json_decode((string) file_get_contents($output), true);
        $this->assertIsArray($report);
        $this->assertSame(['actors'], $report['ready_for_pilot']);
        $this->assertSame('actors_only_ready_for_pilot_validation', $report['release_decision']);
    }

    #[Test]
    public function it_exits_non_zero_for_header_mismatch(): void
    {
        $dir = $this->tempDir();
        $workbook = $dir.'/career_assets_bad_header.xlsx';
        $headers = CareerAssetImportValidator::expectedHeaders();
        $headers[0] = 'Wrong_Header';
        $this->writeWorkbook($workbook, $headers, [
            CareerAssetImportValidatorTest::actorsRow(),
        ]);

        $exitCode = Artisan::call('career:validate-asset-import', [
            '--file' => $workbook,
            '--json' => true,
        ]);
        $consoleOutput = Artisan::output();

        $this->assertSame(1, $exitCode, $consoleOutput);
        $this->assertStringContainsString('"header_exact_match": false', $consoleOutput);
        $this->assertStringContainsString('"import_decision": "fail_import_validation"', $consoleOutput);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function writeWorkbook(string $path, array $headers, array $rows): void
    {
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
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($headers, $rows));
        $this->assertTrue($zip->close());
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function sheetXml(array $headers, array $rows): string
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
        $dir = sys_get_temp_dir().'/career-asset-import-validator-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
