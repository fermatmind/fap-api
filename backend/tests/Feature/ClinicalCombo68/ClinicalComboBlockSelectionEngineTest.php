<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Report\ClinicalCombo\ClinicalComboBlockSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboBlockSelectionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_selector_applies_alias_and_priority_with_exclusive_group(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);

        /** @var ClinicalComboPackLoader $loader */
        $loader = app(ClinicalComboPackLoader::class);
        /** @var ClinicalComboBlockSelector $selector */
        $selector = app(ClinicalComboBlockSelector::class);

        $policy = $loader->loadPolicy('v1');
        $blocks = $loader->loadBlocks('zh-CN', 'v1');

        $context = [
            'quality' => [
                'crisis_alert' => false,
                'flags' => [],
            ],
            'scores' => [
                'depression' => ['level' => 'severe', 'flags' => ['masked_depression']],
                'anxiety' => ['level' => 'mild'],
                'ocd' => ['level' => 'mild'],
                'stress' => ['level' => 'high'],
                'resilience' => ['level' => 'strong'],
                'perfectionism' => ['level' => 'extreme'],
            ],
            'facts' => [
                'function_impairment_level' => 'moderate',
            ],
            'report_tags' => [
                'trait:mistake_concern_dominant',
            ],
        ];

        $actionSelected = $selector->select(
            allBlocks: $blocks,
            sectionKey: 'action_plan',
            locale: 'zh-CN',
            allowedAccessLevels: ['paid'],
            minBlocks: 0,
            maxBlocks: 10,
            context: $context,
            policy: $policy,
        );

        $actionIds = array_map(
            static fn (array $row): string => (string) ($row['block_id'] ?? ''),
            array_filter($actionSelected, 'is_array')
        );

        $this->assertContains('paid_action_perfectionism_14d_zh', $actionIds, 'rigid alias should match perfectionism.level=extreme');

        $custom = [
            [
                'block_id' => 'low_priority_dep',
                'section' => 'symptoms_depression',
                'locale' => 'zh-CN',
                'access_level' => 'free',
                'priority' => 10,
                'exclusive_group' => 'dep_level',
                'conditions' => [
                    ['path' => 'scores.depression.level', 'op' => 'eq', 'value' => 'severe'],
                ],
            ],
            [
                'block_id' => 'high_priority_dep',
                'section' => 'symptoms_depression',
                'locale' => 'zh-CN',
                'access_level' => 'free',
                'priority' => 30,
                'exclusive_group' => 'dep_level',
                'conditions' => [
                    ['path' => 'scores.depression.level', 'op' => 'eq', 'value' => 'severe'],
                ],
            ],
            [
                'block_id' => 'masked_hint',
                'section' => 'symptoms_depression',
                'locale' => 'zh-CN',
                'access_level' => 'free',
                'priority' => 20,
                'exclusive_group' => 'dep_masked_hint',
                'conditions' => [
                    ['path' => 'scores.depression.flags', 'op' => 'contains', 'value' => 'masked_depression'],
                ],
            ],
        ];

        $depSelected = $selector->select(
            allBlocks: $custom,
            sectionKey: 'symptoms_depression',
            locale: 'zh-CN',
            allowedAccessLevels: ['free'],
            minBlocks: 1,
            maxBlocks: 10,
            context: $context,
            policy: $policy,
        );

        $depIds = array_map(
            static fn (array $row): string => (string) ($row['block_id'] ?? ''),
            array_filter($depSelected, 'is_array')
        );

        $this->assertContains('high_priority_dep', $depIds);
        $this->assertNotContains('low_priority_dep', $depIds);
        $this->assertContains('masked_hint', $depIds);
    }
}
