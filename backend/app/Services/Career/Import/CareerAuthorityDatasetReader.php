<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class CareerAuthorityDatasetReader
{
    /**
     * @return array{
     *   dataset_name: string,
     *   dataset_version: ?string,
     *   dataset_checksum: string,
     *   source_path: string,
     *   rows: list<array<string, mixed>>,
     *   manifest: array<string, mixed>
     * }
     */
    public function read(string $sourcePath, ?string $manifestPath = null, ?int $limit = null): array
    {
        $resolvedSource = $this->resolvePath($sourcePath);
        $manifest = $this->readManifest($manifestPath);

        $rows = match (strtolower(pathinfo($resolvedSource, PATHINFO_EXTENSION))) {
            'csv' => $this->readCsv($resolvedSource),
            'xlsx' => $this->readXlsx($resolvedSource),
            default => throw new RuntimeException(sprintf('Unsupported Career authority dataset format [%s].', $resolvedSource)),
        };

        if ($limit !== null && $limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'dataset_name' => basename($resolvedSource),
            'dataset_version' => is_string($manifest['dataset_version'] ?? null) ? $manifest['dataset_version'] : null,
            'dataset_checksum' => hash_file('sha256', $resolvedSource) ?: hash('sha256', $resolvedSource),
            'source_path' => $resolvedSource,
            'rows' => $rows,
            'manifest' => $manifest,
        ];
    }

    private function resolvePath(string $path): string
    {
        $candidate = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($candidate)) {
            throw new RuntimeException(sprintf('Career authority dataset not found at [%s].', $candidate));
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(?string $manifestPath): array
    {
        if ($manifestPath === null || trim($manifestPath) === '') {
            return [];
        }

        $resolved = $this->resolvePath($manifestPath);
        $decoded = json_decode((string) file_get_contents($resolved), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Career authority manifest is not valid JSON: [%s].', $resolved));
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCsv(string $sourcePath): array
    {
        $handle = fopen($sourcePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset [%s].', $sourcePath));
        }

        $headers = null;
        $rows = [];
        $rowNumber = 0;

        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $cells = array_map(static fn (mixed $value): string => trim((string) $value), $values);

            if ($cells === [] || count(array_filter($cells, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            $rows[] = $this->combineRow($headers, $cells, $rowNumber);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readXlsx(string $sourcePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException(sprintf('Unable to open spreadsheet [%s].', $sourcePath));
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (! is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            throw new RuntimeException(sprintf('Unable to read first worksheet from [%s].', $sourcePath));
        }

        $sharedStrings = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();

        $xml = simplexml_load_string($sheetXml);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException(sprintf('Unable to parse worksheet XML from [%s].', $sourcePath));
        }

        $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $xml->xpath('//a:sheetData/a:row') ?: [];

        $headers = null;
        $rows = [];

        foreach ($rowNodes as $rowNode) {
            if (! $rowNode instanceof SimpleXMLElement) {
                continue;
            }

            $rowNumber = (int) ($rowNode['r'] ?? 0);
            $cells = $this->rowCells($rowNode, $sharedStrings);

            if ($cells === [] || count(array_filter($cells, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            if ($headers === null && in_array('Occupation Title', $cells, true) && in_array('Slug', $cells, true)) {
                $headers = $cells;

                continue;
            }

            if ($headers === null) {
                continue;
            }

            $rows[] = $this->combineRow($headers, $cells, $rowNumber);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string|false $xml): array
    {
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $document = simplexml_load_string($xml);
        if (! $document instanceof SimpleXMLElement) {
            return [];
        }

        $document->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = [];

        foreach ($document->xpath('//a:si') ?: [] as $node) {
            if (! $node instanceof SimpleXMLElement) {
                continue;
            }

            $texts = [];
            foreach ($node->xpath('.//a:t') ?: [] as $textNode) {
                $texts[] = (string) $textNode;
            }

            $items[] = implode('', $texts);
        }

        return $items;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<string>
     */
    private function rowCells(SimpleXMLElement $rowNode, array $sharedStrings): array
    {
        $rowNode->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];

        foreach ($rowNode->xpath('./a:c') ?: [] as $cell) {
            if (! $cell instanceof SimpleXMLElement) {
                continue;
            }

            $reference = (string) ($cell['r'] ?? '');
            $column = $this->columnFromReference($reference);
            $index = $this->columnIndex($column);
            $cells[$index] = $this->cellValue($cell, $sharedStrings);
        }

        if ($cells === []) {
            return [];
        }

        ksort($cells);

        $normalized = [];
        $maxIndex = max(array_keys($cells));
        for ($i = 0; $i <= $maxIndex; $i++) {
            $normalized[] = trim((string) ($cells[$i] ?? ''));
        }

        return $normalized;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('./a:is//a:t') ?: [];

            return trim(implode('', array_map(static fn (SimpleXMLElement $node): string => (string) $node, $texts)));
        }

        $valueNodes = $cell->xpath('./a:v');
        $value = isset($valueNodes[0]) ? trim((string) $valueNodes[0]) : '';

        if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) {
            return trim($sharedStrings[(int) $value]);
        }

        return $value;
    }

    private function columnFromReference(string $reference): string
    {
        return preg_replace('/\d+/', '', strtoupper($reference)) ?: 'A';
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return max($index - 1, 0);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private function combineRow(array $headers, array $cells, int $rowNumber): array
    {
        $row = ['_row_number' => $rowNumber];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = $cells[$index] ?? '';
        }

        return $row;
    }
}
