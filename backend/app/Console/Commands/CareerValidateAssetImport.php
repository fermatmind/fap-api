<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Import\CareerAssetImportValidator;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CareerValidateAssetImport extends Command
{
    private const SHEET_NAME = 'Career_Assets_v4_1';

    protected $signature = 'career:validate-asset-import
        {--file= : Absolute path to a Career Asset Excel workbook}
        {--json : Emit machine-readable JSON}
        {--output= : Optional path to write the JSON report}';

    protected $description = 'Validate a Career Asset Excel workbook before any database import.';

    public function handle(CareerAssetImportValidator $validator): int
    {
        try {
            $path = $this->requiredPath('file');
            $workbook = $this->readWorkbook($path);
            $report = $validator->validate($workbook['rows'], $workbook['headers']);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($json)) {
                throw new RuntimeException('Unable to encode validation report.');
            }

            $output = trim((string) ($this->option('output') ?? ''));
            if ($output !== '') {
                $written = file_put_contents($output, $json.PHP_EOL);
                if ($written === false) {
                    throw new RuntimeException('Unable to write report output: '.$output);
                }
            }

            if ((bool) $this->option('json')) {
                $this->line($json);
            } else {
                $this->line('validator_version='.$report['validator_version']);
                $this->line('header_exact_match='.($report['header_exact_match'] ? 'true' : 'false'));
                $this->line('total_rows_processed='.$report['total_rows_processed']);
                $this->line('actors_integrity_pass='.($report['actors_integrity_pass'] ? 'true' : 'false'));
                $this->line('rows_with_missing_soc='.$report['rows_with_missing_soc']);
                $this->line('rows_with_missing_links='.$report['rows_with_missing_links']);
                $this->line('ready_for_pilot='.json_encode($report['ready_for_pilot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('import_decision='.$report['import_decision']);
                $this->line('release_decision='.$report['release_decision']);
            }

            return $report['import_decision'] === 'pass_for_database_import_test'
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredPath(string $option): string
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            throw new RuntimeException('--'.$option.' is required.');
        }
        if (! is_file($path)) {
            throw new RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        return $path;
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string|int>>}
     */
    private function readWorkbook(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to read XLSX workbooks.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX workbook: '.$path);
        }

        try {
            $sheetPath = $this->resolveSheetPath($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if (! is_string($sheetXml)) {
                throw new RuntimeException('Unable to read workbook sheet: '.$sheetPath);
            }

            return $this->readSheetXml($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private function resolveSheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            throw new RuntimeException('Invalid XLSX workbook: missing workbook relationships.');
        }

        $workbook = $this->loadXml($workbookXml);
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheet = $xpath->query('//x:sheet[@name="'.self::SHEET_NAME.'"]')->item(0);
        if (! $sheet instanceof DOMElement) {
            throw new RuntimeException(self::SHEET_NAME.' sheet not found.');
        }

        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($relationshipId === '') {
            throw new RuntimeException(self::SHEET_NAME.' sheet relationship not found.');
        }

        $rels = $this->loadXml($relsXml);
        $relXpath = new DOMXPath($rels);
        $relXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = $relXpath->query('//rel:Relationship[@Id="'.$relationshipId.'"]')->item(0);
        if (! $relationship instanceof DOMElement) {
            throw new RuntimeException(self::SHEET_NAME.' sheet target not found.');
        }

        $target = ltrim($relationship->getAttribute('Target'), '/');
        if ($target === '') {
            throw new RuntimeException(self::SHEET_NAME.' sheet target is empty.');
        }

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xpath->query('//x:si') as $item) {
            $strings[] = $this->collectText($xpath, $item);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array{headers: list<string>, rows: list<array<string, string|int>>}
     */
    private function readSheetXml(string $xml, array $sharedStrings): array
    {
        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $headers = [];
        $rows = [];
        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($xpath->query('x:c', $rowNode) as $cellNode) {
                if (! $cellNode instanceof DOMElement) {
                    continue;
                }
                $cellRef = $cellNode->getAttribute('r');
                $columnIndex = $this->columnIndex($cellRef);
                $cells[$columnIndex] = $this->readCellValue($xpath, $cellNode, $sharedStrings);
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
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = (string) ($values[$index] ?? '');
            }
            $assoc['_row_number'] = (int) $rowNode->getAttribute('r');
            $rows[] = $assoc;
        }

        if ($headers === []) {
            throw new RuntimeException(self::SHEET_NAME.' sheet has no header row.');
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
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

    private function columnIndex(string $cellRef): int
    {
        if (! preg_match('/^([A-Z]+)/', $cellRef, $matches)) {
            return 0;
        }

        $index = 0;
        foreach (str_split($matches[1]) as $char) {
            $index = ($index * 26) + (ord($char) - ord('A') + 1);
        }

        return $index - 1;
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
}
