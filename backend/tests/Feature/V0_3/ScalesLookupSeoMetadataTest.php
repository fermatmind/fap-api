<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScalesLookupSeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_mbti_zh_lookup_uses_conservative_free_test_metadata(): void
    {
        $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh')
            ->assertOk()
            ->assertJsonPath('seo_title', '免费 MBTI 测试：16 型人格完整结果')
            ->assertJsonPath(
                'seo_description',
                '免费完成 MBTI 人格测试，查看 16 型人格结果、偏好维度与后续探索建议。结果用于自我了解，不作诊断或职业保证。'
            );
    }
}
