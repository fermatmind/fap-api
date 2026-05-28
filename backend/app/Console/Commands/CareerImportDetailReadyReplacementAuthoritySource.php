<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CareerImportDetailReadyReplacementAuthoritySource extends Command
{
    private const COMMAND_NAME = 'career:import-detail-ready-replacement-authority-source';

    private const VALIDATOR_VERSION = 'detail_ready_1048_replacement_authority_source_import_v0.1';

    private const DEFAULT_PACKAGE = 'docs/seo/import-packages/detail-ready-1048-replacement-authority-source-repair-01.import.v1.json';

    private const EXPECTED_TASK = 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01';

    private const EXPECTED_PACKAGE_TYPE = 'career_detail_ready_replacement_authority_source_repair';

    private const TARGET_SLUG = 'digital-forensics-analysts';

    private const TARGET_FAMILY_SLUG = 'computer-and-information-technology';

    private const MANUAL_HOLD_SLUG = 'software-developers';

    private const EXPECTED_ONET_CODE = '15-1299.06';

    private const EXPECTED_US_SOC_CODE = '15-1299';

    private const CONFIRM_PHRASE = 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT_APPROVED';

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    protected $signature = 'career:import-detail-ready-replacement-authority-source
        {--package= : Source repair package path; defaults to the repo-backed source repair package}
        {--apply : Write the reviewed replacement authority source rows}
        {--confirm= : Required confirmation phrase when --apply is used}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}';

    protected $description = 'Validate and optionally import the reviewed detail-ready 1048 replacement authority source package.';

    public function handle(): int
    {
        $report = $this->baseReport();

        try {
            $apply = (bool) $this->option('apply');
            $report['mode'] = $apply ? 'apply' : 'dry_run';

            if ($apply && trim((string) $this->option('confirm')) !== self::CONFIRM_PHRASE) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => ['--confirm must equal '.self::CONFIRM_PHRASE.' when --apply is used.'],
                ]), false);
            }

            $packagePath = $this->packagePath();
            $package = $this->readJson($packagePath);
            $plan = $this->validatePackage($package);
            $report = array_merge($report, $plan['report'], [
                'package_path' => $packagePath,
                'package_sha256' => hash_file('sha256', $packagePath) ?: null,
            ]);

            if ($plan['errors'] !== []) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => $plan['errors'],
                ]), false);
            }

            $report['would_write'] = true;
            $report['decision'] = 'pass';

            if (! $apply) {
                return $this->finish($report, true);
            }

            $writeSummary = DB::transaction(function () use ($plan, $package, $packagePath): array {
                $familyPayload = $plan['family_payload'];
                $occupationPayload = $plan['occupation_payload'];
                $crosswalkPayloads = $plan['crosswalk_payloads'];
                $assetPayload = $plan['asset_payload'];

                $importRun = CareerImportRun::query()->create([
                    'dataset_name' => 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01',
                    'dataset_version' => (string) ($package['schema_version'] ?? 'unknown'),
                    'dataset_checksum' => hash_file('sha256', $packagePath) ?: hash('sha256', json_encode($package, JSON_THROW_ON_ERROR)),
                    'source_path' => $packagePath,
                    'scope_mode' => 'detail_ready_replacement_authority_source',
                    'dry_run' => false,
                    'status' => 'completed',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'rows_seen' => 1,
                    'rows_accepted' => 1,
                    'rows_skipped' => 0,
                    'rows_failed' => 0,
                    'output_counts' => [
                        'occupation_families' => 1,
                        'occupations' => 1,
                        'occupation_crosswalks' => count($crosswalkPayloads),
                        'career_job_display_assets' => 1,
                        'index_states' => 0,
                        'runtime_promotions' => 0,
                    ],
                    'error_summary' => [],
                    'meta' => [
                        'command' => self::COMMAND_NAME,
                        'validator_version' => self::VALIDATOR_VERSION,
                        'target_slug' => self::TARGET_SLUG,
                        'manual_hold_slug_kept_excluded' => self::MANUAL_HOLD_SLUG,
                        'runtime_promotion_performed' => false,
                        'sitemap_llms_footer_exposure_performed' => false,
                    ],
                ]);

                $family = OccupationFamily::query()->updateOrCreate(
                    ['canonical_slug' => self::TARGET_FAMILY_SLUG],
                    [
                        'title_en' => (string) $familyPayload['title_en'],
                        'title_zh' => (string) $familyPayload['title_zh'],
                    ]
                );

                $occupation = Occupation::query()->updateOrCreate(
                    ['canonical_slug' => self::TARGET_SLUG],
                    [
                        'family_id' => $family->id,
                        'entity_level' => (string) $occupationPayload['entity_level'],
                        'truth_market' => (string) $occupationPayload['truth_market'],
                        'display_market' => (string) $occupationPayload['display_market'],
                        'crosswalk_mode' => (string) $occupationPayload['crosswalk_mode'],
                        'canonical_title_en' => (string) $occupationPayload['canonical_title_en'],
                        'canonical_title_zh' => (string) $occupationPayload['canonical_title_zh'],
                        'search_h1_zh' => (string) $occupationPayload['search_h1_zh'],
                    ]
                );

                $crosswalkIds = [];
                foreach ($crosswalkPayloads as $crosswalkPayload) {
                    $crosswalk = OccupationCrosswalk::query()->updateOrCreate(
                        [
                            'occupation_id' => $occupation->id,
                            'source_system' => (string) $crosswalkPayload['source_system'],
                            'source_code' => (string) $crosswalkPayload['source_code'],
                        ],
                        [
                            'source_title' => (string) $crosswalkPayload['source_title'],
                            'mapping_type' => (string) $crosswalkPayload['mapping_type'],
                            'confidence_score' => (float) $crosswalkPayload['confidence_score'],
                            'notes' => (string) ($crosswalkPayload['notes'] ?? ''),
                            'import_run_id' => $importRun->id,
                            'row_fingerprint' => hash('sha256', implode('|', [
                                'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01',
                                self::TARGET_SLUG,
                                (string) $crosswalkPayload['source_system'],
                                (string) $crosswalkPayload['source_code'],
                            ])),
                        ]
                    );
                    $crosswalkIds[] = $crosswalk->id;
                }

                $asset = CareerJobDisplayAsset::query()->updateOrCreate(
                    [
                        'canonical_slug' => self::TARGET_SLUG,
                        'asset_version' => (string) $assetPayload['asset_version'],
                    ],
                    [
                        'occupation_id' => $occupation->id,
                        'surface_version' => (string) $assetPayload['surface_version'],
                        'template_version' => (string) $assetPayload['template_version'],
                        'asset_type' => (string) $assetPayload['asset_type'],
                        'asset_role' => (string) $assetPayload['asset_role'],
                        'status' => (string) $assetPayload['status'],
                        'component_order_json' => $assetPayload['component_order_json'],
                        'page_payload_json' => $assetPayload['page_payload_json'],
                        'seo_payload_json' => $assetPayload['seo_payload_json'] ?? null,
                        'sources_json' => $assetPayload['sources_json'] ?? null,
                        'structured_data_json' => $assetPayload['structured_data_json'] ?? null,
                        'implementation_contract_json' => $assetPayload['implementation_contract_json'] ?? null,
                        'metadata_json' => array_merge((array) ($assetPayload['metadata_json'] ?? []), [
                            'controlled_import_task' => 'DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01',
                            'runtime_promotion_performed' => false,
                        ]),
                        'import_run_id' => $importRun->id,
                    ]
                );

                return [
                    'import_run_id' => $importRun->id,
                    'family_id' => $family->id,
                    'occupation_id' => $occupation->id,
                    'crosswalk_ids' => $crosswalkIds,
                    'display_asset_id' => $asset->id,
                ];
            });

            return $this->finish(array_merge($report, $writeSummary, [
                'did_write' => true,
                'runtime_promotion_performed' => false,
                'index_state_rows_written' => 0,
            ]), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
            ]), false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'mode' => 'dry_run',
            'target_slug' => self::TARGET_SLUG,
            'manual_hold_slug_kept_excluded' => self::MANUAL_HOLD_SLUG,
            'family_valid' => false,
            'occupation_valid' => false,
            'onet_crosswalk_valid' => false,
            'us_soc_crosswalk_valid' => false,
            'display_asset_valid' => false,
            'public_payload_forbidden_keys_found' => [],
            'existing_occupation_found' => false,
            'existing_index_state_rows' => 0,
            'existing_indexable_state_rows' => 0,
            'would_write' => false,
            'did_write' => false,
            'runtime_promotion_performed' => false,
            'sitemap_llms_footer_exposure_performed' => false,
            'decision' => 'fail',
            'errors' => [],
        ];
    }

    private function packagePath(): string
    {
        $path = trim((string) $this->option('package'));
        if ($path === '') {
            $path = base_path(self::DEFAULT_PACKAGE);
        } elseif (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            throw new \RuntimeException('--package does not exist: '.$path);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON package: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{report: array<string, mixed>, errors: list<string>, family_payload?: array<string, mixed>, occupation_payload?: array<string, mixed>, crosswalk_payloads?: list<array<string, mixed>>, asset_payload?: array<string, mixed>}
     */
    private function validatePackage(array $package): array
    {
        $errors = [];
        $source = $this->arrayValue($package, 'replacement_source');
        $family = $this->arrayValue($source, 'proposed_family_import');
        $occupation = $this->arrayValue($source, 'proposed_occupation_import');
        $asset = $this->arrayValue($source, 'proposed_display_asset_import');
        $crosswalks = $source['proposed_crosswalk_imports'] ?? [];
        $crosswalks = is_array($crosswalks) ? array_values(array_filter($crosswalks, 'is_array')) : [];
        $exposure = $this->arrayValue($package, 'exposure_policy');

        $this->expect(($package['task'] ?? null) === self::EXPECTED_TASK, 'package.task must match '.self::EXPECTED_TASK.'.', $errors);
        $this->expect(($package['package_type'] ?? null) === self::EXPECTED_PACKAGE_TYPE, 'package_type must be '.self::EXPECTED_PACKAGE_TYPE.'.', $errors);

        $this->expect($this->stringValue($source, 'canonical_slug') === self::TARGET_SLUG, 'replacement_source.canonical_slug must be '.self::TARGET_SLUG.'.', $errors);
        $this->expect((bool) ($source['manual_hold'] ?? true) === false, 'replacement_source.manual_hold must be false.', $errors);
        $this->expect((bool) ($source['cn_proxy'] ?? true) === false, 'replacement_source.cn_proxy must be false.', $errors);
        $this->expect((bool) ($source['known_blocked_slug'] ?? true) === false, 'replacement_source.known_blocked_slug must be false.', $errors);
        $this->expect((bool) ($source['existing_union_member_must_be_false_in_target_authority'] ?? false) === true, 'replacement_source.existing_union_member_must_be_false_in_target_authority must be true.', $errors);

        foreach (['sitemap_eligible', 'llms_eligible', 'footer_eligible', 'search_channel_eligible', 'runtime_public'] as $flag) {
            $this->expect(($exposure[$flag] ?? null) === false, 'exposure_policy.'.$flag.' must be false.', $errors);
        }

        $familyValid = $this->stringValue($family, 'canonical_slug') === self::TARGET_FAMILY_SLUG
            && $this->stringValue($family, 'title_en') !== ''
            && $this->stringValue($family, 'title_zh') !== '';

        $occupationValid = $this->stringValue($occupation, 'canonical_slug') === self::TARGET_SLUG
            && $this->stringValue($occupation, 'entity_level') !== ''
            && $this->stringValue($occupation, 'truth_market') === 'US'
            && $this->stringValue($occupation, 'canonical_title_en') !== ''
            && $this->stringValue($occupation, 'canonical_title_zh') !== ''
            && $this->stringValue($occupation, 'search_h1_zh') !== '';

        $onet = $this->firstCrosswalk($crosswalks, 'onet_soc_2019');
        $usSoc = $this->firstCrosswalk($crosswalks, 'us_soc');

        $onetValid = $this->stringValue($onet, 'source_code') === self::EXPECTED_ONET_CODE
            && $this->stringValue($onet, 'source_title') !== ''
            && $this->stringValue($onet, 'mapping_type') === 'direct_onet_soc_2019';

        $usSocValid = $this->stringValue($usSoc, 'source_code') === self::EXPECTED_US_SOC_CODE
            && $this->stringValue($usSoc, 'source_title') !== ''
            && $this->stringValue($usSoc, 'mapping_type') === 'same_soc_family_from_onet_soc_2019';

        $displayAssetValid = $this->stringValue($asset, 'canonical_slug') === self::TARGET_SLUG
            && $this->stringValue($asset, 'surface_version') === 'display.surface.v1'
            && $this->stringValue($asset, 'asset_version') === 'v4.2'
            && $this->stringValue($asset, 'template_version') === 'v4.2'
            && $this->stringValue($asset, 'asset_type') === 'career_job_public_display'
            && $this->stringValue($asset, 'asset_role') === 'formal_pilot_master'
            && $this->stringValue($asset, 'status') === 'ready_for_pilot';

        $componentOrder = $asset['component_order_json'] ?? null;
        $pagePayload = $asset['page_payload_json'] ?? null;

        $this->expect($familyValid, 'Replacement family payload is invalid.', $errors);
        $this->expect($occupationValid, 'Replacement occupation payload is invalid.', $errors);
        $this->expect($onetValid, 'Replacement O*NET-SOC 2019 crosswalk payload is invalid.', $errors);
        $this->expect($usSocValid, 'Replacement us_soc crosswalk payload is invalid.', $errors);
        $this->expect($displayAssetValid, 'Replacement display asset payload is invalid.', $errors);
        $this->expect(is_array($componentOrder) && count($componentOrder) === 24, 'component_order_json must contain 24 components.', $errors);
        $this->expect(is_array($pagePayload) && is_array($pagePayload['page']['en'] ?? null), 'page_payload_json.page.en must exist.', $errors);
        $this->expect(is_array($pagePayload) && is_array($pagePayload['page']['zh'] ?? null), 'page_payload_json.page.zh must exist.', $errors);

        $forbiddenKeys = $this->forbiddenPublicKeys([
            'component_order_json' => $componentOrder,
            'page_payload_json' => $pagePayload,
            'seo_payload_json' => $asset['seo_payload_json'] ?? [],
            'sources_json' => $asset['sources_json'] ?? [],
            'structured_data_json' => $asset['structured_data_json'] ?? [],
            'implementation_contract_json' => $asset['implementation_contract_json'] ?? [],
        ]);
        if ($forbiddenKeys !== []) {
            $errors[] = 'Forbidden public payload keys found: '.implode(', ', $forbiddenKeys).'.';
        }

        $existingOccupation = Occupation::query()->where('canonical_slug', self::TARGET_SLUG)->first();
        $existingOccupationFound = $existingOccupation instanceof Occupation;
        $existingIndexStateRows = $existingOccupationFound ? IndexState::query()->where('occupation_id', $existingOccupation->id)->count() : 0;
        $existingIndexableStateRows = $existingOccupationFound ? IndexState::query()
            ->where('occupation_id', $existingOccupation->id)
            ->where('index_eligible', true)
            ->count() : 0;
        $this->expect($existingIndexableStateRows === 0, 'Controlled source import must not target an already indexable occupation.', $errors);

        $report = [
            'target_slug' => self::TARGET_SLUG,
            'family_valid' => $familyValid,
            'occupation_valid' => $occupationValid,
            'onet_crosswalk_valid' => $onetValid,
            'us_soc_crosswalk_valid' => $usSocValid,
            'display_asset_valid' => $displayAssetValid,
            'public_payload_forbidden_keys_found' => $forbiddenKeys,
            'existing_occupation_found' => $existingOccupationFound,
            'existing_index_state_rows' => $existingIndexStateRows,
            'existing_indexable_state_rows' => $existingIndexableStateRows,
            'planned_writes' => [
                'occupation_families' => 1,
                'occupations' => 1,
                'occupation_crosswalks' => 2,
                'career_job_display_assets' => 1,
                'career_import_runs' => 1,
                'index_states' => 0,
                'runtime_promotions' => 0,
            ],
        ];

        $plan = [
            'report' => $report,
            'errors' => $errors,
        ];

        if ($familyValid) {
            $plan['family_payload'] = $family;
        }
        if ($occupationValid) {
            $plan['occupation_payload'] = $occupation;
        }
        if ($onetValid && $usSocValid) {
            $plan['crosswalk_payloads'] = [$onet, $usSoc];
        }
        if ($displayAssetValid) {
            $plan['asset_payload'] = $asset;
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $crosswalks
     * @return array<string, mixed>
     */
    private function firstCrosswalk(array $crosswalks, string $sourceSystem): array
    {
        foreach ($crosswalks as $crosswalk) {
            if ($this->stringValue($crosswalk, 'source_system') === $sourceSystem) {
                return $crosswalk;
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function forbiddenPublicKeys(array $payload, string $prefix = ''): array
    {
        $found = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                $found[] = $path;
            }
            if (is_array($value)) {
                array_push($found, ...$this->forbiddenPublicKeys($value, $path));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function arrayValue(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? trim((string) $payload[$key]) : '';
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
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            $json = '{}';
        }

        $output = trim((string) $this->option('output'));
        if ($output !== '') {
            file_put_contents($output, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } elseif ($success) {
            $this->info((string) ($report['mode'] ?? 'dry_run').' complete: '.$report['decision']);
        } else {
            $this->error('source import failed: '.implode('; ', (array) ($report['errors'] ?? [])));
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        return preg_replace('/\s+/', ' ', trim($throwable->getMessage())) ?: $throwable::class;
    }
}
