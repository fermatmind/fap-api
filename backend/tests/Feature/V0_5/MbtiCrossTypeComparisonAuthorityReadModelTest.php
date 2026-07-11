<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\MbtiCrossTypeComparisonAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MbtiCrossTypeComparisonAuthorityReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_type_comparison_index_prefers_database_authority_rows(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->createAuthority([
            'slug' => 'istj-vs-isfj',
            'left_type_code' => 'ISTJ',
            'right_type_code' => 'ISFJ',
            'title' => 'ISTJ 和 ISFJ 的区别：规则执行与照护判断',
            'seo_title' => 'ISTJ 和 ISFJ 的区别 | FermatMind',
            'seo_description' => 'ISTJ 和 ISFJ 的区别测试描述',
            'summary' => 'ISTJ 更容易从规则和责任入口判断，ISFJ 更容易从照护和关系稳定入口判断。',
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons?locale=zh-CN');

        $response->assertOk()
            ->assertJsonPath('ok', true);

        $items = collect($response->json('cross_type_comparisons'));
        $authorityItem = $items->firstWhere('slug', 'istj-vs-isfj');

        self::assertIsArray($authorityItem);
        self::assertSame('ISTJ 和 ISFJ 的区别：规则执行与照护判断', $authorityItem['title']);
        self::assertSame('https://fermatmind.com/zh/personality/istj-vs-isfj', $authorityItem['public_url']);
        self::assertTrue((bool) $authorityItem['is_public']);
        self::assertFalse((bool) $authorityItem['is_indexable']);
        self::assertFalse((bool) $authorityItem['sitemap_eligible']);
        self::assertFalse((bool) $authorityItem['llms_eligible']);
        self::assertStringNotContainsString('/zh/result', (string) $response->getContent());
        self::assertStringNotContainsString('token=', (string) $response->getContent());
    }

    public function test_cross_type_comparison_detail_reads_database_authority_payload(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->createAuthority([
            'slug' => 'istj-vs-isfj',
            'left_type_code' => 'ISTJ',
            'right_type_code' => 'ISFJ',
            'title' => 'ISTJ 和 ISFJ 的区别：规则执行与照护判断',
            'seo_title' => 'ISTJ 和 ISFJ 的区别 | FermatMind',
            'seo_description' => 'ISTJ 和 ISFJ 的区别测试描述',
            'summary' => 'ISTJ 更容易从规则和责任入口判断，ISFJ 更容易从照护和关系稳定入口判断。',
            'source_package_id' => 'mbti-content15-top-blocker-batch',
            'source_sha256' => hash('sha256', 'istj-vs-isfj-authority-fixture'),
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/istj-vs-isfj?locale=zh-CN');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('comparison_public_projection_v1.comparison_slug', 'istj-vs-isfj')
            ->assertJsonPath('comparison_public_projection_v1.left_type', 'ISTJ')
            ->assertJsonPath('comparison_public_projection_v1.right_type', 'ISFJ')
            ->assertJsonPath('comparison_public_projection_v1.title', 'ISTJ 和 ISFJ 的区别：规则执行与照护判断')
            ->assertJsonPath('comparison_public_projection_v1.sections.0.id', 'direct_answer')
            ->assertJsonPath('comparison_public_projection_v1.sections.0.title', '最大区别')
            ->assertJsonPath('comparison_public_projection_v1.sections.1.rows.0.dimension', '判断入口')
            ->assertJsonPath('comparison_public_projection_v1.faq.0.question', 'ISTJ 和 ISFJ 最大区别是什么？')
            ->assertJsonPath('comparison_public_projection_v1.internal_links.0.href', '/zh/personality/istj-a')
            ->assertJsonPath('comparison_public_projection_v1.source_refs.0', 'mbti-content15-top-blocker-batch')
            ->assertJsonPath('comparison_public_projection_v1.is_indexable', false)
            ->assertJsonPath('comparison_public_projection_v1.sitemap_eligible', false)
            ->assertJsonPath('comparison_public_projection_v1.llms_eligible', false);

        $response->assertJsonPath('seo_meta.robots', 'noindex,follow')
            ->assertJsonPath('seo_meta.canonical_url', 'https://fermatmind.com/zh/personality/istj-vs-isfj')
            ->assertJsonPath('jsonld.@type', 'CollectionPage')
            ->assertJsonPath('jsonld.url', 'https://fermatmind.com/zh/personality/istj-vs-isfj')
            ->assertJsonPath('jsonld.mainEntity.@type', 'ItemList')
            ->assertJsonPath('jsonld.mainEntity.itemListElement.0.name', 'ISTJ')
            ->assertJsonPath('jsonld.mainEntity.itemListElement.1.name', 'ISFJ')
            ->assertJsonPath('jsonld.hasPart.@type', 'FAQPage')
            ->assertJsonPath('jsonld.hasPart.mainEntity.0.name', 'ISTJ 和 ISFJ 最大区别是什么？')
            ->assertJsonPath('jsonld.hasPart.mainEntity.0.acceptedAnswer.text', 'ISTJ 更偏规则执行，ISFJ 更偏照护判断。')
            ->assertJsonPath('seo_surface_v1.surface_type', 'mbti_personality_cross_type_comparison')
            ->assertJsonPath('seo_surface_v1.indexability_state', 'noindex')
            ->assertJsonPath('seo_surface_v1.sitemap_state', 'excluded')
            ->assertJsonPath('seo_surface_v1.llms_exposure_state', 'withhold');

        self::assertContains('CollectionPage', (array) $response->json('seo_surface_v1.structured_data_keys'));
        self::assertContains('ItemList', (array) $response->json('seo_surface_v1.structured_data_keys'));
        self::assertContains('BreadcrumbList', (array) $response->json('seo_surface_v1.structured_data_keys'));
        self::assertContains('FAQPage', (array) $response->json('seo_surface_v1.structured_data_keys'));
        self::assertSame(
            $response->json('comparison_public_projection_v1.faq'),
            collect((array) $response->json('jsonld.hasPart.mainEntity'))->map(static fn (array $item): array => [
                'question' => $item['name'],
                'answer' => $item['acceptedAnswer']['text'],
            ])->all()
        );

        self::assertGreaterThanOrEqual(5, count((array) $response->json('comparison_public_projection_v1.sections')));
        self::assertGreaterThanOrEqual(4, count((array) $response->json('comparison_public_projection_v1.faq')));
        self::assertGreaterThanOrEqual(3, count((array) $response->json('comparison_public_projection_v1.internal_links')));
        self::assertStringNotContainsString('/zh/orders', (string) $response->getContent());
        self::assertStringNotContainsString('order_no=', (string) $response->getContent());
    }

    public function test_cross_type_comparison_keeps_a_row_only_quick_judgment_table(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $payload = $this->contentPayload();
        unset($payload['sections'][1]['body']);

        $this->createAuthority([
            'slug' => 'intj-vs-intp',
            'left_type_code' => 'INTJ',
            'right_type_code' => 'INTP',
            'content_payload_json' => $payload,
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/intj-vs-intp?locale=zh-CN');

        $response->assertOk();

        $quickJudgmentTable = collect((array) $response->json('comparison_public_projection_v1.sections'))
            ->firstWhere('id', 'quick_judgment_table');

        self::assertIsArray($quickJudgmentTable);
        self::assertSame('判断入口', data_get($quickJudgmentTable, 'rows.0.dimension'));
        self::assertSame([], $quickJudgmentTable['body']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createAuthority(array $overrides = []): MbtiCrossTypeComparisonAuthority
    {
        /** @var MbtiCrossTypeComparisonAuthority */
        return MbtiCrossTypeComparisonAuthority::query()->create(array_merge([
            'org_id' => 0,
            'locale' => 'zh-CN',
            'slug' => 'intj-vs-intp',
            'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
            'left_type_code' => 'INTJ',
            'right_type_code' => 'INTP',
            'title' => 'INTJ 和 INTP 的区别',
            'seo_title' => 'INTJ 和 INTP 的区别 | FermatMind',
            'seo_description' => 'INTJ 和 INTP 的区别测试描述',
            'summary' => 'INTJ 和 INTP 的差异摘要。',
            'content_payload_json' => $this->contentPayload(),
            'claim_boundary' => '人格对比仅用于自我理解，不用于诊断、录用或关系预测。',
            'source_package_id' => 'mbti-content15-top-blocker-batch',
            'source_sha256' => hash('sha256', 'authority-fixture'),
            'authority_contract_version' => MbtiCrossTypeComparisonAuthority::AUTHORITY_CONTRACT_VERSION,
            'readmodel_contract_version' => MbtiCrossTypeComparisonAuthority::READMODEL_CONTRACT_VERSION,
            'review_status' => 'approved',
            'publish_status' => 'published',
            'indexability_status' => 'held_for_indexability_gate',
            'is_public' => true,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
            'published_at' => now()->subMinute(),
            'imported_at' => now()->subMinute(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function contentPayload(): array
    {
        return [
            'sections' => [
                [
                    'key' => 'direct_answer',
                    'title' => '最大区别',
                    'body' => 'ISTJ 更容易从规则和责任入口判断，ISFJ 更容易从照护和关系稳定入口判断。',
                ],
                [
                    'key' => 'quick_judgment_table',
                    'title' => '快速判断表',
                    'rows' => [
                        [
                            'dimension' => '判断入口',
                            'ISTJ' => '规则、流程、责任',
                            'ISFJ' => '照护、关系、稳定',
                        ],
                    ],
                    'body' => '用判断入口、压力反应和协作偏好快速区分。',
                ],
                [
                    'key' => 'easy_misread',
                    'title' => '为什么容易误判',
                    'body' => '两者都重视稳定和责任，所以不能只看是否守规矩。',
                ],
                [
                    'key' => 'real_scenario_differences',
                    'title' => '真实场景差异',
                    'body' => '任务变更时，ISTJ 更关注规则是否一致，ISFJ 更关注相关人的稳定感。',
                ],
                [
                    'key' => 'do_not_misjudge',
                    'title' => '不要这样误判',
                    'body' => '不要把细心直接等同于 ISFJ，也不要把守时直接等同于 ISTJ。',
                ],
            ],
            'faq' => [
                [
                    'question' => 'ISTJ 和 ISFJ 最大区别是什么？',
                    'answer' => 'ISTJ 更偏规则执行，ISFJ 更偏照护判断。',
                ],
                [
                    'question' => 'ISTJ 和 ISFJ 为什么容易混淆？',
                    'answer' => '两者都重视稳定、责任和可靠性。',
                ],
                [
                    'question' => '工作中怎么区分 ISTJ 和 ISFJ？',
                    'answer' => '看他们更先维护流程一致，还是更先维护关系稳定。',
                ],
                [
                    'question' => '这个对比能决定职业选择吗？',
                    'answer' => '不能，只能作为自我观察线索。',
                ],
            ],
            'internal_links' => [
                [
                    'href' => '/zh/personality/istj-a',
                    'anchor_text' => 'ISTJ-A',
                    'link_intent' => 'left_variant',
                ],
                [
                    'href' => '/zh/personality/isfj-a',
                    'anchor_text' => 'ISFJ-A',
                    'link_intent' => 'right_variant',
                ],
                [
                    'href' => '/zh/personality',
                    'anchor_text' => 'MBTI 人格',
                    'link_intent' => 'hub',
                ],
            ],
            'source_notes' => [
                'MBTI-CMS-26B database authority fixture.',
            ],
        ];
    }
}
