<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\Import\CareerSelectedDisplayAssetMapper;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerAlignCareerAuthorityBatch extends Command
{
    private const COMMAND_NAME = 'career:align-career-authority-batch';

    private const SOURCE_SYSTEM_SOC = 'us_soc';

    private const SOURCE_SYSTEM_ONET = 'onet_soc_2019';

    private const FAMILY_SLUG = 'career-upload-governance-batch';

    private const ALIGNMENT_NOTES = 'D11 career upload authority batch alignment';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'Slug',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
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
        'web-developers',
        'marketing-managers',
        'lawyers',
        'pharmacists',
        'acupuncturists',
        'business-intelligence-analysts',
        'clinical-data-managers',
        'budget-analysts',
        'human-resources-managers',
        'administrative-services-managers',
        'advertising-and-promotions-managers',
        'architects',
        'air-traffic-controllers',
        'airline-and-commercial-pilots',
        'chemists-and-materials-scientists',
        'clinical-laboratory-technologists-and-technicians',
        'community-health-workers',
        'compensation-and-benefits-managers',
        'career-and-technical-education-teachers',
    ];

    /** @var list<string> */
    private const MANUAL_HOLD_SLUGS = [
        'software-developers',
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
        'multiple_onet',
        'proxy',
    ];

    protected $signature = 'career:align-career-authority-batch
        {--file= : Absolute path to repaired career upload workbook}
        {--slugs= : Comma-separated explicit slug allowlist}
        {--directory-draft-scope= : Validated full-upload planner scope for directory_draft state resolution}
        {--dry-run : Validate and report without writing}
        {--force : Required to write authority Occupation and crosswalk rows}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}';

    protected $description = 'Guarded dry-run/force alignment for career upload authority occupations and SOC/O*NET crosswalks.';

    public function __construct(private readonly CareerSelectedDisplayAssetMapper $workbookReader)
    {
        parent::__construct();
    }

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
            $directoryDraftScope = $this->directoryDraftScope();
            if ($directoryDraftScope !== null) {
                return $this->handleDirectoryDraftScope($report, $file, $directoryDraftScope, $force);
            }

            $slugs = $this->requiredSlugs();
            $workbook = $this->workbookReader->readWorkbook($file, $slugs);
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
                    $errors[] = "Requested slug {$slug} was not found in workbook.";

                    continue;
                }
                if (count($matchingRows) > 1) {
                    $errors[] = "Requested slug {$slug} appears more than once in workbook.";

                    continue;
                }

                $item = $this->validateRow($slug, $matchingRows[0], $force);
                foreach ($item['errors'] as $error) {
                    $errors[] = "{$slug}: {$error}";
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
                'did_write' => (($result['created_occupation_count'] ?? 0) + ($result['created_crosswalk_count'] ?? 0)) > 0,
            ], $this->summarizeAfterForce($items, $result)), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
            ]), false);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{path:string, sha256:string, payload:array<string,mixed>, rows:array<string,array<string,mixed>>, slugs:list<string>}  $scope
     */
    private function handleDirectoryDraftScope(array $report, string $file, array $scope, bool $force): int
    {
        $workbook = $this->workbookReader->readWorkbook($file, $scope['slugs']);
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $workbook['headers']));

        $report = array_merge($report, [
            'operation' => 'resolve_directory_draft',
            'mode' => $force ? 'force' : 'dry_run',
            'source_file_sha256' => hash_file('sha256', $file) ?: null,
            'directory_draft_scope' => $scope['path'],
            'directory_draft_scope_sha256' => $scope['sha256'],
            'source_plan' => (string) ($scope['payload']['source_plan'] ?? ''),
            'requested_slugs' => $scope['slugs'],
            'target_crosswalk_mode' => 'direct_match',
            'total_rows' => $workbook['total_rows'],
        ]);

        $expectedWorkbookSha = trim((string) ($scope['payload']['source_repaired_workbook_sha256'] ?? ''));
        if ($expectedWorkbookSha === '' || $expectedWorkbookSha !== $report['source_file_sha256']) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => ['Directory draft scope workbook sha256 does not match --file.'],
            ]), false);
        }

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
        foreach ($scope['slugs'] as $slug) {
            $matchingRows = $rowsBySlug[$slug] ?? [];
            if ($matchingRows === []) {
                $errors[] = "Directory draft scope slug {$slug} was not found in workbook.";

                continue;
            }
            if (count($matchingRows) > 1) {
                $errors[] = "Directory draft scope slug {$slug} appears more than once in workbook.";

                continue;
            }

            $scopeRow = $scope['rows'][$slug] ?? [];
            $item = $this->validateRow($slug, $matchingRows[0], $force);
            $item = $this->validateDirectoryDraftItem($item, $scopeRow);
            foreach ($item['errors'] as $error) {
                $errors[] = "{$slug}: {$error}";
            }
            $items[] = $item;
        }

        $transitionSummary = $this->summarizeDirectoryDraftItems($items);
        $report = array_merge($report, [
            'validated_count' => count($items),
            'items' => $items,
        ], $this->summarize($items), $transitionSummary);

        if ($errors !== []) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => $errors,
            ]), false);
        }

        $report['decision'] = 'pass';
        $report['would_write'] = ($transitionSummary['would_transition_directory_draft_count'] ?? 0) > 0;

        if (! $force) {
            return $this->finish($report, true);
        }

        $result = DB::transaction(fn (): array => $this->applyDirectoryDraftTransition($items, $scope, $file));
        $afterForceSummary = $this->summarizeDirectoryDraftAfterForce($items, $result);

        return $this->finish(array_merge($report, $result, [
            'did_write' => ($result['transitioned_directory_draft_count'] ?? 0) > 0,
        ], $afterForceSummary), true);
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
                'title_en' => 'Career Upload Governance Batch',
                'title_zh' => '职业上传治理批次',
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
                        'status' => 'career_upload_authority_alignment',
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
     * @return array{path:string, sha256:string, payload:array<string,mixed>, rows:array<string,array<string,mixed>>, slugs:list<string>}|null
     */
    private function directoryDraftScope(): ?array
    {
        $path = trim((string) $this->option('directory-draft-scope'));
        if ($path === '') {
            return null;
        }
        if (! is_file($path)) {
            throw new RuntimeException('--directory-draft-scope does not exist: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new RuntimeException('--directory-draft-scope must be a JSON object.');
        }
        if (($payload['scope'] ?? null) !== 'career_full_directory_draft_resolution') {
            throw new RuntimeException('--directory-draft-scope has unsupported scope.');
        }

        $declaredRows = $payload['rows'] ?? null;
        if (! is_array($declaredRows) || $declaredRows === []) {
            throw new RuntimeException('--directory-draft-scope must include exact rows.');
        }

        $rows = [];
        foreach ($declaredRows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('--directory-draft-scope rows must be JSON objects.');
            }

            $slug = strtolower(trim((string) ($row['slug'] ?? $row['canonical_slug'] ?? '')));
            if ($slug === '') {
                throw new RuntimeException('--directory-draft-scope rows must include non-empty slugs.');
            }
            if (isset($rows[$slug])) {
                throw new RuntimeException('--directory-draft-scope contains duplicate slug: '.$slug);
            }
            $rows[$slug] = $row;
        }

        $slugs = array_keys($rows);
        $this->guardSlugs($slugs, 'directory draft scope');
        $rowCount = (int) ($payload['row_count'] ?? 0);
        if ($rowCount !== count($slugs)) {
            throw new RuntimeException('--directory-draft-scope row_count does not match rows.');
        }

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path) ?: '',
            'payload' => $payload,
            'rows' => $rows,
            'slugs' => $slugs,
        ];
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

        $this->guardSlugs($slugs, '--slugs');

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function guardSlugs(array $slugs, string $source): void
    {
        $protected = array_values(array_intersect($slugs, self::PROTECTED_SLUGS));
        if ($protected !== []) {
            throw new RuntimeException('Protected validated slug(s) cannot be authority-aligned by '.$source.': '.implode(', ', $protected).'.');
        }

        $manualHold = array_values(array_intersect($slugs, self::MANUAL_HOLD_SLUGS));
        if ($manualHold !== []) {
            throw new RuntimeException('Manual-hold slug(s) cannot be authority-aligned by '.$source.': '.implode(', ', $manualHold).'.');
        }

        $cnSlugs = array_values(array_filter($slugs, static fn (string $slug): bool => str_starts_with($slug, 'cn-')));
        if ($cnSlugs !== []) {
            throw new RuntimeException('CN proxy slug(s) cannot be authority-aligned by '.$source.': '.implode(', ', $cnSlugs).'.');
        }
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array<string, mixed>
     */
    private function validateRow(string $slug, array $row, bool $force): array
    {
        $workbookSoc = trim((string) ($row['SOC_Code'] ?? ''));
        $workbookOnet = trim((string) ($row['O_NET_Code'] ?? ''));
        $titleEn = $this->presentTitle((string) ($row['EN_Title'] ?? ''), $slug);
        $titleZh = $this->presentTitle((string) ($row['CN_Title'] ?? ''), $slug);
        $errors = [];

        $this->expect($workbookSoc !== '', 'SOC_Code is missing.', $errors);
        $this->expect($workbookOnet !== '', 'O_NET_Code is missing.', $errors);
        $this->expect($this->isNormalSocCode($workbookSoc), 'SOC_Code must be a normal direct US SOC code.', $errors);
        $this->expect($this->isNormalOnetCode($workbookOnet), 'O_NET_Code must be a normal direct O*NET code.', $errors);
        $this->expect(! $this->rowContainsRejectedAuthorityToken($row), 'Workbook row contains a rejected CN/proxy/broad/multiple O*NET authority token.', $errors);

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
                displayAssetCount: 0,
                errors: $errors,
            );
        }

        $socCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_SOC)
            ->get(['id', 'source_system', 'source_code']);
        $onetCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_ONET)
            ->get(['id', 'source_system', 'source_code']);
        $displayAssetCount = CareerJobDisplayAsset::query()->where('canonical_slug', $slug)->count();

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
            displayAssetCount: $displayAssetCount,
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
            displayAssetCount: 0,
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
        int $displayAssetCount,
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
            'workbook_us_soc' => $workbookSoc,
            'workbook_onet' => $workbookOnet,
            'title_en' => $titleEn,
            'title_zh' => $titleZh,
            'existing_us_soc_crosswalks' => $existingSoc,
            'existing_onet_crosswalks' => $existingOnet,
            'would_create_us_soc_crosswalk' => ! $socExists && $valid,
            'would_create_onet_soc_2019_crosswalk' => ! $onetExists && $valid,
            'already_exists_us_soc_crosswalk' => $socExists && $valid,
            'already_exists_onet_soc_2019_crosswalk' => $onetExists && $valid,
            'display_asset_count_before' => $displayAssetCount,
            'display_asset_created' => false,
            'release_gates_changed' => false,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $scopeRow
     * @return array<string, mixed>
     */
    private function validateDirectoryDraftItem(array $item, array $scopeRow): array
    {
        $errors = $item['errors'] ?? [];
        $status = (string) ($scopeRow['status'] ?? '');
        $authorityState = (string) ($scopeRow['authority_state'] ?? data_get($scopeRow, 'authority_gate.authority_state', ''));
        $holdReason = trim((string) ($scopeRow['hold_reason'] ?? ''));

        $this->expect($status === 'needs_authority_alignment', 'Directory draft scope row must have status=needs_authority_alignment.', $errors);
        $this->expect($authorityState === 'directory_draft', 'Directory draft scope row must have authority_state=directory_draft.', $errors);
        $this->expect($holdReason === '', 'Held rows cannot be resolved by directory draft transition.', $errors);
        $this->expect(($item['occupation_found'] ?? false) === true, 'Existing occupation is required for directory draft transition.', $errors);
        $this->expect(($item['already_exists_us_soc_crosswalk'] ?? false) === true, 'Existing us_soc crosswalk is required before directory draft transition.', $errors);
        $this->expect(($item['already_exists_onet_soc_2019_crosswalk'] ?? false) === true, 'Existing onet_soc_2019 crosswalk is required before directory draft transition.', $errors);
        $this->expect((int) ($item['display_asset_count_before'] ?? 0) === 0, 'Existing display asset rows cannot be changed by directory draft transition.', $errors);

        $occupation = Occupation::query()->where('canonical_slug', $item['slug'])->first();
        $currentMode = $occupation instanceof Occupation ? (string) $occupation->crosswalk_mode : '';
        $item['current_crosswalk_mode'] = $currentMode;
        $item['target_crosswalk_mode'] = 'direct_match';
        $item['would_transition_directory_draft'] = $errors === [] && $currentMode === 'directory_draft';
        $item['already_resolved_directory_draft'] = $errors === [] && $currentMode === 'direct_match';
        $item['authority_state_transitioned'] = false;

        if ($errors === [] && ! in_array($currentMode, ['directory_draft', 'direct_match'], true)) {
            $errors[] = 'Occupation crosswalk_mode must be directory_draft or already direct_match.';
            $item['would_transition_directory_draft'] = false;
            $item['already_resolved_directory_draft'] = false;
        }

        $item['errors'] = $errors;

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{path:string, sha256:string, payload:array<string,mixed>, rows:array<string,array<string,mixed>>, slugs:list<string>}  $scope
     * @return array<string, mixed>
     */
    private function applyDirectoryDraftTransition(array $items, array $scope, string $file): array
    {
        $transitioned = [];

        foreach ($items as $item) {
            if (($item['would_transition_directory_draft'] ?? false) !== true) {
                continue;
            }

            $occupation = Occupation::query()
                ->where('canonical_slug', $item['slug'])
                ->where('crosswalk_mode', 'directory_draft')
                ->first();

            if (! $occupation instanceof Occupation) {
                continue;
            }

            $trustScope = is_array($occupation->trust_inheritance_scope)
                ? $occupation->trust_inheritance_scope
                : [];
            $occupation->forceFill([
                'crosswalk_mode' => 'direct_match',
                'trust_inheritance_scope' => array_merge($trustScope, [
                    'status' => 'career_upload_directory_draft_resolved',
                    'previous_crosswalk_mode' => 'directory_draft',
                    'source_asset' => basename($file),
                    'source_scope' => basename($scope['path']),
                    'source_plan' => basename((string) ($scope['payload']['source_plan'] ?? '')),
                ]),
            ])->save();

            $transitioned[] = [
                'slug' => $item['slug'],
                'occupation_id' => $occupation->id,
                'from' => 'directory_draft',
                'to' => 'direct_match',
            ];
        }

        return [
            'transitioned_directory_draft_count' => count($transitioned),
            'transitioned_directory_drafts' => $transitioned,
            'created_occupation_count' => 0,
            'created_crosswalk_count' => 0,
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

    /**
     * @param  array<string, string|int>  $row
     */
    private function rowContainsRejectedAuthorityToken(array $row): bool
    {
        $authorityText = strtolower(implode(' ', [
            (string) ($row['Slug'] ?? ''),
            (string) ($row['SOC_Code'] ?? ''),
            (string) ($row['O_NET_Code'] ?? ''),
        ]));

        return $this->containsRejectedToken($authorityText);
    }

    private function containsRejectedToken(string $value): bool
    {
        $lower = strtolower($value);
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
     * @return array<string, int>
     */
    private function summarizeDirectoryDraftItems(array $items): array
    {
        return [
            'would_transition_directory_draft_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_transition_directory_draft'] ?? false) === true)),
            'transitioned_directory_draft_count' => 0,
            'already_resolved_directory_draft_count' => count(array_filter($items, static fn (array $item): bool => ($item['already_resolved_directory_draft'] ?? false) === true)),
            'occupation_delta' => 0,
            'occupation_crosswalk_delta' => 0,
            'career_job_display_assets_delta' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    private function summarizeAfterForce(array $items, array $result): array
    {
        return [
            'would_create_occupation_count' => 0,
            'would_create_crosswalk_count' => 0,
            'already_exists_occupation_count' => count($items),
            'already_exists_crosswalk_count' => count($items) * 2,
            'failed_count' => 0,
            'created_occupation_count' => (int) ($result['created_occupation_count'] ?? 0),
            'created_crosswalk_count' => (int) ($result['created_crosswalk_count'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    private function summarizeDirectoryDraftAfterForce(array $items, array $result): array
    {
        return [
            'would_transition_directory_draft_count' => 0,
            'already_resolved_directory_draft_count' => count($items),
            'transitioned_directory_draft_count' => (int) ($result['transitioned_directory_draft_count'] ?? 0),
            'occupation_delta' => 0,
            'occupation_crosswalk_delta' => 0,
            'career_job_display_assets_delta' => 0,
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
            'manual_hold_slugs' => self::MANUAL_HOLD_SLUGS,
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
            $writeCount = (int) ($report['created_occupation_count'] ?? 0)
                + (int) ($report['created_crosswalk_count'] ?? 0)
                + (int) ($report['transitioned_directory_draft_count'] ?? 0);
            $report['read_only'] = false;
            $report['writes_database'] = $success && $writeCount > 0;
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
            if (array_key_exists('transitioned_directory_draft_count', $report)) {
                $this->line('transitioned_directory_draft_count='.(string) $report['transitioned_directory_draft_count']);
            }
            $this->line('decision='.$report['decision']);
            if (isset($report['errors'])) {
                $this->line('errors='.json_encode($report['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function presentTitle(string $title, string $slug): string
    {
        $trimmed = trim($title);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return str_replace(' ', ' ', ucwords(str_replace('-', ' ', $slug)));
    }

    private function searchH1Zh(string $titleZh): string
    {
        $title = trim($titleZh);

        return $title === '' ? '职业详情' : $title.'适合谁？';
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message === '' ? $throwable::class : $message;
    }
}
