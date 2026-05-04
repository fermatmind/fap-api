<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Services\Career\Import\CareerSelectedDisplayAssetMapper;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CareerImportSelectedDisplayAssets extends Command
{
    private const COMMAND_NAME = 'career:import-selected-display-assets';

    private const VALIDATOR_VERSION = 'career_selected_display_asset_import_v0.1';

    private const SOURCE_SYSTEM_SOC = 'us_soc';

    private const SOURCE_SYSTEM_ONET = 'onet_soc_2019';

    private const PUBLIC_CAREER_JOB_API = 'https://api.fermatmind.com/api/v0.5/career/jobs';

    protected $signature = 'career:import-selected-display-assets
        {--file= : Absolute path to repaired second-pilot workbook}
        {--slugs= : Comma-separated explicit slug allowlist}
        {--dry-run : Validate and report without writing}
        {--force : Required to write selected display asset rows}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}';

    protected $description = 'Guarded dry-run/force import for selected second-pilot career display assets.';

    public function __construct(private readonly CareerSelectedDisplayAssetMapper $mapper)
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
            $slugs = $this->requiredSlugs();
            $workbook = $this->mapper->readWorkbook($file, $slugs);
            $missingHeaders = array_values(array_diff(CareerSelectedDisplayAssetMapper::REQUIRED_HEADERS, $workbook['headers']));
            if ($missingHeaders !== []) {
                return $this->finish(array_merge($report, [
                    'mode' => $force ? 'force' : 'dry_run',
                    'source_file_basename' => basename($file),
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
                    $rowsBySlug[$slug][] = $row;
                }
            }

            $items = [];
            $errors = [];
            foreach ($slugs as $slug) {
                $rows = $rowsBySlug[$slug] ?? [];
                if (count($rows) !== 1) {
                    $errors[] = count($rows) === 0
                        ? "Allowlisted slug {$slug} was not found in workbook."
                        : "Allowlisted slug {$slug} appears more than once in workbook.";

                    continue;
                }

                $mapped = $this->mapper->mapRow($rows[0]);
                $authority = $this->validateAuthority($slug, $mapped['expected_soc'], $mapped['expected_onet'], $force);
                $itemErrors = array_merge($mapped['errors'], $authority['errors']);
                foreach ($itemErrors as $error) {
                    $errors[] = "{$slug}: {$error}";
                }

                $items[] = $this->item($mapped, $authority, $itemErrors);
            }

            $summary = $this->summarize($items);
            $report = array_merge($report, $summary, [
                'mode' => $force ? 'force' : 'dry_run',
                'source_file_basename' => basename($file),
                'source_file_sha256' => hash_file('sha256', $file) ?: null,
                'requested_slugs' => $slugs,
                'total_rows' => $workbook['total_rows'],
                'validated_count' => count($items),
                'items' => $items,
            ]);

            if ($errors !== []) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => $errors,
                ]), false);
            }

            $report['decision'] = ($report['failed_count'] ?? 0) === 0 && $items !== []
                ? 'pass'
                : 'no_go';
            $report['would_write'] = $report['would_write_count'] > 0;

            if (! $force) {
                return $this->finish($report, true);
            }

            $written = DB::transaction(function () use ($items, $file): array {
                $written = [];
                foreach ($items as $item) {
                    if (($item['would_write'] ?? false) !== true) {
                        continue;
                    }

                    /** @var array<string, mixed> $payload */
                    $payload = $item['payload'];
                    /** @var Occupation $occupation */
                    $occupation = Occupation::query()
                        ->where('canonical_slug', $item['slug'])
                        ->firstOrFail();

                    $asset = CareerJobDisplayAsset::query()->updateOrCreate(
                        [
                            'canonical_slug' => $item['slug'],
                            'asset_version' => CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION,
                        ],
                        [
                            'occupation_id' => $occupation->id,
                            'surface_version' => CareerSelectedDisplayAssetMapper::SURFACE_VERSION,
                            'template_version' => CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION,
                            'asset_type' => CareerSelectedDisplayAssetMapper::ASSET_TYPE,
                            'asset_role' => CareerSelectedDisplayAssetMapper::ASSET_ROLE,
                            'status' => CareerSelectedDisplayAssetMapper::STATUS,
                            'component_order_json' => $payload['component_order_json'],
                            'page_payload_json' => $payload['page_payload_json'],
                            'seo_payload_json' => $payload['seo_payload_json'],
                            'sources_json' => $payload['sources_json'],
                            'structured_data_json' => $payload['structured_data_json'],
                            'implementation_contract_json' => $payload['implementation_contract_json'],
                            'metadata_json' => $this->metadata($item, $file),
                        ],
                    );

                    $written[] = [
                        'slug' => $item['slug'],
                        'row_id' => $asset->id,
                        'created' => $asset->wasRecentlyCreated,
                    ];
                }

                return $written;
            });

            return $this->finish(array_merge($report, [
                'did_write' => count($written) > 0,
                'written_assets' => $written,
                'created_count' => count(array_filter($written, static fn (array $row): bool => ($row['created'] ?? false) === true)),
                'updated_count' => count(array_filter($written, static fn (array $row): bool => ($row['created'] ?? false) !== true)),
            ]), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
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
            static fn (string $slug): bool => ! array_key_exists($slug, CareerSelectedDisplayAssetMapper::ALLOWED_SLUGS),
        ));
        if ($invalid !== []) {
            throw new RuntimeException('Unsupported slug(s) for selected display asset import: '.implode(', ', $invalid).'.');
        }

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAuthority(string $slug, string $expectedSoc, string $expectedOnet, bool $force): array
    {
        try {
            $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
        } catch (QueryException $queryException) {
            if ($force) {
                throw $queryException;
            }

            return $this->validateAuthorityWithPublicApiFallback($slug, $expectedSoc, $expectedOnet);
        }

        if (! $occupation instanceof Occupation) {
            return [
                'authority_source' => 'local_db',
                'occupation_found' => false,
                'occupation_id' => null,
                'soc_crosswalk_valid' => false,
                'onet_crosswalk_valid' => false,
                'existing_display_asset' => false,
                'errors' => ['Occupation is missing; this command must not create occupations.'],
            ];
        }

        $socCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_SOC)
            ->get(['source_system', 'source_code']);
        $onetCrosswalks = $occupation->crosswalks()
            ->where('source_system', self::SOURCE_SYSTEM_ONET)
            ->get(['source_system', 'source_code']);

        $errors = [];
        $socValid = $socCrosswalks->contains(static fn (OccupationCrosswalk $crosswalk): bool => $crosswalk->source_code === $expectedSoc);
        $onetValid = $onetCrosswalks->contains(static fn (OccupationCrosswalk $crosswalk): bool => $crosswalk->source_code === $expectedOnet);

        if (! $socValid) {
            $errors[] = "Existing us_soc crosswalk must match {$expectedSoc}.";
        }
        if (! $onetValid) {
            $errors[] = "Existing onet_soc_2019 crosswalk must match {$expectedOnet}.";
        }

        return [
            'authority_source' => 'local_db',
            'occupation_found' => true,
            'occupation_id' => $occupation->id,
            'soc_crosswalk_valid' => $socValid,
            'onet_crosswalk_valid' => $onetValid,
            'existing_display_asset' => CareerJobDisplayAsset::query()
                ->where('canonical_slug', $slug)
                ->where('asset_version', CareerSelectedDisplayAssetMapper::TEMPLATE_VERSION)
                ->exists(),
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAuthorityWithPublicApiFallback(string $slug, string $expectedSoc, string $expectedOnet): array
    {
        $errors = [];
        $blockingReasons = [];
        $occupationFound = false;
        $socValid = false;
        $onetValid = false;

        try {
            $response = Http::timeout(5)->get(self::PUBLIC_CAREER_JOB_API.'/'.$slug, [
                'locale' => 'zh-CN',
            ]);

            if (! $response->ok()) {
                $blockingReasons[] = 'Local authority DB is unavailable and public API fallback did not return 200.';
            } else {
                $payload = $response->json();
                $reasonCodes = data_get($payload, 'seo_contract.reason_codes', []);
                $contentVersion = (string) data_get($payload, 'provenance_meta.content_version', '');
                $isDocxFallback = is_array($reasonCodes) && in_array('docx_baseline_authority', $reasonCodes, true)
                    || str_contains($contentVersion, 'docx');

                if ($isDocxFallback) {
                    $blockingReasons[] = 'Public API fallback is DOCX baseline, not authority-backed.';
                }

                $occupationUuid = (string) data_get($payload, 'identity.occupation_uuid', '');
                $canonicalSlug = (string) data_get($payload, 'identity.canonical_slug', '');
                $occupationFound = $occupationUuid !== '' && $canonicalSlug === $slug && ! $isDocxFallback;
                $crosswalks = data_get($payload, 'ontology.crosswalks', []);
                if (is_array($crosswalks)) {
                    foreach ($crosswalks as $crosswalk) {
                        if (! is_array($crosswalk)) {
                            continue;
                        }
                        $sourceSystem = (string) ($crosswalk['source_system'] ?? '');
                        $sourceCode = (string) ($crosswalk['source_code'] ?? '');
                        $socValid = $socValid || ($sourceSystem === self::SOURCE_SYSTEM_SOC && $sourceCode === $expectedSoc);
                        $onetValid = $onetValid || ($sourceSystem === self::SOURCE_SYSTEM_ONET && $sourceCode === $expectedOnet);
                    }
                }
            }
        } catch (Throwable) {
            $blockingReasons[] = 'Local authority DB is unavailable and public API fallback request failed.';
        }

        if (! $occupationFound) {
            $blockingReasons[] = 'Authority occupation could not be verified.';
        }
        if (! $socValid) {
            $blockingReasons[] = "Public API fallback us_soc crosswalk must match {$expectedSoc}.";
        }
        if (! $onetValid) {
            $blockingReasons[] = "Public API fallback onet_soc_2019 crosswalk must match {$expectedOnet}.";
        }

        $authorityState = 'authority_unavailable';
        if ($occupationFound && $socValid && $onetValid) {
            $authorityState = 'public_api_fallback_verified';
        } elseif (app()->environment(['local', 'testing'])) {
            $authorityState = 'local_dry_run_authority_deferred';
            $blockingReasons[] = 'Local dry-run deferred authority validation to target DB dry-run because the local authority DB is unavailable.';
        } else {
            $errors = $blockingReasons;
        }

        return [
            'authority_source' => 'public_api_fallback',
            'authority_state' => $authorityState,
            'occupation_found' => $occupationFound,
            'occupation_id' => null,
            'soc_crosswalk_valid' => $socValid,
            'onet_crosswalk_valid' => $onetValid,
            'existing_display_asset' => false,
            'blocking_reasons' => $blockingReasons,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $authority
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function item(array $mapped, array $authority, array $errors): array
    {
        $authorityReady = ($authority['occupation_found'] ?? false) === true
            && ($authority['soc_crosswalk_valid'] ?? false) === true
            && ($authority['onet_crosswalk_valid'] ?? false) === true;
        $authorityDeferredForLocalDryRun = ($authority['authority_state'] ?? null) === 'local_dry_run_authority_deferred';

        return [
            'slug' => $mapped['slug'],
            'row_number' => $mapped['row_number'],
            'authority_source' => $authority['authority_source'],
            'authority_state' => $authority['authority_state'] ?? ($authority['authority_source'] === 'local_db' ? 'local_db' : 'authority_unavailable'),
            'occupation_found' => $authority['occupation_found'],
            'occupation_id' => $authority['occupation_id'],
            'soc_crosswalk_valid' => $authority['soc_crosswalk_valid'],
            'onet_crosswalk_valid' => $authority['onet_crosswalk_valid'],
            'existing_display_asset' => $authority['existing_display_asset'],
            'would_write' => $errors === []
                && ($authorityReady || $authorityDeferredForLocalDryRun)
                && ($authority['existing_display_asset'] ?? false) !== true,
            'payload_summary' => $mapped['summary'],
            'payload' => $mapped['payload'],
            'release_gates_changed' => false,
            'blocking_reasons' => $authority['blocking_reasons'] ?? [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summarize(array $items): array
    {
        return [
            'would_write_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_write'] ?? false) === true)),
            'already_exists_count' => count(array_filter($items, static fn (array $item): bool => ($item['existing_display_asset'] ?? false) === true && ($item['errors'] ?? []) === [])),
            'failed_count' => count(array_filter($items, static fn (array $item): bool => ($item['errors'] ?? []) !== [])),
            'created_count' => 0,
            'updated_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function metadata(array $item, string $file): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'mapper_version' => CareerSelectedDisplayAssetMapper::MAPPER_VERSION,
            'workbook_basename' => basename($file),
            'workbook_sha256' => hash_file('sha256', $file) ?: null,
            'slug' => $item['slug'],
            'row_number' => $item['row_number'],
            'row_fingerprint' => $this->rowFingerprint($item),
            'imported_at' => now()->toISOString(),
            'release_gates' => [
                'sitemap' => false,
                'llms' => false,
                'paid' => false,
                'backlink' => false,
            ],
            'display_import_stage' => 'second_pilot_selected',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rowFingerprint(array $item): string
    {
        $payload = [
            'slug' => $item['slug'] ?? null,
            'row_number' => $item['row_number'] ?? null,
            'payload_summary' => $item['payload_summary'] ?? [],
            'payload' => $item['payload'] ?? [],
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'mapper_version' => CareerSelectedDisplayAssetMapper::MAPPER_VERSION,
            'mode' => 'dry_run',
            'read_only' => true,
            'writes_database' => false,
            'requested_slugs' => [],
            'total_rows' => null,
            'validated_count' => 0,
            'items' => [],
            'would_write' => false,
            'would_write_count' => 0,
            'already_exists_count' => 0,
            'did_write' => false,
            'created_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
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
            $report['writes_database'] = $success && (($report['created_count'] ?? 0) + ($report['updated_count'] ?? 0) > 0);
        }

        if (isset($report['items']) && is_array($report['items'])) {
            $report['validated_count'] = count($report['items']);
        }

        $outputReport = $this->outputReport($report);
        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            file_put_contents($outputPath, json_encode($outputReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($outputReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('mode='.$report['mode']);
            $this->line('validated_count='.(string) $report['validated_count']);
            $this->line('would_write_count='.(string) $report['would_write_count']);
            $this->line('failed_count='.(string) $report['failed_count']);
            $this->line('created_count='.(string) $report['created_count']);
            $this->line('updated_count='.(string) $report['updated_count']);
            $this->line('decision='.$report['decision']);
            if (isset($report['errors'])) {
                $this->line('errors='.json_encode($report['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function outputReport(array $report): array
    {
        if (! isset($report['items']) || ! is_array($report['items'])) {
            return $report;
        }

        $report['items'] = array_map(static function (array $item): array {
            unset($item['payload']);

            return $item;
        }, $report['items']);

        return $report;
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException) {
            return 'Database validation failed while reading occupation authority tables or writing display assets.';
        }

        return $throwable->getMessage();
    }
}
