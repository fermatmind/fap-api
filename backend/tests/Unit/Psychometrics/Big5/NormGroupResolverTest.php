<?php

declare(strict_types=1);

namespace Tests\Unit\Psychometrics\Big5;

use App\Services\Psychometrics\Big5\NormGroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NormGroupResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_zh_cn_resolves_from_db_chain(): void
    {
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(NormGroupResolver::class);
        $resolved = $resolver->resolve('BIG5_OCEAN', [
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'gender' => 'ALL',
            'age_band' => '18-60',
        ]);

        $this->assertSame('zh-CN_xu_all_18-60', $resolved['group_id']);
        $this->assertSame('PROVISIONAL', $resolved['status']);
        $this->assertSame('db', $resolved['origin']);
    }

    public function test_en_resolves_to_calibrated_group(): void
    {
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(NormGroupResolver::class);
        $resolved = $resolver->resolve('BIG5_OCEAN', [
            'locale' => 'en',
            'region' => 'GLOBAL',
            'gender' => 'ALL',
            'age_band' => '18-60',
        ]);

        $this->assertSame('en_johnson_all_18-60', $resolved['group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
        $this->assertSame('db', $resolved['origin']);
    }

    public function test_incomplete_db_group_is_skipped(): void
    {
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $versionId = (string) Str::uuid();
        DB::table('scale_norms_versions')->insert([
            'id' => $versionId,
            'scale_code' => 'BIG5_OCEAN',
            'norm_id' => 'en_prod_all_18-60',
            'region' => 'GLOBAL',
            'locale' => 'en',
            'version' => '2026Q2_prod_bad',
            'group_id' => 'en_prod_all_18-60',
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_PROD_ROLLING',
            'source_type' => 'internal_prod',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => 'bad',
            'meta_json' => json_encode(['test' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only 5 rows, intentionally incomplete.
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            DB::table('scale_norm_stats')->insert([
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'domain',
                'metric_code' => $domain,
                'mean' => 3.0,
                'sd' => 0.6,
                'sample_n' => 10000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resolver = app(NormGroupResolver::class);
        $resolved = $resolver->resolve('BIG5_OCEAN', [
            'locale' => 'en',
            'region' => 'GLOBAL',
            'gender' => 'ALL',
            'age_band' => '18-60',
        ]);

        // Bad prod group should be skipped and fallback to bootstrap group.
        $this->assertSame('en_johnson_all_18-60', $resolved['group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
    }
}
