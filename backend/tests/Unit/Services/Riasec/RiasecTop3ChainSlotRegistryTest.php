<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class RiasecTop3ChainSlotRegistryTest extends TestCase
{
    private const TARGET_ORDERED_MATRIX = [
        'R_I_A' => ['RIA', 'RAI', 'IRA'],
        'R_I_S' => ['RIS', 'RSI', 'IRS'],
        'R_I_E' => ['RIE', 'REI', 'IRE'],
        'R_I_C' => ['RIC', 'RCI', 'IRC'],
        'R_A_S' => ['RAS', 'RSA', 'ARS'],
        'R_A_E' => ['RAE', 'REA', 'ARE'],
        'R_A_C' => ['RAC', 'RCA', 'ARC'],
        'R_S_E' => ['RSE', 'RES', 'SRE'],
        'R_S_C' => ['RSC', 'RCS', 'SRC'],
        'R_E_C' => ['REC', 'RCE', 'ERC'],
        'I_A_S' => ['IAS', 'ISA', 'AIS'],
        'I_A_E' => ['IAE', 'IEA', 'AIE'],
        'I_A_C' => ['IAC', 'ICA', 'AIC'],
        'I_S_E' => ['ISE', 'IES', 'SIE'],
        'I_S_C' => ['ISC', 'ICS', 'SIC'],
        'I_E_C' => ['IEC', 'ICE', 'EIC'],
        'A_S_E' => ['ASE', 'AES', 'SAE'],
        'A_S_C' => ['ASC', 'ACS', 'SAC'],
        'A_E_C' => ['AEC', 'ACE', 'EAC'],
        'S_E_C' => ['SEC', 'SCE', 'ESC'],
    ];

    private const DIMENSION_LABELS = [
        'R' => '实作型',
        'I' => '研究型',
        'A' => '艺术型',
        'S' => '社会型',
        'E' => '企业型',
        'C' => '常规型',
    ];

    public function test_top3_chain_contract_covers_all_unordered_combos(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->top3ChainSlots();

        $this->assertSame(RiasecDeepCopySlotRegistry::TOP3_COMBOS, array_keys($slots));

        foreach ($slots as $top3Key => $slot) {
            $this->assertSame('triad_blend_copy', $slot['slot_key']);
            $this->assertSame($top3Key, $slot['top3_key']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->top3ChainRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot), 'Top3 chain '.$top3Key.' should be contract-clean.');
        }
    }

    public function test_all_top3_chains_have_authored_runtime_copy_from_file_backed_asset(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        foreach (RiasecDeepCopySlotRegistry::TOP3_COMBOS as $top3Key) {
            $slot = $registry->resolveTop3ChainSlot($top3Key);

            $this->assertSame('authored', $slot['content_status']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertSame('content_review', $slot['review_status']);
            $this->assertSame('expert_reviewed', $slot['evidence_level']);
            $this->assertSame('riasec_top3_code_chain_strategy_v1.zh-CN', $slot['content_version']);

            foreach ($registry->authoredTop3ChainRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot));
        }
    }

    public function test_top3_key_selection_is_deterministic_for_unordered_fixture(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $this->assertSame('R_I_A', $registry->resolveTop3ChainSlot(['A', 'R', 'I'])['top3_key']);
        $this->assertSame('R_S_C', $registry->resolveTop3ChainSlot('C×S×R')['top3_key']);
        $this->assertSame('I_E_C', $registry->resolveTop3ChainSlot('E-I-C')['top3_key']);
    }

    public function test_top3_ordered_code_matrix_reorders_visible_reading_emphasis_for_20_by_3_targets(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        foreach (self::TARGET_ORDERED_MATRIX as $unorderedTop3Key => $orderedCodes) {
            $seenOrderedReadings = [];

            foreach ($orderedCodes as $orderedCode) {
                $letters = str_split($orderedCode);
                $slot = $registry->resolveTop3ChainSlot($orderedCode);

                $this->assertSame($unorderedTop3Key, $slot['top3_key']);
                $this->assertSame($unorderedTop3Key, $slot['canonical_unordered_top3_key']);
                $this->assertSame($orderedCode, $slot['ordered_code']);
                $this->assertSame(implode('_', $letters), $slot['ordered_top3_key']);
                $this->assertSame($letters, $slot['ordered_top3_dimensions']);

                $this->assertStringContainsString('第一位 '.self::DIMENSION_LABELS[$letters[0]], $slot['primary_activity_chain']);
                $this->assertStringContainsString('第二位 '.self::DIMENSION_LABELS[$letters[1]], $slot['secondary_support_line']);
                $this->assertStringContainsString('第三位 '.self::DIMENSION_LABELS[$letters[2]], $slot['tertiary_stabilizer']);
                $this->assertStringContainsString($orderedCode.' 是本次测量的有序三字码', $slot['ordered_code_handling']);
                $this->assertStringContainsString('不能推断人格身份、能力水平或职业结论', $slot['core_reading']);
                $this->assertStringContainsString('不提供岗位答案', $slot['positive_value']);

                $activitySequence = $slot['activity_sequence'];
                $this->assertCount(3, $activitySequence);
                $this->assertStringStartsWith('优先观察入口：', $activitySequence[0]);
                $this->assertStringStartsWith('第二观察入口：', $activitySequence[1]);
                $this->assertStringStartsWith('第三观察入口：', $activitySequence[2]);
                $this->assertStringContainsString('优先观察入口', $slot['ordered_code_handling']);
                $this->assertStringContainsString('排序改变先问什么、后验证什么', $slot['ordered_code_handling']);
                $this->assertStringContainsString('小任务证据', $slot['primary_activity_chain']);
                $this->assertStringNotContainsString('决定主读重心', $slot['ordered_code_handling']);
                $this->assertStringNotContainsString('职业推荐', $slot['ordered_code_handling']);
                $this->assertStringNotContainsString('岗位匹配', $slot['ordered_code_handling']);

                $seenOrderedReadings[] = implode(' | ', [
                    $slot['primary_activity_chain'],
                    $slot['secondary_support_line'],
                    $slot['tertiary_stabilizer'],
                ]);
                $this->assertSame([], $registry->validateSlot($slot), 'Ordered Top3 slot '.$orderedCode.' should remain contract-clean.');
            }

            $this->assertCount(3, array_unique($seenOrderedReadings), $unorderedTop3Key.' must expose three different ordered readings.');
        }
    }

    public function test_top3_hero_copy_frames_order_as_observation_not_identity_or_fit(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        foreach (self::TARGET_ORDERED_MATRIX as $orderedCodes) {
            foreach ($orderedCodes as $orderedCode) {
                $slot = $registry->resolveTop3ChainSlot($orderedCode);
                $visibleCopy = implode("\n", array_filter([
                    $slot['strategy_label'] ?? '',
                    $slot['activity_chain'] ?? '',
                    $slot['core_reading'] ?? '',
                    $slot['positive_value'] ?? '',
                    $slot['real_world_cost'] ?? '',
                    $slot['first_experiment'] ?? '',
                    $slot['ordered_code_handling'] ?? '',
                    $slot['primary_activity_chain'] ?? '',
                    $slot['secondary_support_line'] ?? '',
                    $slot['tertiary_stabilizer'] ?? '',
                    $slot['likely_tension'] ?? '',
                    $slot['low_risk_validation'] ?? '',
                    $slot['free_page_teaser'] ?? '',
                ]));

                $this->assertStringContainsString('观察入口', $visibleCopy);
                $this->assertStringContainsString('小任务', $visibleCopy);
                $this->assertStringContainsString('收集证据', $visibleCopy);
                $this->assertStringContainsString('不能推断人格身份、能力水平或职业结论', $visibleCopy);
                $this->assertStringNotContainsString('活动链', $visibleCopy);
                $this->assertStringNotContainsString('决定主读重心', $visibleCopy);
                $this->assertStringNotContainsString('岗位匹配', $visibleCopy);
                $this->assertStringNotContainsString('职业匹配', $visibleCopy);
                $this->assertStringNotContainsString('成功概率', $visibleCopy);
            }
        }
    }

    public function test_top3_chain_rejects_identity_career_and_success_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolveTop3ChainSlot('R_I_A');
        $slot['body'] = '你就是某类人，职业推荐很明确，职业成功概率很高。';

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
    }

    public function test_unknown_top3_chain_fails_closed_without_frontend_fallback(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolveTop3ChainSlot('R_I_Z');

        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }
}
