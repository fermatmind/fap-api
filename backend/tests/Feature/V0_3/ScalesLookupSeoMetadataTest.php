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

    public function test_mbti_zh_lookup_uses_free_test_visible_faq_authority(): void
    {
        $response = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh')
            ->assertOk();

        $faq = $response->json('content_i18n_json.zh.faq');

        $this->assertIsArray($faq);
        $this->assertCount(4, $faq);
        $this->assertSame('费马的 MBTI免费测试会收费吗？', $faq[0]['q'] ?? null);
        $this->assertSame('这份 16型人格完整结果/报告包含哪些内容？', $faq[1]['q'] ?? null);
        $this->assertSame('MBTI免费测试结果可以作为职业或心理诊断吗？', $faq[2]['q'] ?? null);
        $this->assertStringContainsString('页面不会把基础结果解读作为付费前置条件', (string) ($faq[0]['a'] ?? ''));
        $this->assertStringContainsString('不等同于诊断或职业保证', (string) ($faq[1]['a'] ?? ''));
        $this->assertStringContainsString('不能替代心理诊断、医疗建议或正式职业评估', (string) ($faq[2]['a'] ?? ''));

        $serializedFaq = json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('真全免', $serializedFaq);
        $this->assertStringNotContainsString('无付费墙', $serializedFaq);
        $this->assertStringNotContainsString('拒绝强制收费', $serializedFaq);
        $this->assertStringNotContainsString('2026专业版', $serializedFaq);
    }
}
