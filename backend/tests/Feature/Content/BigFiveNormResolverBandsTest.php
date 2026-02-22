<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Norms\BigFiveNormGroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveNormResolverBandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_zh_cn_female_20_hits_gender_age_band_group(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve([], [
            'locale' => 'zh-CN',
            'country' => 'CN_MAINLAND',
            'gender' => 'F',
            'age' => 20,
        ]);

        $this->assertSame('zh-CN_prod_f_18-29', $resolved['domain_group_id']);
        $this->assertSame('zh-CN_prod_f_18-29', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
    }

    public function test_zh_cn_unknown_gender_20_falls_back_to_all_18_60(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve([], [
            'locale' => 'zh-CN',
            'country' => 'CN_MAINLAND',
            'gender' => 'UNKNOWN',
            'age' => 20,
        ]);

        $this->assertSame('zh-CN_prod_all_18-60', $resolved['domain_group_id']);
        $this->assertSame('zh-CN_prod_all_18-60', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
    }

    public function test_en_male_25_hits_gender_age_band_group(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve([], [
            'locale' => 'en',
            'country' => 'GLOBAL',
            'gender' => 'M',
            'age' => 25,
        ]);

        $this->assertSame('en_johnson_m_18-29', $resolved['domain_group_id']);
        $this->assertSame('en_johnson_m_18-29', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
    }
}

