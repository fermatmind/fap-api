<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5NormsDriftCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_drift_check_passes_when_versions_are_identical(): void
    {
        $this->seedVersion('2026Q1_v1', 'zh-CN_prod_all_18-60', 0.0);

        $this->artisan('norms:big5:drift-check --from=2026Q1_v1 --to=2026Q1_v1 --group_id=zh-CN_prod_all_18-60')
            ->assertExitCode(0);
    }

    public function test_drift_check_fails_when_threshold_is_breached(): void
    {
        $this->seedVersion('2026Q1_v1', 'zh-CN_prod_all_18-60', 0.0);
        $this->seedVersion('2026Q2_v1', 'zh-CN_prod_all_18-60', 0.3);

        $this->artisan('norms:big5:drift-check --from=2026Q1_v1 --to=2026Q2_v1 --group_id=zh-CN_prod_all_18-60 --threshold_mean=0.05 --threshold_sd=0.05')
            ->assertExitCode(1);
    }

    private function seedVersion(string $version, string $groupId, float $meanShift): void
    {
        $versionId = (string) Str::uuid();
        DB::table('scale_norms_versions')->insert([
            'id' => $versionId,
            'scale_code' => 'BIG5_OCEAN',
            'norm_id' => $groupId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'version' => $version,
            'group_id' => $groupId,
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_PROD_ROLLING',
            'source_type' => 'internal_prod',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => hash('sha256', $version.'|'.$groupId),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domains = ['O', 'C', 'E', 'A', 'N'];
        $facets = [
            'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
            'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
            'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
            'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
            'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
        ];

        $rows = [];
        foreach ($domains as $domain) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'domain',
                'metric_code' => $domain,
                'mean' => 3.0 + $meanShift,
                'sd' => 0.6 + $meanShift,
                'sample_n' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach ($facets as $facet) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'facet',
                'metric_code' => $facet,
                'mean' => 3.0 + $meanShift,
                'sd' => 0.6 + $meanShift,
                'sample_n' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('scale_norm_stats')->insert($rows);
    }
}

