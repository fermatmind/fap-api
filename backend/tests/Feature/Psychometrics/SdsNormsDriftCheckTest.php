<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SdsNormsDriftCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_drift_check_passes_when_versions_are_identical(): void
    {
        $this->seedVersion('2026Q1_seed', 'zh-CN_all_18-60', 0.0);

        $this->artisan('norms:sds:drift-check --from=2026Q1_seed --to=2026Q1_seed --group_id=zh-CN_all_18-60')
            ->assertExitCode(0);
    }

    public function test_drift_check_fails_when_threshold_is_breached(): void
    {
        $this->seedVersion('2026Q1_seed', 'zh-CN_all_18-60', 0.0);
        $this->seedVersion('2026Q2_prod', 'zh-CN_all_18-60', 4.0);

        $this->artisan('norms:sds:drift-check --from=2026Q1_seed --to=2026Q2_prod --group_id=zh-CN_all_18-60 --threshold_mean=1 --threshold_sd=1')
            ->assertExitCode(1);
    }

    private function seedVersion(string $version, string $groupId, float $shift): void
    {
        $versionId = (string) Str::uuid();

        DB::table('scale_norms_versions')->insert([
            'id' => $versionId,
            'scale_code' => 'SDS_20',
            'norm_id' => $groupId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'version' => $version,
            'group_id' => $groupId,
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_SDS20_PROD_ROLLING',
            'source_type' => 'internal_prod',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => hash('sha256', $version.'|'.$groupId),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('scale_norm_stats')->insert([
            'id' => (string) Str::uuid(),
            'norm_version_id' => $versionId,
            'metric_level' => 'global',
            'metric_code' => 'INDEX_SCORE',
            'mean' => 50.0 + $shift,
            'sd' => 10.0 + $shift,
            'sample_n' => 2000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
