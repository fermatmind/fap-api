<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Norms\BigFiveNormGroupResolver;
use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveNormResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_zh_cn_uses_zh_domain_and_global_facet_norms(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $this->assertIsArray($norms);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve((array) $norms, [
            'locale' => 'zh-CN',
            'country' => 'CN_MAINLAND',
            'gender' => 'ALL',
            'age_band' => 'all',
        ]);

        $this->assertSame('zh-CN_all', $resolved['domain_group_id']);
        $this->assertSame('global_all', $resolved['facet_group_id']);
        $this->assertSame('PROVISIONAL', $resolved['status']);
    }

    public function test_en_global_can_be_fully_calibrated(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $this->assertIsArray($norms);

        $resolver = app(BigFiveNormGroupResolver::class);
        $resolved = $resolver->resolve((array) $norms, [
            'locale' => 'en',
            'country' => 'GLOBAL',
            'gender' => 'ALL',
            'age_band' => 'all',
        ]);

        $this->assertSame('global_all', $resolved['domain_group_id']);
        $this->assertSame('global_all', $resolved['facet_group_id']);
        $this->assertSame('CALIBRATED', $resolved['status']);
    }
}
