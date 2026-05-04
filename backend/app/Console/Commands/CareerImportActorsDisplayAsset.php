<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CareerImportActorsDisplayAsset extends Command
{
    private const COMMAND_NAME = 'career:import-actors-display-asset';

    private const VALIDATOR_VERSION = 'actors_display_asset_import_v0.1';

    private const TARGET_SLUG = 'actors';

    private const SURFACE_VERSION = 'display.surface.v1';

    private const TEMPLATE_VERSION = 'v4.2';

    private const ASSET_TYPE = 'career_job_public_display';

    private const ASSET_ROLE = 'formal_pilot_master';

    private const STATUS = 'ready_for_pilot';

    private const SOC_CODE = '27-2011';

    private const ONET_CODE = '27-2011.00';

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    protected $signature = 'career:import-actors-display-asset
        {--file= : Absolute path to actors_v4_2_pilot_master.json}
        {--dry-run : Validate and report without writing}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}
        {--force : Required to write the display asset}';

    protected $description = 'Validate and persist the guarded Actors v4.2 career display asset.';

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

            $report['mode'] = $force ? 'force' : 'dry_run';
            $file = $this->requiredFile();
            $payload = $this->readJson($file);
            $plan = $this->validatePayload($payload);
            $report = array_merge($report, $plan['report'], [
                'source_file_sha256' => hash_file('sha256', $file) ?: null,
            ]);

            if ($plan['errors'] !== []) {
                return $this->finish(array_merge($report, [
                    'would_write' => false,
                    'decision' => 'fail',
                    'errors' => $plan['errors'],
                ]), false);
            }

            $report['would_write'] = true;
            $report['decision'] = 'pass';

            if (! $force) {
                return $this->finish($report, true);
            }

            $asset = DB::transaction(function () use ($plan, $file): CareerJobDisplayAsset {
                /** @var Occupation $occupation */
                $occupation = $plan['occupation'];

                return CareerJobDisplayAsset::query()->updateOrCreate(
                    [
                        'canonical_slug' => self::TARGET_SLUG,
                        'asset_version' => self::TEMPLATE_VERSION,
                    ],
                    [
                        'occupation_id' => $occupation->id,
                        'surface_version' => self::SURFACE_VERSION,
                        'template_version' => self::TEMPLATE_VERSION,
                        'asset_type' => self::ASSET_TYPE,
                        'asset_role' => self::ASSET_ROLE,
                        'status' => self::STATUS,
                        'component_order_json' => $plan['component_order'],
                        'page_payload_json' => $plan['page'],
                        'seo_payload_json' => $plan['seo'],
                        'sources_json' => $plan['sources'],
                        'structured_data_json' => $plan['structured_data'],
                        'implementation_contract_json' => $plan['implementation_contract'],
                        'metadata_json' => $this->metadata($plan, $file),
                    ]
                );
            });

            return $this->finish(array_merge($report, [
                'did_write' => true,
                'row_id' => $asset->id,
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
            'asset_version' => self::TEMPLATE_VERSION,
            'template_version' => self::TEMPLATE_VERSION,
            'occupation_found' => false,
            'soc_crosswalk_valid' => false,
            'onet_crosswalk_valid' => false,
            'public_payload_forbidden_keys_found' => [],
            'would_write' => false,
            'did_write' => false,
            'row_id' => null,
            'decision' => 'fail',
        ];
    }

    private function requiredFile(): string
    {
        $path = trim((string) $this->option('file'));
        if ($path === '') {
            throw new \RuntimeException('--file is required.');
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--file does not exist: '.$path);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON file: '.$file);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     report: array<string, mixed>,
     *     errors: list<string>,
     *     occupation?: Occupation,
     *     component_order?: mixed,
     *     page?: mixed,
     *     seo?: mixed,
     *     sources?: mixed,
     *     structured_data?: mixed,
     *     implementation_contract?: mixed,
     *     not_included_in_public_display?: mixed
     * }
     */
    private function validatePayload(array $payload): array
    {
        $asset = $this->arrayValue($payload, 'asset');
        $errors = [];

        $slug = $this->stringValue($asset, 'slug', $this->stringValue($payload, 'slug'));
        $assetVersion = $this->stringValue($asset, 'asset_version', $this->stringValue($asset, 'template_version', $this->stringValue($payload, 'asset_version')));
        $templateVersion = $this->stringValue($asset, 'template_version', $this->stringValue($payload, 'template_version'));
        $assetType = $this->stringValue($asset, 'asset_type', $this->stringValue($payload, 'asset_type'));
        $assetRole = $this->stringValue($asset, 'asset_role', $this->stringValue($payload, 'asset_role'));
        $socCode = $this->stringValue($asset, 'soc_code', $this->stringValue($payload, 'soc_code'));
        $onetCode = $this->stringValue($asset, 'onet_code', $this->stringValue($payload, 'onet_code'));
        $status = $this->stringValue($asset, 'status', $this->stringValue($payload, 'status', self::STATUS));

        $this->expect($slug === self::TARGET_SLUG, 'asset.slug must be actors.', $errors);
        $this->expect($assetVersion === self::TEMPLATE_VERSION, 'asset_version must be v4.2.', $errors);
        $this->expect($templateVersion === self::TEMPLATE_VERSION, 'asset.template_version must be v4.2.', $errors);
        $this->expect($assetType === self::ASSET_TYPE, 'asset.asset_type must be career_job_public_display.', $errors);
        $this->expect($assetRole === self::ASSET_ROLE, 'asset.asset_role must be formal_pilot_master.', $errors);
        $this->expect($socCode === self::SOC_CODE, 'asset.soc_code must be 27-2011.', $errors);
        $this->expect($onetCode === self::ONET_CODE, 'asset.onet_code must be 27-2011.00.', $errors);
        $this->expect($status === self::STATUS, 'asset status must be ready_for_pilot when provided.', $errors);

        $componentOrder = $payload['component_order'] ?? null;
        $page = $payload['page'] ?? null;
        $seo = $payload['seo'] ?? null;
        $sources = $payload['sources'] ?? null;
        $structuredData = $payload['structured_data_from_visible_content'] ?? null;
        $implementationContract = $payload['implementation_contract'] ?? null;

        $this->expect(is_array($componentOrder) && $componentOrder !== [], 'component_order must be a non-empty array.', $errors);
        $this->expect(is_array($page) && is_array($page['zh'] ?? null), 'page.zh must exist.', $errors);
        $this->expect(is_array($page) && is_array($page['en'] ?? null), 'page.en must exist.', $errors);
        $this->expect(is_array($sources) && $sources !== [], 'sources must be a non-empty array.', $errors);
        $this->expect(is_array($structuredData) && $structuredData !== [], 'structured_data_from_visible_content must be a non-empty array.', $errors);
        $this->expect(is_array($implementationContract) && $implementationContract !== [], 'implementation_contract must be a non-empty array.', $errors);

        $occupation = Occupation::query()->where('canonical_slug', self::TARGET_SLUG)->first();
        $occupationFound = $occupation instanceof Occupation;
        $socCrosswalkValid = $occupationFound && $this->hasCrosswalk($occupation, self::SOC_CODE);
        $onetCrosswalkValid = $occupationFound && $this->hasCrosswalk($occupation, self::ONET_CODE);

        $this->expect($occupationFound, 'Occupation actors must exist.', $errors);
        $this->expect($socCrosswalkValid, 'Occupation actors must have SOC crosswalk 27-2011.', $errors);
        $this->expect($onetCrosswalkValid, 'Occupation actors must have O*NET crosswalk 27-2011.00.', $errors);

        $forbiddenKeys = $this->forbiddenPublicKeys([
            'component_order_json' => $componentOrder,
            'page_payload_json' => $page,
            'seo_payload_json' => $seo,
            'sources_json' => $sources,
            'structured_data_json' => $structuredData,
            'implementation_contract_json' => $implementationContract,
        ]);
        if ($forbiddenKeys !== []) {
            $errors[] = 'Forbidden public payload keys found: '.implode(', ', $forbiddenKeys).'.';
        }

        $plan = [
            'report' => [
                'target_slug' => $slug !== '' ? $slug : self::TARGET_SLUG,
                'asset_version' => $assetVersion !== '' ? $assetVersion : self::TEMPLATE_VERSION,
                'template_version' => $templateVersion !== '' ? $templateVersion : self::TEMPLATE_VERSION,
                'occupation_found' => $occupationFound,
                'soc_crosswalk_valid' => $socCrosswalkValid,
                'onet_crosswalk_valid' => $onetCrosswalkValid,
                'public_payload_forbidden_keys_found' => $forbiddenKeys,
            ],
            'errors' => $errors,
            'component_order' => $componentOrder,
            'page' => $page,
            'seo' => is_array($seo) ? $seo : null,
            'sources' => $sources,
            'structured_data' => $structuredData,
            'implementation_contract' => $implementationContract,
            'not_included_in_public_display' => $asset['not_included_in_public_display'] ?? null,
        ];

        if ($occupation instanceof Occupation) {
            $plan['occupation'] = $occupation;
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function arrayValue(array $array, string $key): array
    {
        return is_array($array[$key] ?? null) ? $array[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function stringValue(array $array, string $key, string $default = ''): string
    {
        return trim((string) ($array[$key] ?? $default));
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

    private function hasCrosswalk(Occupation $occupation, string $sourceCode): bool
    {
        return $occupation->crosswalks()
            ->where('source_code', $sourceCode)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payloads
     * @return list<string>
     */
    private function forbiddenPublicKeys(array $payloads): array
    {
        $found = [];
        foreach ($payloads as $payloadName => $payload) {
            $this->collectForbiddenKeys($payload, $payloadName, $found);
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $found
     */
    private function collectForbiddenKeys(mixed $value, string $path, array &$found): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = $path.'.'.$key;
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                $found[] = $childPath;
            }

            $this->collectForbiddenKeys($child, $childPath, $found);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function metadata(array $plan, string $file): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'source_file_basename' => basename($file),
            'source_file_sha256' => hash_file('sha256', $file) ?: null,
            'imported_at' => now()->toISOString(),
            'public_payload_forbidden_keys_found' => [],
            'not_included_in_public_display' => $plan['not_included_in_public_display'] ?? null,
        ];
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException) {
            return 'Database validation failed while reading occupation authority tables.';
        }

        return $throwable->getMessage();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        $report['decision'] = $success ? ($report['decision'] === 'fail' ? 'pass' : $report['decision']) : 'fail';

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('mode='.$report['mode']);
            $this->line('target_slug='.$report['target_slug']);
            $this->line('asset_version='.$report['asset_version']);
            $this->line('occupation_found='.($report['occupation_found'] ? 'true' : 'false'));
            $this->line('soc_crosswalk_valid='.($report['soc_crosswalk_valid'] ? 'true' : 'false'));
            $this->line('onet_crosswalk_valid='.($report['onet_crosswalk_valid'] ? 'true' : 'false'));
            $this->line('would_write='.($report['would_write'] ? 'true' : 'false'));
            $this->line('did_write='.($report['did_write'] ? 'true' : 'false'));
            $this->line('decision='.$report['decision']);
            if (isset($report['errors'])) {
                $this->line('errors='.json_encode($report['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
