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
        $this->assertCount(8, $faq);

        $questions = array_map(static fn (array $item): string => (string) ($item['q'] ?? ''), $faq);
        $this->assertSame([
            'MBTI 测试免费吗？',
            'MBTI 完整结果能看到什么？',
            'MBTI 测试一般多久？',
            'MBTI 能决定职业吗？',
            'MBTI 是心理诊断吗？',
            '16 型人格结果会变吗？',
            'MBTI 和大五人格有什么区别？',
            '做完 MBTI 后下一步看什么？',
        ], $questions);

        $this->assertStringContainsString('不会把基础结果说明作为付费前置条件', (string) ($faq[0]['a'] ?? ''));
        $this->assertStringContainsString('16 型人格类型', (string) ($faq[1]['a'] ?? ''));
        $this->assertStringContainsString('不同版本题量不同', (string) ($faq[2]['a'] ?? ''));
        $this->assertStringContainsString('不能单独决定职业选择、录用结果或发展上限', (string) ($faq[3]['a'] ?? ''));
        $this->assertStringContainsString('不能替代心理诊断、治疗建议或医疗专业意见', (string) ($faq[4]['a'] ?? ''));
        $this->assertStringContainsString('不是固定身份标签', (string) ($faq[5]['a'] ?? ''));
        $this->assertStringContainsString('大五人格更像连续维度评分', (string) ($faq[6]['a'] ?? ''));
        $this->assertStringContainsString('职业探索场景做记录', (string) ($faq[7]['a'] ?? ''));

        $serializedFaq = json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('真全免', $serializedFaq);
        $this->assertStringNotContainsString('无付费墙', $serializedFaq);
        $this->assertStringNotContainsString('拒绝强制收费', $serializedFaq);
        $this->assertStringNotContainsString('2026专业版', $serializedFaq);
        $this->assertStringNotContainsString('官方 MBTI', $serializedFaq);
        $this->assertStringNotContainsString('招聘筛选', $serializedFaq);
        $this->assertStringNotContainsString('职业保证', $serializedFaq);
    }
}
