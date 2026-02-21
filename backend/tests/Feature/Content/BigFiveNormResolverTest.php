<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Norms\BigFiveNormGroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveNormResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_zh_cn_uses_db_first_norms_group(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve([], [
            'locale' => 'zh-CN',
            'country' => 'CN_MAINLAND',
            'gender' => 'ALL',
            'age_band' => '18-60',
        ]);

        $this->assertSame('zh-CN_prod_all_18-60', $resolved['domain_group_id']);
        $this->assertSame('zh-CN_prod_all_18-60', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
        $this->assertSame('db', $resolved['origin']);
    }

    public function test_en_global_can_be_fully_calibrated_from_db(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve([], [
            'locale' => 'en',
            'country' => 'GLOBAL',
            'gender' => 'ALL',
            'age_band' => '18-60',
        ]);

        $this->assertSame('en_johnson_all_18-60', $resolved['domain_group_id']);
        $this->assertSame('en_johnson_all_18-60', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
        $this->assertSame('db', $resolved['origin']);
    }
}
