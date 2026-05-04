<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

final class CareerAlignD8AuthorityCrosswalks extends Command
{
    private const COMMAND_NAME = 'career:align-d8-authority-crosswalks';

    private const SHEET_NAME = 'Career_Assets_v4_1';

    private const SOURCE_SYSTEM_SOC = 'us_soc';

    private const SOURCE_SYSTEM_ONET = 'onet_soc_2019';

    private const FAMILY_SLUG = 'd8-selected-display-surface';

    private const ALIGNMENT_NOTES = 'PR-D8b selected authority occupation and crosswalk alignment';

    /** @var array<string, array{soc: string, onet: string}> */
    private const ALLOWED_SLUGS = [
        'software-developers' => ['soc' => '15-1253', 'onet' => '15-1253.00'],
        'web-developers' => ['soc' => '15-1254', 'onet' => '15-1254.00'],
        'marketing-managers' => ['soc' => '11-2021', 'onet' => '11-2021.00'],
        'lawyers' => ['soc' => '23-1011', 'onet' => '23-1011.00'],
        'pharmacists' => ['soc' => '29-1051', 'onet' => '29-1051.00'],
        'acupuncturists' => ['soc' => '29-1291', 'onet' => '29-1291.00'],
        'business-intelligence-analysts' => ['soc' => '15-2051', 'onet' => '15-2051.01'],
        'clinical-data-managers' => ['soc' => '15-2051', 'onet' => '15-2051.02'],
        'budget-analysts' => ['soc' => '13-2031', 'onet' => '13-2031.00'],
        'human-resources-managers' => ['soc' => '11-3121', 'onet' => '11-3121.00'],
        'administrative-services-managers' => ['soc' => '11-3012', 'onet' => '11-3012.00'],
        'advertising-and-promotions-managers' => ['soc' => '11-2011', 'onet' => '11-2011.00'],
        'architects' => ['soc' => '17-1011', 'onet' => '17-1011.00'],
        'air-traffic-controllers' => ['soc' => '53-2021', 'onet' => '53-2021.00'],
        'airline-and-commercial-pilots' => ['soc' => '53-2011', 'onet' => '53-2011.00'],
        'chemists-and-materials-scientists' => ['soc' => '19-2031', 'onet' => '19-2031.00'],
        'clinical-laboratory-technologists-and-technicians' => ['soc' => '29-2011', 'onet' => '29-2011.00'],
        'community-health-workers' => ['soc' => '21-1094', 'onet' => '21-1094.00'],
        'compensation-and-benefits-managers' => ['soc' => '11-3111', 'onet' => '11-3111.00'],
        'career-and-technical-education-teachers' => ['soc' => '25-2032', 'onet' => '25-2032.00'],
    ];

    /** @var list<string> */
    private const PROTECTED_SLUGS = [
        'actors',
        'data-scientists',
        'registered-nurses',
        'accountants-and-auditors',
        'actuaries',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'architectural-and-engineering-managers',
        'civil-engineers',
        'biomedical-engineers',
        'dentists',
    ];

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'Slug',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
    ];

    /** @var list<string> */
    private const REJECTED_CODE_TOKENS = [
        'cn-',
        'not_applicable_cn_occupation',
        'functional_proxy',
        'nearest_us_soc',
        'cn_boundary_only',
        'bls_broad_group',
        'multiple_onet_occupations',
        'proxy',
    ];

    protected $signature = 'career:align-d8-authority-crosswalks
        {--file= : Absolute path to D8 repaired workbook}
        {--slugs= : Comma-separated explicit slug allowlist}
        {--dry-run : Validate and report without writing}
        {--force : Required to write authority Occupation and crosswalk rows}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}';

    protected $description = 'Guarded dry-run/force alignment for D8 selected authority occupations and SOC/O*NET crosswalks.';

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

            $report = array_merge($report, [
                'mode' => $force ? 'force' : 'dry_run',
                'source_file_sha256' => hash_file('sha256', $file) ?: null,
                'requested_slugs' => $slugs,
                'total_rows' => $workbook['total_rows'],
            ]);

            if ($missingHeaders !== []) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => ['Workbook is missing required headers: '.implode(', ', $missingHeaders).'.'],
                ]), false);
            }

            $rowsBySlug = [];
            foreach ($workbook['rows'] as $row) {
                $slug = strtolower(trim((string) ($row['Slug'] ?? '')));
                if ($slug !== '') {
                    $rowsBySlug[$slug][] = $row;
                }
            }

            $items = [];
            $errors = [];
            foreach ($slugs as $slug) {
                $matchingRows = $rowsBySlug[$slug] ?? [];
                if ($matchingRows === []) {
                    $errors[] = "Allowlisted slug {$slug} was not found in workbook.";

                    continue;
                }
                if (count($matchingRows) > 1) {
                    $errors[] = "Allowlisted slug {$slug} appears more than once in workbook.";

                    continue;
                }

                $item = $this->validateRow($slug, $matchingRows[0], $force);
                if ($item['errors'] !== []) {
                    foreach ($item['errors'] as $error) {
                        $errors[] = "{$slug}: {$error}";
                    }
                }
                $items[] = $item;
            }

            $report = array_merge($report, [
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
            $report['would_write'] = ($report['would_create_occupation_count'] + $report['would_create_crosswalk_count']) > 0;

            if (! $force) {
                return $this->finish($report, true);
            }

            $result = DB::transaction(fn (): array => $this->applyForce($items, $file));

            return $this->finish(array_merge($report, $result, [
                'did_write' => ($result['created_occupation_count'] + $result['created_crosswalk_count']) > 0,
            ], $this->summarizeAfterForce($items, $result)), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
            ]), false);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function applyForce(array $items, string $file): array
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => self::FAMILY_SLUG],
            [
                'title_en' => 'D8 Selected Display Surface Careers',
                'title_zh' => 'D8 职业展示面候选职业',
            ],
        );

        $createdOccupations = [];
        $createdCrosswalks = [];

        foreach ($items as $item) {
            $occupation = Occupation::query()
                ->where('canonical_slug', $item['slug'])
                ->first();

            if (! $occupation instanceof Occupation) {
                $occupation = Occupation::query()->create([
                    'family_id' => $family->id,
                    'entity_level' => 'market_child',
                    'truth_market' => 'US',
                    'display_market' => 'CN',
                    'crosswalk_mode' => 'direct_match',
                    'canonical_slug' => $item['slug'],
                    'canonical_title_en' => $item['title_en'],
                    'canonical_title_zh' => $item['title_zh'],
                    'search_h1_zh' => $this->searchH1Zh($item['title_zh']),
                    'structural_stability' => null,
                    'task_prototype_signature' => null,
                    'market_semantics_gap' => null,
                    'regulatory_divergence' => null,
                    'toolchain_divergence' => null,
                    'skill_gap_threshold' => null,
                    'trust_inheritance_scope' => [
                        'status' => 'd8_selected_authority_alignment',
                        'source_asset' => basename($file),
                        'soc_code' => $item['workbook_us_soc'],
                        'onet_code' => $item['workbook_onet'],
                    ],
                ]);

                $createdOccupations[] = [
                    'slug' => $item['slug'],
                    'occupation_id' => $occupation->id,
                ];
            }

            foreach ([
                self::SOURCE_SYSTEM_SOC => $item['workbook_us_soc'],
                self::SOURCE_SYSTEM_ONET => $item['workbook_onet'],
            ] as $sourceSystem => $sourceCode) {
                $existing = OccupationCrosswalk::query()
                    ->where('occupation_id', $occupation->id)
                    ->where('source_system', $sourceSystem)
                    ->first();

                if ($existing instanceof OccupationCrosswalk) {
                    continue;
                }

                $crosswalk = OccupationCrosswalk::query()->create([
                    'occupation_id' => $occupation->id,
                    'source_system' => $sourceSystem,
                    'source_code' => $sourceCode,
                    'source_title' => $item['title_en'],
                    'mapping_type' => 'direct_match',
                    'confidence_score' => 1.0,
                    'notes' => self::ALIGNMENT_NOTES,
                ]);

                $createdCrosswalks[] = [
                    'slug' => $item['slug'],
                    'source_system' => $sourceSystem,
                    'source_code' => $sourceCode,
                    'crosswalk_id' => $crosswalk->id,
                ];
            }
        }

        return [
            'created_occupation_count' => count($createdOccupations),
            'created_crosswalk_count' => count($createdCrosswalks),
            'created_occupations' => $createdOccupations,
            'created_crosswalks' => $createdCrosswalks,
        ];
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
            throw new RuntimeException('Unsupported slug(s) for D8 authority alignment: '.implode(', ', $invalid).'.');
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
        $titleEn = $this->presentTitle((string) ($row['EN_Title'] ?? ''), $slug);
        $titleZh = $this->presentTitle((string) ($row['CN_Title'] ?? ''), $slug);
        $errors = [];

        $this->expect($workbookSoc !== '', 'SOC_Code is missing.', $errors);
        $this->expect($workbookOnet !== '', 'O_NET_Code is missing.', $errors);
        $this->expect($workbookSoc === $expected['soc'], "SOC_Code must be {$expected['soc']}.", $errors);
        $this->expect($workbookOnet === $expected['onet'], "O_NET_Code must be {$expected['onet']}.", $errors);
        $this->expect($this->isNormalSocCode($workbookSoc), 'SOC_Code must be a normal direct US SOC code.', $errors);
        $this->expect($this->isNormalOnetCode($workbookOnet), 'O_NET_Code must be a normal direct O*NET code.', $errors);

        try {
            $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
        } catch (QueryException $queryException) {
            if ($force) {
                throw $queryException;
            }

            return $this->dbUnavailableItem($slug, $row, $workbookSoc, $workbookOnet, $titleEn, $titleZh, $errors);
        }

        if (! $occupation instanceof Occupation) {
            return $this->item(
                slug: $slug,
                row: $row,
                occupation: null,
                workbookSoc: $workbookSoc,
                workbookOnet: $workbookOnet,
                titleEn: $titleEn,
                titleZh: $titleZh,
                existingSoc: [],
                existingOnet: [],
                errors: $errors,
            );
        }

        $socCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_SOC)
            ->get(['id', 'source_system', 'source_code']);
        $onetCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_ONET)
            ->get(['id', 'source_system', 'source_code']);

        if ($socCrosswalks->count() > 1) {
            $errors[] = 'Duplicate existing us_soc crosswalks found.';
        } elseif ($socCrosswalks->count() === 1 && $socCrosswalks->first()?->source_code !== $workbookSoc) {
            $errors[] = 'Existing us_soc crosswalk conflicts with workbook SOC_Code.';
        }

        if ($onetCrosswalks->count() > 1) {
            $errors[] = 'Duplicate existing onet_soc_2019 crosswalks found.';
        } elseif ($onetCrosswalks->count() === 1 && $onetCrosswalks->first()?->source_code !== $workbookOnet) {
            $errors[] = 'Existing onet_soc_2019 crosswalk conflicts with workbook O_NET_Code.';
        }

        return $this->item(
            slug: $slug,
            row: $row,
            occupation: $occupation,
            workbookSoc: $workbookSoc,
            workbookOnet: $workbookOnet,
            titleEn: $titleEn,
            titleZh: $titleZh,
            existingSoc: $socCrosswalks->map(fn (OccupationCrosswalk $crosswalk): array => $this->crosswalkReport($crosswalk))->all(),
            existingOnet: $onetCrosswalks->map(fn (OccupationCrosswalk $crosswalk): array => $this->crosswalkReport($crosswalk))->all(),
            errors: $errors,
        );
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function dbUnavailableItem(
        string $slug,
        array $row,
        string $workbookSoc,
        string $workbookOnet,
        string $titleEn,
        string $titleZh,
        array $errors,
    ): array {
        $item = $this->item(
            slug: $slug,
            row: $row,
            occupation: null,
            workbookSoc: $workbookSoc,
            workbookOnet: $workbookOnet,
            titleEn: $titleEn,
            titleZh: $titleZh,
            existingSoc: [],
            existingOnet: [],
            errors: $errors,
        );

        $item['authority_source'] = 'local_db_unavailable';
        $item['conflict_check'] = 'not_available_without_local_db';
        $item['warnings'] = ['Local authority DB is unavailable; dry-run reports the workbook-derived creation plan only.'];

        return $item;
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  list<array<string, mixed>>  $existingSoc
     * @param  list<array<string, mixed>>  $existingOnet
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function item(
        string $slug,
        array $row,
        ?Occupation $occupation,
        string $workbookSoc,
        string $workbookOnet,
        string $titleEn,
        string $titleZh,
        array $existingSoc,
        array $existingOnet,
        array $errors,
    ): array {
        $socExists = count($existingSoc) === 1 && ($existingSoc[0]['source_code'] ?? null) === $workbookSoc;
        $onetExists = count($existingOnet) === 1 && ($existingOnet[0]['source_code'] ?? null) === $workbookOnet;
        $occupationFound = $occupation instanceof Occupation;
        $valid = $errors === [];

        return [
            'slug' => $slug,
            'row_number' => (int) ($row['_row_number'] ?? 0),
            'authority_source' => 'local_db',
            'conflict_check' => 'local_db',
            'occupation_found' => $occupationFound,
            'occupation_id' => $occupation?->id,
            'would_create_occupation' => ! $occupationFound && $valid,
            'already_exists_occupation' => $occupationFound && $valid,
            'expected_us_soc' => self::ALLOWED_SLUGS[$slug]['soc'],
            'workbook_us_soc' => $workbookSoc,
            'expected_onet' => self::ALLOWED_SLUGS[$slug]['onet'],
            'workbook_onet' => $workbookOnet,
            'title_en' => $titleEn,
            'title_zh' => $titleZh,
            'existing_us_soc_crosswalks' => $existingSoc,
            'existing_onet_crosswalks' => $existingOnet,
            'would_create_us_soc_crosswalk' => ! $socExists && $valid,
            'would_create_onet_soc_2019_crosswalk' => ! $onetExists && $valid,
            'already_exists_us_soc_crosswalk' => $socExists && $valid,
            'already_exists_onet_soc_2019_crosswalk' => $onetExists && $valid,
            'display_asset_created' => false,
            'release_gates_changed' => false,
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

    private function isNormalSocCode(string $code): bool
    {
        return preg_match('/^\d{2}-\d{4}$/', $code) === 1 && ! $this->containsRejectedToken($code);
    }

    private function isNormalOnetCode(string $code): bool
    {
        return preg_match('/^\d{2}-\d{4}\.\d{2}$/', $code) === 1 && ! $this->containsRejectedToken($code);
    }

    private function containsRejectedToken(string $code): bool
    {
        $lower = strtolower($code);
        foreach (self::REJECTED_CODE_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
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
            'would_create_occupation_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_create_occupation'] ?? false) === true)),
            'created_occupation_count' => 0,
            'already_exists_occupation_count' => count(array_filter($items, static fn (array $item): bool => ($item['already_exists_occupation'] ?? false) === true)),
            'would_create_crosswalk_count' => array_sum(array_map(static function (array $item): int {
                return (int) (($item['would_create_us_soc_crosswalk'] ?? false) === true)
                    + (int) (($item['would_create_onet_soc_2019_crosswalk'] ?? false) === true);
            }, $items)),
            'created_crosswalk_count' => 0,
            'already_exists_crosswalk_count' => array_sum(array_map(static function (array $item): int {
                return (int) (($item['already_exists_us_soc_crosswalk'] ?? false) === true)
                    + (int) (($item['already_exists_onet_soc_2019_crosswalk'] ?? false) === true);
            }, $items)),
            'failed_count' => count(array_filter($items, static fn (array $item): bool => ($item['errors'] ?? []) !== [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    private function summarizeAfterForce(array $items, array $result): array
    {
        $createdOccupationCount = (int) ($result['created_occupation_count'] ?? 0);
        $createdCrosswalkCount = (int) ($result['created_crosswalk_count'] ?? 0);

        return [
            'would_create_occupation_count' => 0,
            'would_create_crosswalk_count' => 0,
            'already_exists_occupation_count' => count($items),
            'already_exists_crosswalk_count' => (count($items) * 2),
            'failed_count' => 0,
            'created_occupation_count' => $createdOccupationCount,
            'created_crosswalk_count' => $createdCrosswalkCount,
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
            'protected_slugs_untouched' => self::PROTECTED_SLUGS,
            'total_rows' => null,
            'validated_count' => 0,
            'items' => [],
            'would_create_occupation_count' => 0,
            'created_occupation_count' => 0,
            'already_exists_occupation_count' => 0,
            'would_create_crosswalk_count' => 0,
            'created_crosswalk_count' => 0,
            'already_exists_crosswalk_count' => 0,
            'failed_count' => 0,
            'would_write' => false,
            'did_write' => false,
            'display_assets_created' => false,
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
            $report['writes_database'] = $success && (($report['created_occupation_count'] ?? 0) + ($report['created_crosswalk_count'] ?? 0)) > 0;
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
            $this->line('would_create_occupation_count='.(string) $report['would_create_occupation_count']);
            $this->line('would_create_crosswalk_count='.(string) $report['would_create_crosswalk_count']);
            $this->line('failed_count='.(string) $report['failed_count']);
            $this->line('created_occupation_count='.(string) $report['created_occupation_count']);
            $this->line('created_crosswalk_count='.(string) $report['created_crosswalk_count']);
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
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Invalid XLSX XML.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function presentTitle(string $title, string $slug): string
    {
        $title = trim($title);

        return $title !== '' ? $title : str_replace('-', ' ', $slug);
    }

    private function searchH1Zh(string $titleZh): string
    {
        return str_contains($titleZh, '职业诊断') ? $titleZh : $titleZh.'职业诊断';
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        if ($throwable instanceof QueryException) {
            return 'Authority database query failed: '.$message;
        }

        return $message;
    }
}
