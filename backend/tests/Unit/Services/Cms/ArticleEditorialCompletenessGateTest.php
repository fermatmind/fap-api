<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\ArticleEditorialCompletenessGate;
use PHPUnit\Framework\TestCase;

final class ArticleEditorialCompletenessGateTest extends TestCase
{
    private ArticleEditorialCompletenessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new ArticleEditorialCompletenessGate;
    }

    public function test_accepts_chinese_body_at_exact_minimum_without_forbidden_markers(): void
    {
        $result = $this->gate->inspect('zh-CN', str_repeat('人', 2000), [
            'title' => '完整文章',
            'excerpt' => '结构化摘要',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(2000, $result['actual_han_characters']);
        $this->assertSame(2000, $result['minimum_han_characters']);
        $this->assertSame([], $result['matched_forbidden_markers']);
        $this->assertSame([], $result['issues']);
    }

    public function test_rejects_short_chinese_body_after_visible_text_normalization(): void
    {
        $body = "# 标题\n\n".str_repeat('人', 1990)."\n\n![图](https://assets.fermatmind.com/cover.jpg)";
        $result = $this->gate->inspect('zh-CN', $body, ['title' => '标题']);

        $this->assertFalse($result['ok']);
        $this->assertSame(1993, $result['actual_han_characters']);
        $this->assertSame(
            ['body_han_characters_below_minimum'],
            array_column($result['issues'], 'code'),
        );
    }

    public function test_rejects_forbidden_markers_across_reader_facing_fields(): void
    {
        $result = $this->gate->inspect('zh-CN', str_repeat('人', 2100), [
            'excerpt' => 'Big Five Authority V2 draft candidate pending manual review.',
            'seo_description' => 'This remains pending manual review.',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['forbidden_draft_marker'], array_column($result['issues'], 'code'));
        $this->assertSame(
            [
                'Big Five Authority V2 draft candidate pending manual review',
                'draft candidate',
                'pending manual review',
            ],
            array_column($result['matched_forbidden_markers'], 'marker'),
        );
    }

    public function test_non_chinese_content_uses_marker_only_profile(): void
    {
        $result = $this->gate->inspect('en', 'Short but reviewed.', ['title' => 'Reviewed article']);

        $this->assertTrue($result['ok']);
        $this->assertSame('marker_only_v1', $result['profile']);
        $this->assertNull($result['minimum_han_characters']);
    }
}
