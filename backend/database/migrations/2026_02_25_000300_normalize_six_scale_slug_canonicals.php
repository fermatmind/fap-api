<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string,array{canonical_slug:string,aliases:list<string>,driver_type:string,assessment_driver:string,default_pack_id:string,default_dir_version:string}>
     */
    private const SCALE_CANONICALS = [
        'MBTI' => [
            'canonical_slug' => 'mbti-personality-test-16-personality-types',
            'aliases' => [
                'mbti-test',
                'mbti-personality-test',
                'mbti',
                'personality-mbti-test',
            ],
            'driver_type' => 'mbti',
            'assessment_driver' => 'generic_scoring',
            'default_pack_id' => '',
            'default_dir_version' => '',
        ],
        'BIG5_OCEAN' => [
            'canonical_slug' => 'big-five-personality-test-ocean-model',
            'aliases' => [
                'big5-ocean',
                'big5-ocean-test',
                'big5',
                'big5-personality-test',
                'big-five-personality-test',
            ],
            'driver_type' => 'big5_ocean',
            'assessment_driver' => 'big5_ocean',
            'default_pack_id' => 'BIG5_OCEAN',
            'default_dir_version' => 'v1',
        ],
        'CLINICAL_COMBO_68' => [
            'canonical_slug' => 'clinical-depression-anxiety-assessment-professional-edition',
            'aliases' => [
                'clinical-combo-68',
                'depression-anxiety-combo',
            ],
            'driver_type' => 'clinical_combo_68',
            'assessment_driver' => 'clinical_combo_68',
            'default_pack_id' => 'CLINICAL_COMBO_68',
            'default_dir_version' => 'v1',
        ],
        'SDS_20' => [
            'canonical_slug' => 'depression-screening-test-standard-edition',
            'aliases' => [
                'sds-20',
                'zung-self-rating-depression-scale',
            ],
            'driver_type' => 'sds_20',
            'assessment_driver' => 'sds_20',
            'default_pack_id' => 'SDS_20',
            'default_dir_version' => 'v1',
        ],
        'IQ_RAVEN' => [
            'canonical_slug' => 'iq-test-intelligence-quotient-assessment',
            'aliases' => [
                'iq-test',
                'iq_raven',
                'raven-iq-test',
                'raven-matrices',
            ],
            'driver_type' => 'iq_raven',
            'assessment_driver' => 'iq_raven',
            'default_pack_id' => '',
            'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
        ],
        'EQ_60' => [
            'canonical_slug' => 'eq-test-emotional-intelligence-assessment',
            'aliases' => [
                'eq-test',
                'emotional-intelligence-test',
            ],
            'driver_type' => 'eq_60',
            'assessment_driver' => 'eq_60',
            'default_pack_id' => 'EQ_60',
            'default_dir_version' => 'v1',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('scales_registry') || ! Schema::hasTable('scale_slugs')) {
            return;
        }

        $defaultPackId = trim((string) config('content_packs.default_pack_id', ''));
        $demoPackId = trim((string) config('content_packs.demo_pack_id', ''));
        if ($demoPackId === '') {
            $demoPackId = $defaultPackId;
        }
        $defaultRegion = trim((string) config('content_packs.default_region', 'CN_MAINLAND'));
        $defaultLocale = trim((string) config('content_packs.default_locale', 'zh-CN'));
        $defaultDirVersion = trim((string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'));

        $definitions = self::SCALE_CANONICALS;
        $definitions['MBTI']['default_pack_id'] = $defaultPackId;
        $definitions['MBTI']['default_dir_version'] = $defaultDirVersion;
        $definitions['IQ_RAVEN']['default_pack_id'] = $demoPackId;

        foreach ($definitions as $scaleCode => $definition) {
            $canonicalSlug = (string) $definition['canonical_slug'];
            $primarySlugConflict = DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('primary_slug', $canonicalSlug)
                ->where('code', '!=', $scaleCode)
                ->exists();

            $slugRowConflict = DB::table('scale_slugs')
                ->where('org_id', 0)
                ->where('slug', $canonicalSlug)
                ->where('scale_code', '!=', $scaleCode)
                ->exists();

            if ($primarySlugConflict || $slugRowConflict) {
                throw new RuntimeException("canonical slug conflict: {$canonicalSlug}");
            }
        }

        DB::transaction(function () use ($definitions, $defaultRegion, $defaultLocale): void {
            $now = now();

            foreach ($definitions as $scaleCode => $definition) {
                $canonicalSlug = (string) $definition['canonical_slug'];
                $row = DB::table('scales_registry')
                    ->where('code', $scaleCode)
                    ->first();

                $existingSlugs = $this->decodeSlugs($row?->slugs_json ?? null);
                $existingPrimarySlug = trim((string) ($row?->primary_slug ?? ''));
                $normalizedSlugs = $this->normalizeSlugs(array_merge(
                    [$canonicalSlug],
                    (array) $definition['aliases'],
                    $existingPrimarySlug !== '' ? [$existingPrimarySlug] : [],
                    $existingSlugs
                ));

                if ($row === null) {
                    $insert = [
                        'code' => $scaleCode,
                        'org_id' => 0,
                        'primary_slug' => $canonicalSlug,
                        'slugs_json' => json_encode($normalizedSlugs, JSON_UNESCAPED_UNICODE),
                        'driver_type' => (string) $definition['driver_type'],
                        'default_pack_id' => (string) $definition['default_pack_id'],
                        'default_region' => $defaultRegion,
                        'default_locale' => $defaultLocale,
                        'default_dir_version' => (string) $definition['default_dir_version'],
                        'is_public' => 1,
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (Schema::hasColumn('scales_registry', 'assessment_driver')) {
                        $insert['assessment_driver'] = (string) $definition['assessment_driver'];
                    }
                    if (Schema::hasColumn('scales_registry', 'capabilities_json')) {
                        $insert['capabilities_json'] = json_encode([
                            'enabled_in_prod' => true,
                            'rollout_ratio' => 1.0,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    DB::table('scales_registry')->insert($insert);
                } else {
                    DB::table('scales_registry')
                        ->where('code', $scaleCode)
                        ->update([
                            'primary_slug' => $canonicalSlug,
                            'slugs_json' => json_encode($normalizedSlugs, JSON_UNESCAPED_UNICODE),
                            'updated_at' => $now,
                        ]);
                }

                DB::table('scale_slugs')
                    ->where('org_id', 0)
                    ->where('scale_code', $scaleCode)
                    ->delete();

                $slugRows = [];
                foreach ($normalizedSlugs as $slug) {
                    $slugRows[] = [
                        'org_id' => 0,
                        'slug' => $slug,
                        'scale_code' => $scaleCode,
                        'is_primary' => $slug === $canonicalSlug,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($slugRows !== []) {
                    DB::table('scale_slugs')->insert($slugRows);
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-only migration: keep normalized canonical slugs.
    }

    /**
     * @return list<string>
     */
    private function decodeSlugs(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map(static fn (mixed $slug): string => (string) $slug, $raw);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_map(static fn (mixed $slug): string => (string) $slug, $decoded);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function normalizeSlugs(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $normalized = strtolower(trim($slug));
            if ($normalized === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9-]{1,127}$/', $normalized)) {
                continue;
            }
            if (! isset($out[$normalized])) {
                $out[$normalized] = true;
            }
        }

        return array_keys($out);
    }
};
