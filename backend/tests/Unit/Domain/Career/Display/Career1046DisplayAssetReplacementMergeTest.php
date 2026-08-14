<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Career1046DisplayAssetReplacementMergeTest extends TestCase
{
    public function test_it_preserves_existing_authority_and_only_adds_the_two_localized_blocks(): void
    {
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $merge = new \ReflectionMethod($service, 'mergeLocalizedBlocks');
        $before = [
            'page' => [
                'en' => [
                    'hero' => ['h1' => 'Example'],
                    'definition_block' => ['body' => 'Definition'],
                    'responsibilities_block' => ['items' => ['Responsibility']],
                    'market_signal_card' => ['summary' => 'Snapshot'],
                    'faq_block' => ['items' => [['question' => 'Q', 'answer' => 'A']]],
                    'sentinel' => 'en',
                ],
                'zh' => [
                    'hero' => ['h1' => '示例'],
                    'definition_block' => ['body' => '定义'],
                    'responsibilities_block' => ['items' => ['职责']],
                    'market_signal_card' => ['summary' => '快照'],
                    'faq_block' => ['items' => [['question' => '问', 'answer' => '答']]],
                    'sentinel' => 'zh',
                ],
            ],
            'unrelated' => ['preserved' => true],
        ];
        $rows = [
            'en' => ['blocks' => [
                'career_ai_description_block' => ['component' => 'CareerAiDescriptionBlock', 'heading' => 'AI Career Analysis', 'body' => ['Body']],
                'career_path_block' => ['component' => 'CareerPathBlock', 'heading' => 'Career Path', 'rows' => [['Entry', '0-2', 'Skill', 'Range']]],
            ]],
            'zh-CN' => ['blocks' => [
                'career_ai_description_block' => ['component' => 'CareerAiDescriptionBlock', 'heading' => 'AI 职业解读', 'body' => ['正文']],
                'career_path_block' => ['component' => 'CareerPathBlock', 'heading' => '职业发展路径', 'rows' => [['入门', '0-2', '技能', '范围']]],
            ]],
        ];

        $after = $merge->invoke($service, $before, $rows);

        self::assertSame($before['unrelated'], $after['unrelated']);
        self::assertSame($before['page']['en']['hero'], $after['page']['en']['hero']);
        self::assertSame($before['page']['en']['definition_block'], $after['page']['en']['definition_block']);
        self::assertSame($before['page']['zh']['responsibilities_block'], $after['page']['zh']['responsibilities_block']);
        self::assertSame($before['page']['zh']['market_signal_card'], $after['page']['zh']['market_signal_card']);
        self::assertSame($before['page']['zh']['faq_block'], $after['page']['zh']['faq_block']);
        self::assertSame('AI Career Analysis', $after['page']['en']['career_ai_description_block']['heading']);
        self::assertSame('职业发展路径', $after['page']['zh']['career_path_block']['heading']);
        self::assertStringNotContainsString(
            'display_asset_backed_directory_draft_shell',
            json_encode($after, JSON_THROW_ON_ERROR),
        );
    }
}
