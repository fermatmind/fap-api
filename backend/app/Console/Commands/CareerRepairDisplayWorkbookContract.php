<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Xlsx\XlsxCellReference;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class CareerRepairDisplayWorkbookContract extends Command
{
    private const SHEET_NAME = 'Career_Assets_v4_1';

    private const REPAIRER_VERSION = 'career_display_workbook_contract_repairer_v0.1';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'Slug',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'Primary_CTA_Target_Action',
    ];

    /** @var list<string> */
    private const SCHEMA_FIELDS = [
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
    ];

    protected $signature = 'career:repair-display-workbook-contract
        {--file= : Absolute path to the reviewed v4.2 career display workbook}
        {--output= : Optional repaired workbook output path; required with --execute}
        {--report-output= : Optional JSON report output path}
        {--slugs= : Optional comma-separated slug filter}
        {--execute : Write the repaired workbook artifact to --output}
        {--json : Emit JSON report}';

    protected $description = 'Repair mechanical career display workbook contract issues without importing, publishing, or mutating CMS data.';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        try {
            $report = $this->repair(
                sourcePath: (string) $this->option('file'),
                outputPath: $this->option('output') !== null ? (string) $this->option('output') : null,
                execute: (bool) $this->option('execute'),
                slugs: $this->slugs((string) ($this->option('slugs') ?? '')),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $reportOutput = $this->option('report-output');
        if (is_string($reportOutput) && trim($reportOutput) !== '') {
            $this->writeJsonReport($reportOutput, $report);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Career display workbook contract repair %s: %d changed rows, %d changed cells.',
            $report['decision'],
            $report['changed_rows'],
            $report['changed_cells'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function repair(string $sourcePath, ?string $outputPath = null, bool $execute = false, array $slugs = []): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Workbook file does not exist: '.$sourcePath);
        }

        if ($execute && ($outputPath === null || trim($outputPath) === '')) {
            throw new RuntimeException('--output is required when --execute is set.');
        }

        if ($execute && realpath($sourcePath) === realpath((string) $outputPath)) {
            throw new RuntimeException('--output must not overwrite the source workbook.');
        }

        $slugAllowlist = $this->slugAllowlist($slugs);
        $workbook = $this->readWorkbook($sourcePath);
        $headers = $workbook['headers'];
        $rows = $workbook['rows'];
        $this->assertRequiredHeaders($headers);

        $repairsByField = [];
        $sampleRepairs = [];
        $changedRows = 0;
        $changedCells = 0;
        $selectedRows = 0;

        foreach ($rows as $rowIndex => $row) {
            $slug = strtolower(trim((string) ($row['Slug'] ?? '')));
            if ($slugAllowlist !== [] && ! isset($slugAllowlist[$slug])) {
                continue;
            }

            $selectedRows++;
            $rowChanged = false;
            $changes = [];

            $this->normalizeCta($rows[$rowIndex], $changes);
            $this->normalizeSourceRefs($rows[$rowIndex], $changes);
            $this->normalizeOccupationSchemas($rows[$rowIndex], $changes);

            foreach ($changes as $field => $change) {
                $rowChanged = true;
                $changedCells++;
                $repairsByField[$field] = ($repairsByField[$field] ?? 0) + 1;
            }

            if ($rowChanged) {
                $changedRows++;
                if (count($sampleRepairs) < 12) {
                    $sampleRepairs[] = [
                        'row_number' => $row['_row_number'] ?? null,
                        'slug' => $slug,
                        'fields' => array_keys($changes),
                    ];
                }
            }
        }

        if ($execute) {
            $this->writeWorkbook($sourcePath, (string) $outputPath, $headers, $rows, $workbook['sheet_path']);
        }

        ksort($repairsByField);

        return [
            'command' => 'career:repair-display-workbook-contract',
            'repairer_version' => self::REPAIRER_VERSION,
            'sheet' => self::SHEET_NAME,
            'source_file_basename' => basename($sourcePath),
            'source_sha256' => hash_file('sha256', $sourcePath),
            'execute' => $execute,
            'writes_database' => false,
            'cms_mutation' => false,
            'output_file' => $execute ? $outputPath : null,
            'output_sha256' => $execute && $outputPath !== null && is_file($outputPath) ? hash_file('sha256', $outputPath) : null,
            'total_rows' => count($rows),
            'selected_rows' => $selectedRows,
            'slug_filter_count' => count($slugAllowlist),
            'changed_rows' => $changedRows,
            'changed_cells' => $changedCells,
            'repairs_by_field' => $repairsByField,
            'sample_repairs' => $sampleRepairs,
            'repair_contract' => [
                'primary_cta_target_action' => 'start_riasec_test',
                'source_ref_label' => 'FermatMind interpretation',
                'occupation_schema_forbidden_terms_removed' => [
                    'Product schema',
                    'job posting sample',
                    '招聘样本',
                ],
            ],
            'decision' => $changedRows === 0 ? 'already_clean' : ($execute ? 'repaired_artifact_written' : 'dry_run_changes_available'),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $changes
     */
    private function normalizeCta(array &$row, array &$changes): void
    {
        $previous = trim((string) ($row['Primary_CTA_Target_Action'] ?? ''));
        if ($previous === 'start_riasec_test') {
            return;
        }

        $row['Primary_CTA_Target_Action'] = 'start_riasec_test';
        $changes['Primary_CTA_Target_Action'] = ['from' => $previous, 'to' => 'start_riasec_test'];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $changes
     */
    private function normalizeSourceRefs(array &$row, array &$changes): void
    {
        $previous = (string) ($row['Claim_Level_Source_Refs'] ?? '');
        $decoded = $this->decodeJsonObject($previous);
        if ($decoded === null) {
            return;
        }

        $text = strtolower($this->encodedText($decoded));
        if (str_contains($text, 'fermatmind') || str_contains($text, 'interpretation') || str_contains($text, '解释')) {
            return;
        }

        $decoded['fermatmind_interpretation'] = [
            'label' => 'FermatMind interpretation',
            'usage' => 'FermatMind synthesis; not an official occupational fact source.',
        ];
        $row['Claim_Level_Source_Refs'] = $this->encodeJson($decoded);
        $changes['Claim_Level_Source_Refs'] = ['added' => 'fermatmind_interpretation'];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $changes
     */
    private function normalizeOccupationSchemas(array &$row, array &$changes): void
    {
        foreach (self::SCHEMA_FIELDS as $field) {
            $previous = (string) ($row[$field] ?? '');
            $decoded = $this->decodeJsonObject($previous);
            if ($decoded === null) {
                continue;
            }

            $normalized = $this->sanitizeOccupationSchema($decoded);
            $encoded = $this->encodeJson($normalized);
            if ($this->canonicalJson($previous) === $encoded) {
                continue;
            }

            $row[$field] = $encoded;
            $changes[$field] = ['normalized' => true];
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sanitizeOccupationSchema(array $schema): array
    {
        $sanitized = $this->sanitizeJsonValue($schema);
        if (! is_array($sanitized)) {
            return $schema;
        }

        if (($sanitized['@type'] ?? null) === '' || ($sanitized['@type'] ?? null) === null) {
            $sanitized['@type'] = 'Occupation';
        }

        return $sanitized;
    }

    private function sanitizeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $keyString = (string) $key;
                if ($this->containsForbiddenSchemaTerm($keyString)) {
                    continue;
                }

                if ($keyString === '@type') {
                    $result[$key] = $this->normalizeSchemaType($item);

                    continue;
                }

                $sanitized = $this->sanitizeJsonValue($item);
                if ($sanitized === null || $sanitized === '') {
                    continue;
                }

                $result[$key] = $sanitized;
            }

            return $result;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($this->containsForbiddenSchemaTerm($value)) {
            return '';
        }

        return $value;
    }

    private function normalizeSchemaType(mixed $value): mixed
    {
        if (is_array($value)) {
            $types = array_values(array_filter($value, static fn (mixed $type): bool => strtolower((string) $type) !== 'product'));
            if (! in_array('Occupation', $types, true)) {
                $types[] = 'Occupation';
            }

            return count($types) === 1 ? $types[0] : $types;
        }

        if (strtolower((string) $value) === 'product') {
            return 'Occupation';
        }

        return $value;
    }

    private function containsForbiddenSchemaTerm(string $value): bool
    {
        $lower = strtolower($value);

        return str_contains($lower, 'product schema')
            || str_contains($lower, 'job posting sample')
            || str_contains($value, '招聘样本');
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, true>
     */
    private function slugAllowlist(array $slugs): array
    {
        $allowlist = [];
        foreach ($slugs as $slug) {
            $normalized = strtolower(trim($slug));
            if ($normalized !== '') {
                $allowlist[$normalized] = true;
            }
        }

        return $allowlist;
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, sheet_path: string}
     */
    private function readWorkbook(string $workbookPath): array
    {
        $sheetPath = $this->sheetPath($workbookPath);
        $sharedStrings = $this->readSharedStrings($workbookPath);
        $headers = [];
        $rows = [];
        $reader = new XMLReader;
        $uri = 'zip://'.$workbookPath.'#'.$sheetPath;
        if ($reader->open($uri) !== true) {
            throw new RuntimeException('Unable to stream workbook sheet: '.$sheetPath);
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();
                if ($rowXml === '') {
                    continue;
                }

                $document = $this->loadXml($rowXml);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rowNode = $document->documentElement;
                if (! $rowNode instanceof DOMElement) {
                    continue;
                }

                $cells = [];
                foreach ($xpath->query('x:c', $rowNode) as $cellNode) {
                    if (! $cellNode instanceof DOMElement) {
                        continue;
                    }
                    $cells[$this->columnIndex($cellNode->getAttribute('r'))] = $this->readCellValue($xpath, $cellNode, $sharedStrings);
                }

                if ($cells === []) {
                    continue;
                }

                ksort($cells);
                $maxIndex = max(array_keys($cells));
                $values = [];
                for ($index = 0; $index <= $maxIndex; $index++) {
                    $values[$index] = $cells[$index] ?? '';
                }

                if ($this->valuesAreEmpty($values)) {
                    continue;
                }

                if ($headers === []) {
                    $headers = array_values(array_map(static fn (mixed $value): string => trim((string) $value), $values));

                    continue;
                }

                $assoc = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $assoc[$header] = (string) ($values[$index] ?? '');
                    }
                }
                $assoc['_row_number'] = (int) $rowNode->getAttribute('r');
                $rows[] = $assoc;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw new RuntimeException(self::SHEET_NAME.' sheet has no header row.');
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'sheet_path' => $sheetPath,
        ];
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertRequiredHeaders(array $headers): void
    {
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            throw new RuntimeException('Workbook is missing required repair headers: '.implode(', ', $missing));
        }
    }

    private function sheetPath(string $workbookPath): string
    {
        $workbook = $this->zipString($workbookPath, 'xl/workbook.xml');
        $rels = $this->zipString($workbookPath, 'xl/_rels/workbook.xml.rels');
        $document = $this->loadXml($workbook);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $relationshipId = null;
        foreach ($xpath->query('/x:workbook/x:sheets/x:sheet') as $sheetNode) {
            if (! $sheetNode instanceof DOMElement || $sheetNode->getAttribute('name') !== self::SHEET_NAME) {
                continue;
            }

            $relationshipId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            break;
        }

        if ($relationshipId === null || $relationshipId === '') {
            throw new RuntimeException('Unable to locate '.self::SHEET_NAME.' in workbook.');
        }

        $relsDocument = $this->loadXml($rels);
        $relsXpath = new DOMXPath($relsDocument);
        $relsXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relsXpath->query('/rel:Relationships/rel:Relationship') as $relationshipNode) {
            if (! $relationshipNode instanceof DOMElement || $relationshipNode->getAttribute('Id') !== $relationshipId) {
                continue;
            }

            $target = $this->normalizeWorkbookTarget($relationshipNode->getAttribute('Target'));
            if ($target === '') {
                break;
            }

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        throw new RuntimeException('Unable to locate worksheet path for '.self::SHEET_NAME.'.');
    }

    private function normalizeWorkbookTarget(string $target): string
    {
        $normalized = ltrim($target, '/');
        if (str_starts_with($normalized, 'xl/')) {
            return $normalized;
        }

        return ltrim($normalized, '/');
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(string $workbookPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open workbook.');
        }

        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        if ($xml === false) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($xpath->query('//x:si') as $node) {
            $strings[] = $node instanceof DOMNode ? $this->collectText($xpath, $node) : '';
        }

        return $strings;
    }

    /**
     * @param  list<array<string, string|int>>  $rows
     * @param  list<string>  $headers
     */
    private function writeWorkbook(string $sourcePath, string $outputPath, array $headers, array $rows, string $sheetPath): void
    {
        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            @mkdir($outputDir, 0775, true);
        }

        if (! copy($sourcePath, $outputPath)) {
            throw new RuntimeException('Unable to copy workbook to output path: '.$outputPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($outputPath) !== true) {
            throw new RuntimeException('Unable to open output workbook: '.$outputPath);
        }

        $sheetXmlPath = $this->streamSheetXml($headers, $rows);
        $zip->deleteName($sheetPath);
        $zip->addFile($sheetXmlPath, $sheetPath);
        $zip->close();
        @unlink($sheetXmlPath);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string|int>>  $rows
     */
    private function streamSheetXml(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-display-sheet-');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary sheet XML file.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary sheet XML file.');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        fwrite($handle, $this->xmlRow(1, $headers));
        foreach ($rows as $index => $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = (string) ($row[$header] ?? '');
            }
            fwrite($handle, $this->xmlRow($index + 2, $values));
        }
        fwrite($handle, '</sheetData></worksheet>');
        fclose($handle);

        return $path;
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

    /**
     * @param  list<string>  $sharedStrings
     */
    private function readCellValue(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $inline = $xpath->query('x:is', $cell)->item(0);

            return $inline instanceof DOMNode ? $this->collectText($xpath, $inline) : '';
        }

        $value = $xpath->query('x:v', $cell)->item(0)?->textContent ?? '';
        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return trim($value);
    }

    private function collectText(DOMXPath $xpath, DOMNode $node): string
    {
        $text = '';
        foreach ($xpath->query('.//x:t', $node) as $textNode) {
            $text .= $textNode->textContent;
        }

        return $text;
    }

    private function zipString(string $workbookPath, string $name): string
    {
        $zip = new ZipArchive;
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open workbook.');
        }

        $xml = $zip->getFromName($name);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Workbook part not found: '.$name);
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $value): ?array
    {
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function canonicalJson(string $value): string
    {
        $decoded = $this->decodeJsonObject($value);
        if ($decoded === null) {
            return $value;
        }

        return $this->encodeJson($decoded);
    }

    private function encodedText(mixed $value): string
    {
        return strtolower(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function columnIndex(string $cellRef): int
    {
        return XlsxCellReference::columnIndex($cellRef);
    }

    /**
     * @param  list<string>  $values
     */
    private function valuesAreEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if ($document->loadXML($xml) !== true) {
                throw new RuntimeException('Invalid XLSX XML part.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $value),
        ), static fn (string $slug): bool => $slug !== ''));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeJsonReport(string $path, array $report): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );
    }
}
