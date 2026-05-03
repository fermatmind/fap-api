<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

final class CareerAlignSelectedOnetCrosswalks extends Command
{
    private const COMMAND_NAME = 'career:align-selected-onet-crosswalks';

    private const SHEET_NAME = 'Career_Assets_v4_1';

    private const SOURCE_SYSTEM_ONET = 'onet_soc_2019';

    private const SOURCE_SYSTEM_SOC = 'us_soc';

    private const PUBLIC_CAREER_JOB_API = 'https://api.fermatmind.com/api/v0.5/career/jobs';

    /** @var array<string, array{soc: string, onet: string}> */
    private const ALLOWED_SLUGS = [
        'accountants-and-auditors' => ['soc' => '13-2011', 'onet' => '13-2011.00'],
        'data-scientists' => ['soc' => '15-2051', 'onet' => '15-2051.00'],
        'registered-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.00'],
    ];

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'Slug',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
    ];

    protected $signature = 'career:align-selected-onet-crosswalks
        {--file= : Absolute path to repaired second-pilot workbook}
        {--slugs= : Comma-separated explicit slug allowlist}
        {--dry-run : Validate and report without writing}
        {--force : Required to write missing O*NET crosswalk rows}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}';

    protected $description = 'Guarded dry-run/force alignment for selected second-pilot O*NET crosswalks.';

    public function handle(): int
    {
        $report = $this->baseReport();

        try {
            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');

            if ($force && $dryRun) {
                return $this->finish(array_merge($report, [
                    'mode' => 'invalid',
                    'decision' => 'fail',
                    'errors' => ['--dry-run and --force cannot be used together.'],
                ]), false);
            }

            $file = $this->requiredFile();
            $slugs = $this->requiredSlugs();
            $workbook = $this->readWorkbook($file, $slugs);
            $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $workbook['headers']));
            if ($missingHeaders !== []) {
                return $this->finish(array_merge($report, [
                    'mode' => $force ? 'force' : 'dry_run',
                    'source_file_sha256' => hash_file('sha256', $file) ?: null,
                    'requested_slugs' => $slugs,
                    'decision' => 'fail',
                    'errors' => ['Workbook is missing required headers: '.implode(', ', $missingHeaders).'.'],
                ]), false);
            }

            $rowsBySlug = [];
            foreach ($workbook['rows'] as $row) {
                $slug = strtolower(trim((string) ($row['Slug'] ?? '')));
                if ($slug !== '') {
                    $rowsBySlug[$slug] = $row;
                }
            }

            $items = [];
            $errors = [];
            foreach ($slugs as $slug) {
                if (! isset($rowsBySlug[$slug])) {
                    $errors[] = "Allowlisted slug {$slug} was not found in workbook.";

                    continue;
                }

                $item = $this->validateRow($slug, $rowsBySlug[$slug], $force);
                if ($item['errors'] !== []) {
                    foreach ($item['errors'] as $error) {
                        $errors[] = "{$slug}: {$error}";
                    }
                }
                $items[] = $item;
            }

            $report = array_merge($report, [
                'mode' => $force ? 'force' : 'dry_run',
                'source_file_sha256' => hash_file('sha256', $file) ?: null,
                'requested_slugs' => $slugs,
                'total_rows' => $workbook['total_rows'],
                'validated_count' => count($items),
                'items' => $items,
            ], $this->summarize($items));

            if ($errors !== []) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => $errors,
                ]), false);
            }

            $report['decision'] = 'pass';
            $report['would_write'] = $report['would_create_count'] > 0;

            if (! $force) {
                return $this->finish($report, true);
            }

            $created = DB::transaction(function () use ($items): array {
                $created = [];
                foreach ($items as $item) {
                    if (($item['already_exists'] ?? false) === true) {
                        continue;
                    }

                    $occupation = Occupation::query()
                        ->where('canonical_slug', $item['slug'])
                        ->firstOrFail();

                    $crosswalk = OccupationCrosswalk::query()->create([
                        'occupation_id' => $occupation->id,
                        'source_system' => self::SOURCE_SYSTEM_ONET,
                        'source_code' => $item['expected_onet'],
                        'source_title' => $item['source_title'],
                        'mapping_type' => 'direct_match',
                        'confidence_score' => 1.0,
                        'notes' => 'PR-D2b selected second-pilot O*NET alignment',
                    ]);

                    $created[] = [
                        'slug' => $item['slug'],
                        'crosswalk_id' => $crosswalk->id,
                    ];
                }

                return $created;
            });

            return $this->finish(array_merge($report, [
                'did_write' => count($created) > 0,
                'created_count' => count($created),
                'created_crosswalks' => $created,
            ], $this->summarizeAfterForce($items, count($created))), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$throwable->getMessage()],
            ]), false);
        }
    }

    private function requiredFile(): string
    {
        $path = trim((string) $this->option('file'));
        if ($path === '') {
            throw new RuntimeException('--file is required.');
        }
        if (! is_file($path)) {
            throw new RuntimeException('--file does not exist: '.$path);
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('--file must be an .xlsx workbook.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function requiredSlugs(): array
    {
        $raw = trim((string) $this->option('slugs'));
        if ($raw === '') {
            throw new RuntimeException('--slugs is required and must be an explicit comma-separated allowlist.');
        }

        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $raw),
        ), static fn (string $slug): bool => $slug !== '')));

        if ($slugs === []) {
            throw new RuntimeException('--slugs is required and must include at least one slug.');
        }

        $invalid = array_values(array_filter(
            $slugs,
            static fn (string $slug): bool => ! array_key_exists($slug, self::ALLOWED_SLUGS),
        ));
        if ($invalid !== []) {
            throw new RuntimeException('Unsupported slug(s) for selected O*NET alignment: '.implode(', ', $invalid).'.');
        }

        return $slugs;
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array<string, mixed>
     */
    private function validateRow(string $slug, array $row, bool $force): array
    {
        $expected = self::ALLOWED_SLUGS[$slug];
        $workbookSoc = trim((string) ($row['SOC_Code'] ?? ''));
        $workbookOnet = trim((string) ($row['O_NET_Code'] ?? ''));
        $title = trim((string) ($row['EN_Title'] ?? ''));
        $errors = [];

        $this->expect($workbookSoc === $expected['soc'], "SOC_Code must be {$expected['soc']}.", $errors);
        $this->expect($workbookOnet === $expected['onet'], "O_NET_Code must be {$expected['onet']}.", $errors);
        $this->expect($this->isNormalOnetCode($workbookOnet), 'O_NET_Code must be a normal O*NET code.', $errors);

        try {
            $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
        } catch (QueryException $queryException) {
            if ($force) {
                throw $queryException;
            }

            return $this->validateRowWithPublicApiFallback($slug, $workbookSoc, $workbookOnet, $title, $errors);
        }
        if (! $occupation instanceof Occupation) {
            $errors[] = 'Occupation is missing; this command must not create occupations.';

            return $this->item($slug, null, $workbookSoc, $workbookOnet, $title, [], [], $errors);
        }

        $socCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_SOC)
            ->get(['id', 'source_system', 'source_code']);
        $onetCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_ONET)
            ->get(['id', 'source_system', 'source_code']);

        if ($socCrosswalks->isEmpty()) {
            $errors[] = 'Existing us_soc crosswalk is missing.';
        } elseif (! $socCrosswalks->contains(static fn (OccupationCrosswalk $crosswalk): bool => $crosswalk->source_code === $workbookSoc)) {
            $errors[] = 'Existing us_soc crosswalk does not match workbook SOC_Code.';
        }

        if ($onetCrosswalks->count() > 1) {
            $errors[] = 'Duplicate existing onet_soc_2019 crosswalks found.';
        } elseif ($onetCrosswalks->count() === 1) {
            $existing = $onetCrosswalks->first();
            if ($existing instanceof OccupationCrosswalk && $existing->source_code !== $workbookOnet) {
                $errors[] = 'Existing onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.';
            }
        }

        return $this->item(
            $slug,
            $occupation,
            $workbookSoc,
            $workbookOnet,
            $title,
            $socCrosswalks->map(fn (OccupationCrosswalk $crosswalk): array => $this->crosswalkReport($crosswalk))->all(),
            $onetCrosswalks->map(fn (OccupationCrosswalk $crosswalk): array => $this->crosswalkReport($crosswalk))->all(),
            $errors,
        );
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function validateRowWithPublicApiFallback(string $slug, string $workbookSoc, string $workbookOnet, string $title, array $errors): array
    {
        $fallbackErrors = $errors;
        $existingSoc = [];
        $existingOnet = [];
        $occupationId = null;

        try {
            $response = Http::timeout(5)->get(self::PUBLIC_CAREER_JOB_API.'/'.$slug, [
                'locale' => 'zh-CN',
            ]);

            if (! $response->ok()) {
                $fallbackErrors[] = 'Local authority DB is unavailable and public API fallback did not return 200.';
            } else {
                $payload = $response->json();
                $reasonCodes = data_get($payload, 'seo_contract.reason_codes', []);
                $contentVersion = (string) data_get($payload, 'provenance_meta.content_version', '');
                $isDocxFallback = is_array($reasonCodes) && in_array('docx_baseline_authority', $reasonCodes, true)
                    || str_contains($contentVersion, 'docx');

                if ($isDocxFallback) {
                    $fallbackErrors[] = 'Public API fallback is DOCX baseline, not authority-backed.';
                }

                $canonicalSlug = (string) data_get($payload, 'identity.canonical_slug', '');
                if ($canonicalSlug !== $slug) {
                    $fallbackErrors[] = 'Public API fallback canonical_slug does not match requested slug.';
                }

                $occupationId = (string) data_get($payload, 'identity.occupation_uuid', '');
                $crosswalks = data_get($payload, 'ontology.crosswalks', []);
                if (is_array($crosswalks)) {
                    foreach ($crosswalks as $crosswalk) {
                        if (! is_array($crosswalk)) {
                            continue;
                        }
                        $sourceSystem = (string) ($crosswalk['source_system'] ?? '');
                        $sourceCode = (string) ($crosswalk['source_code'] ?? '');
                        $entry = [
                            'id' => null,
                            'source_system' => $sourceSystem,
                            'source_code' => $sourceCode,
                        ];
                        if ($sourceSystem === self::SOURCE_SYSTEM_SOC) {
                            $existingSoc[] = $entry;
                        }
                        if ($sourceSystem === self::SOURCE_SYSTEM_ONET) {
                            $existingOnet[] = $entry;
                        }
                    }
                }

                if ($existingSoc === []) {
                    $fallbackErrors[] = 'Public API fallback has no us_soc crosswalk.';
                } elseif (! collect($existingSoc)->contains(static fn (array $crosswalk): bool => ($crosswalk['source_code'] ?? null) === $workbookSoc)) {
                    $fallbackErrors[] = 'Public API fallback us_soc crosswalk does not match workbook SOC_Code.';
                }

                if (count($existingOnet) > 1) {
                    $fallbackErrors[] = 'Public API fallback has duplicate onet_soc_2019 crosswalks.';
                } elseif (count($existingOnet) === 1 && ($existingOnet[0]['source_code'] ?? null) !== $workbookOnet) {
                    $fallbackErrors[] = 'Public API fallback onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.';
                }
            }
        } catch (Throwable) {
            $fallbackErrors[] = 'Local authority DB is unavailable and public API fallback request failed.';
        }

        $item = $this->item($slug, null, $workbookSoc, $workbookOnet, $title, $existingSoc, $existingOnet, $fallbackErrors);
        $item['authority_source'] = 'public_api_fallback';
        $item['occupation_found'] = $occupationId !== null && $occupationId !== '' && $fallbackErrors === [];
        $item['occupation_id'] = $occupationId !== '' ? $occupationId : null;
        $item['would_create'] = $item['occupation_found'] && $existingOnet === [] && $fallbackErrors === [];
        $item['already_exists'] = count($existingOnet) === 1 && ($existingOnet[0]['source_code'] ?? null) === $workbookOnet && $fallbackErrors === [];

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $existingSoc
     * @param  list<array<string, mixed>>  $existingOnet
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function item(
        string $slug,
        ?Occupation $occupation,
        string $workbookSoc,
        string $workbookOnet,
        string $title,
        array $existingSoc,
        array $existingOnet,
        array $errors,
    ): array {
        $matchingOnet = count($existingOnet) === 1 && ($existingOnet[0]['source_code'] ?? null) === $workbookOnet;

        return [
            'slug' => $slug,
            'occupation_found' => $occupation instanceof Occupation,
            'occupation_id' => $occupation?->id,
            'expected_us_soc' => self::ALLOWED_SLUGS[$slug]['soc'],
            'workbook_us_soc' => $workbookSoc,
            'expected_onet' => self::ALLOWED_SLUGS[$slug]['onet'],
            'workbook_onet' => $workbookOnet,
            'source_title' => $title !== '' ? $title : str_replace('-', ' ', $slug),
            'existing_us_soc_crosswalks' => $existingSoc,
            'existing_onet_crosswalks' => $existingOnet,
            'would_create' => $occupation instanceof Occupation && $existingOnet === [] && $errors === [],
            'already_exists' => $matchingOnet && $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function crosswalkReport(OccupationCrosswalk $crosswalk): array
    {
        return [
            'id' => $crosswalk->id,
            'source_system' => $crosswalk->source_system,
            'source_code' => $crosswalk->source_code,
        ];
    }

    private function isNormalOnetCode(string $code): bool
    {
        if (preg_match('/^\d{2}-\d{4}\.\d{2}$/', $code) !== 1) {
            return false;
        }

        $lower = strtolower($code);

        return ! str_starts_with($lower, 'cn-')
            && ! str_contains($lower, 'not_applicable')
            && ! str_contains($lower, 'multiple_onet')
            && ! str_contains($lower, 'proxy')
            && ! str_contains($lower, 'bls_broad_group');
    }

    /**
     * @param  list<string>  $errors
     */
    private function expect(bool $condition, string $message, array &$errors): void
    {
        if (! $condition) {
            $errors[] = $message;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summarize(array $items): array
    {
        return [
            'would_create_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_create'] ?? false) === true)),
            'already_exists_count' => count(array_filter($items, static fn (array $item): bool => ($item['already_exists'] ?? false) === true)),
            'failed_count' => count(array_filter($items, static fn (array $item): bool => ($item['errors'] ?? []) !== [])),
            'created_count' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summarizeAfterForce(array $items, int $createdCount): array
    {
        $preExisting = count(array_filter($items, static fn (array $item): bool => ($item['already_exists'] ?? false) === true));

        return [
            'would_create_count' => 0,
            'already_exists_count' => $preExisting + $createdCount,
            'failed_count' => 0,
            'created_count' => $createdCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'mode' => 'dry_run',
            'read_only' => true,
            'writes_database' => false,
            'requested_slugs' => [],
            'total_rows' => null,
            'validated_count' => 0,
            'items' => [],
            'would_create_count' => 0,
            'already_exists_count' => 0,
            'failed_count' => 0,
            'created_count' => 0,
            'would_write' => false,
            'did_write' => false,
            'release_gates_changed' => false,
            'decision' => 'fail',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        if (($report['mode'] ?? null) === 'force') {
            $report['read_only'] = false;
            $report['writes_database'] = $success && (($report['created_count'] ?? 0) > 0);
        }

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('mode='.$report['mode']);
            $this->line('validated_count='.(string) $report['validated_count']);
            $this->line('would_create_count='.(string) $report['would_create_count']);
            $this->line('already_exists_count='.(string) $report['already_exists_count']);
            $this->line('failed_count='.(string) $report['failed_count']);
            $this->line('created_count='.(string) $report['created_count']);
            $this->line('decision='.$report['decision']);
            if (isset($report['errors'])) {
                $this->line('errors='.json_encode($report['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $slugs
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}
     */
    private function readWorkbook(string $path, array $slugs): array
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

            return $this->readSheetXml($path, $sheetPath, $sharedStrings, $slugs);
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
     * @param  list<string>  $slugs
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}
     */
    private function readSheetXml(string $workbookPath, string $sheetPath, array $sharedStrings, array $slugs): array
    {
        if (! class_exists(XMLReader::class)) {
            throw new RuntimeException('XMLReader extension is required to read large XLSX workbooks.');
        }

        $headers = [];
        $rows = [];
        $totalRows = 0;
        $allowlist = array_fill_keys($slugs, true);
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
                $totalRows++;
                $slug = strtolower(trim((string) ($assoc['Slug'] ?? '')));
                if (! isset($allowlist[$slug])) {
                    continue;
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
            'total_rows' => $totalRows,
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
