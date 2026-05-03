<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CareerAlignActorsAuthorityOccupation extends Command
{
    private const COMMAND_NAME = 'career:align-actors-authority-occupation';

    private const TARGET_SLUG = 'actors';

    private const TEMPLATE_VERSION = 'v4.2';

    private const ASSET_TYPE = 'career_job_public_display';

    private const ASSET_ROLE = 'formal_pilot_master';

    private const SOC_CODE = '27-2011';

    private const ONET_CODE = '27-2011.00';

    private const FAMILY_SLUG = 'entertainment-and-sports';

    protected $signature = 'career:align-actors-authority-occupation
        {--file= : Absolute path to actors_v4_2_pilot_master.json}
        {--dry-run : Validate and report without writing}
        {--json : Emit machine-readable report}
        {--output= : Optional report output path}
        {--force : Required to write occupation and crosswalks}';

    protected $description = 'Validate and align the guarded Actors authority occupation and SOC/O*NET crosswalks.';

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

            $result = DB::transaction(function () use ($plan): array {
                $family = OccupationFamily::query()->updateOrCreate(
                    ['canonical_slug' => self::FAMILY_SLUG],
                    [
                        'title_en' => 'Entertainment and Sports',
                        'title_zh' => '娱乐与体育',
                    ],
                );

                $occupation = Occupation::query()->where('canonical_slug', self::TARGET_SLUG)->first();
                $attributes = [
                    'family_id' => $family->id,
                    'entity_level' => 'market_child',
                    'truth_market' => 'US',
                    'display_market' => 'CN',
                    'crosswalk_mode' => 'direct_match',
                    'canonical_slug' => self::TARGET_SLUG,
                    'canonical_title_en' => $plan['title_en'],
                    'canonical_title_zh' => $plan['title_zh'],
                    'search_h1_zh' => $plan['title_zh'].'职业诊断',
                    'structural_stability' => null,
                    'task_prototype_signature' => null,
                    'market_semantics_gap' => null,
                    'regulatory_divergence' => null,
                    'toolchain_divergence' => null,
                    'skill_gap_threshold' => null,
                    'trust_inheritance_scope' => [
                        'status' => 'actors_v4_2_pilot_authority_alignment',
                        'source_asset' => basename((string) $this->option('file')),
                    ],
                ];

                if ($occupation instanceof Occupation) {
                    $occupation->forceFill([
                        'canonical_title_en' => $attributes['canonical_title_en'],
                        'canonical_title_zh' => $attributes['canonical_title_zh'],
                        'search_h1_zh' => $attributes['search_h1_zh'],
                    ])->save();
                } else {
                    $occupation = Occupation::query()->create($attributes);
                }

                $socCrosswalk = $this->upsertCrosswalk($occupation, 'us_soc', self::SOC_CODE, 'Actors');
                $onetCrosswalk = $this->upsertCrosswalk($occupation, 'onet_soc_2019', self::ONET_CODE, 'Actors');

                return [
                    'occupation_id' => $occupation->id,
                    'soc_crosswalk_id' => $socCrosswalk->id,
                    'onet_crosswalk_id' => $onetCrosswalk->id,
                ];
            });

            return $this->finish(array_merge($report, $result, [
                'did_write' => true,
            ]), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
            ]), false);
        }
    }

    private function upsertCrosswalk(Occupation $occupation, string $sourceSystem, string $sourceCode, string $sourceTitle): OccupationCrosswalk
    {
        return OccupationCrosswalk::query()->updateOrCreate(
            [
                'occupation_id' => $occupation->id,
                'source_system' => $sourceSystem,
            ],
            [
                'source_code' => $sourceCode,
                'source_title' => $sourceTitle,
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
                'notes' => 'actors_v4_2_pilot_authority_alignment',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'mode' => 'dry_run',
            'target_slug' => self::TARGET_SLUG,
            'soc_code' => self::SOC_CODE,
            'onet_code' => self::ONET_CODE,
            'occupation_would_exist' => false,
            'soc_crosswalk_would_exist' => false,
            'onet_crosswalk_would_exist' => false,
            'would_write' => false,
            'did_write' => false,
            'occupation_id' => null,
            'soc_crosswalk_id' => null,
            'onet_crosswalk_id' => null,
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
     *     title_en: string,
     *     title_zh: string
     * }
     */
    private function validatePayload(array $payload): array
    {
        $asset = $this->arrayValue($payload, 'asset');
        $errors = [];

        $slug = $this->stringValue($asset, 'slug', $this->stringValue($payload, 'slug'));
        $templateVersion = $this->stringValue($asset, 'template_version', $this->stringValue($payload, 'template_version'));
        $assetType = $this->stringValue($asset, 'asset_type', $this->stringValue($payload, 'asset_type'));
        $assetRole = $this->stringValue($asset, 'asset_role', $this->stringValue($payload, 'asset_role'));
        $socCode = $this->stringValue($asset, 'soc_code', $this->stringValue($payload, 'soc_code'));
        $onetCode = $this->stringValue($asset, 'onet_code', $this->stringValue($payload, 'onet_code'));
        $titleZh = $this->localizedTitle($payload, 'zh');
        $titleEn = $this->localizedTitle($payload, 'en');

        $this->expect($slug === self::TARGET_SLUG, 'asset.slug must be actors.', $errors);
        $this->expect($socCode === self::SOC_CODE, 'asset.soc_code must be 27-2011.', $errors);
        $this->expect($onetCode === self::ONET_CODE, 'asset.onet_code must be 27-2011.00.', $errors);
        $this->expect($templateVersion === self::TEMPLATE_VERSION, 'asset.template_version must be v4.2.', $errors);
        $this->expect($assetType === self::ASSET_TYPE, 'asset.asset_type must be career_job_public_display.', $errors);
        $this->expect($assetRole === self::ASSET_ROLE, 'asset.asset_role must be formal_pilot_master.', $errors);
        $this->expect($titleZh !== '', 'seo.zh.h1 or page.zh.hero.h1 must exist.', $errors);
        $this->expect($titleEn !== '', 'seo.en.h1 or page.en.hero.h1 must exist.', $errors);

        $occupation = Occupation::query()->where('canonical_slug', self::TARGET_SLUG)->first();
        $socCrosswalk = $occupation instanceof Occupation
            ? $this->hasCrosswalk($occupation, 'us_soc', self::SOC_CODE)
            : false;
        $onetCrosswalk = $occupation instanceof Occupation
            ? $this->hasCrosswalk($occupation, 'onet_soc_2019', self::ONET_CODE)
            : false;

        return [
            'report' => [
                'target_slug' => $slug !== '' ? $slug : self::TARGET_SLUG,
                'soc_code' => $socCode !== '' ? $socCode : self::SOC_CODE,
                'onet_code' => $onetCode !== '' ? $onetCode : self::ONET_CODE,
                'occupation_would_exist' => true,
                'soc_crosswalk_would_exist' => true,
                'onet_crosswalk_would_exist' => true,
                'occupation_id' => $occupation?->id,
                'soc_crosswalk_id' => $socCrosswalk instanceof OccupationCrosswalk ? $socCrosswalk->id : null,
                'onet_crosswalk_id' => $onetCrosswalk instanceof OccupationCrosswalk ? $onetCrosswalk->id : null,
            ],
            'errors' => $errors,
            'title_en' => $titleEn !== '' ? $titleEn : 'Actors',
            'title_zh' => $titleZh !== '' ? $titleZh : '演员',
        ];
    }

    private function localizedTitle(array $payload, string $locale): string
    {
        $seo = is_array($payload['seo'][$locale] ?? null) ? $payload['seo'][$locale] : [];
        $page = is_array($payload['page'][$locale] ?? null) ? $payload['page'][$locale] : [];
        $hero = is_array($page['hero'] ?? null) ? $page['hero'] : [];

        return $this->stringValue($seo, 'h1', $this->stringValue($hero, 'h1'));
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

    private function hasCrosswalk(Occupation $occupation, string $sourceSystem, string $sourceCode): ?OccupationCrosswalk
    {
        $crosswalk = $occupation->crosswalks()
            ->where('source_system', $sourceSystem)
            ->where('source_code', $sourceCode)
            ->first();

        return $crosswalk instanceof OccupationCrosswalk ? $crosswalk : null;
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException) {
            return 'Database validation failed while reading or aligning occupation authority tables.';
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
            $this->line('soc_code='.$report['soc_code']);
            $this->line('onet_code='.$report['onet_code']);
            $this->line('occupation_would_exist='.($report['occupation_would_exist'] ? 'true' : 'false'));
            $this->line('soc_crosswalk_would_exist='.($report['soc_crosswalk_would_exist'] ? 'true' : 'false'));
            $this->line('onet_crosswalk_would_exist='.($report['onet_crosswalk_would_exist'] ? 'true' : 'false'));
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
