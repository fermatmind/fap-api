<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecActivityExplorerService;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use App\Services\Riasec\RiasecLifecycleCopyService;
use App\Services\Riasec\RiasecPublicProjectionService;
use App\Services\Riasec\RiasecReportModuleSelector;
use Tests\TestCase;

final class RiasecFullContentFixtureMatrixTest extends TestCase
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

    private const FORBIDDEN_USER_CLAIMS = [
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'success prediction',
        'success probability',
        'recommended career',
        'best career',
        'career recommendation',
        'occupation ranking',
        'hiring suitability',
        'ability proof',
        'skill inference',
        '140Q more accurate',
        'raw score delta',
        '60Q wrong',
        '职业匹配',
        '岗位匹配',
        '匹配度',
        '适合度',
        '最适合',
        '推荐职业',
        '职业推荐',
        '岗位胜任',
        '成功概率',
        '职业成功',
        '更准确',
        '更准',
        '140题更准确',
        '60题错了',
        '推翻',
        '最终答案',
        '你就是',
        '天生适合',
        '能力证明',
        '技能证明',
        '招聘筛选',
        '录取依据',
        '晋升依据',
        '淘汰依据',
    ];

    private const FORBIDDEN_SCIENCE_BOUNDARY_COPY = [
        '验证这条链',
        '有序三字码只改变阅读重心和语气',
        '实作型入口',
        '研究型处理',
        '收口',
        '活动链',
        '现场求证者',
        '有形表达者',
        '现场支持者',
        '落地推动者',
        '流程实作者',
        '概念表达者',
        '证据倾听者',
        '判断推动者',
        '秩序表达者',
        '稳定服务者',
        '流程推动者',
        '行动动员者',
        '影响表达者',
        '常见消耗',
        '更有能量',
        '能量还是消耗',
        '商务拓展',
        '组织资源',
        '领导力',
        '服务能力',
        '能力证明',
        '技能证明',
        '岗位胜任',
        '天生会推动',
    ];

    public function test_backend_full_content_matrix_counts_and_boundaries_are_frozen(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $activityExplorer = new RiasecActivityExplorerService;
        $lifecycle = new RiasecLifecycleCopyService;
        $overlay = $this->overlay('IAS', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1');

        $pairSlots = array_values(array_filter(
            $registry->pairBlendSlots(),
            static fn (array $slot): bool => ($slot['content_version'] ?? null) === 'riasec_pair_blend_15_pairs_v1.zh-CN'
        ));
        $top3Slots = array_values(array_filter(
            $registry->top3ChainSlots(),
            static fn (array $slot): bool => ($slot['content_version'] ?? null) === 'riasec_top3_code_chain_strategy_v1.zh-CN'
        ));
        $aspirationSlots = array_values(array_filter(
            $registry->aspirationsSlots(),
            static fn (array $slot): bool => ($slot['content_version'] ?? null) === 'aspirations_calibration_v1.zh-CN'
        ));
        $disagreeSlots = array_values(array_filter(
            $registry->disagreePathSlots(),
            static fn (array $slot): bool => ($slot['content_version'] ?? null) === 'disagree_path_v1.zh-CN'
        ));

        $this->assertCount(15, $pairSlots);
        $this->assertCount(20, $top3Slots);
        $this->assertCount(70, $aspirationSlots);
        $this->assertCount(45, $disagreeSlots);

        foreach ([$pairSlots, $top3Slots, $aspirationSlots, $disagreeSlots] as $slotGroup) {
            foreach ($slotGroup as $slot) {
                $this->assertSame('authored', $slot['content_status']);
                $this->assertFalse((bool) ($slot['frontend_fallback_allowed'] ?? true));
            }
        }

        $ias = $activityExplorer->build('IAS', 'zh-CN');
        $this->assertSame('available', data_get($ias, 'code_activity_pack.status'));
        $this->assertCount(9, (array) data_get($ias, 'code_activity_pack.activities'));
        $this->assertSame(
            18,
            array_sum(array_map(
                static fn (array $activity): int => count((array) ($activity['occupation_examples'] ?? [])),
                (array) data_get($ias, 'code_activity_pack.activities', [])
            ))
        );
        $this->assertFalse((bool) data_get($ias, 'boundary.fit_score_allowed'));
        $this->assertFalse((bool) data_get($ias, 'boundary.success_prediction_allowed'));

        $lifecycleContract = $lifecycle->lifecycleCopyContract(true);
        $this->assertSame('riasec.lifecycle_copy.v1', $lifecycleContract['schema_version']);
        $this->assertCount(7, (array) $lifecycleContract['surfaces']);
        $this->assertCount(20, (array) $lifecycleContract['faq_items']);
        $this->assertFalse($lifecycleContract['frontend_fallback_allowed']);
        $this->assertFalse($lifecycleContract['measured_payload_mutation_allowed']);
        $this->assertFalse($lifecycleContract['report_snapshot_mutation_allowed']);
        $this->assertFalse($lifecycleContract['raw_feedback_public_exposure_allowed']);
        $this->assertFalse($lifecycleContract['internal_snapshot_id_public_exposure_allowed']);

        $this->assertCount(6, $lifecycle->technicalNoteSummarySections());
        $this->assertCount(8, $lifecycle->professionalMethodBoundarySections());

        $this->assertSame('available_static_safe_bridge', data_get($overlay, 'action_lab_v1.status'));
        $this->assertCount(18, (array) data_get($overlay, 'action_lab_v1.starter_actions'));
        $this->assertSame('available_static_safe_bridge', data_get($overlay, 'next_exploration_nodes_v1.status'));
        $this->assertCount(6, (array) data_get($overlay, 'next_exploration_nodes_v1.nodes'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.scores_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.holland_code_mutation_allowed'));
    }

    public function test_module_visibility_matrix_freezes_clear_blended_broad_near_tie_low_quality_and_140q_states(): void
    {
        $selector = new RiasecReportModuleSelector;

        $clear = $selector->build($this->projectionContext('normal', 'clear_code', 'riasec_60'));
        $this->assertSame('visible', $this->moduleVisibility($clear, 'hero_activity_chain'));
        $this->assertSame('visible', $this->moduleVisibility($clear, 'pair_blend'));
        $this->assertSame('collapsed', $this->moduleVisibility($clear, 'occupation_examples'));
        $this->assertSame('visible', $this->moduleVisibility($clear, '140q_cta'));
        $this->assertSame('hidden', $this->moduleVisibility($clear, '140q_context_cards'));

        $blended = $selector->build($this->projectionContext('normal', 'blended_code', 'riasec_60'));
        $this->assertSame('visible', $this->moduleVisibility($blended, 'hero_activity_chain'));
        $this->assertSame('visible', $this->moduleVisibility($blended, 'pair_blend'));
        $this->assertSame('collapsed', $this->moduleVisibility($blended, 'occupation_examples'));

        $broad = $selector->build($this->projectionContext('normal', 'broad_profile', 'riasec_60'));
        $this->assertSame('hidden', $this->moduleVisibility($broad, 'hero_activity_chain'));
        $this->assertSame('collapsed', $this->moduleVisibility($broad, 'pair_blend'));
        $this->assertSame('hidden', $this->moduleVisibility($broad, 'occupation_examples'));

        $nearTie = $selector->build($this->projectionContext('normal', 'near_tie', 'riasec_60'));
        $this->assertSame('collapsed', $this->moduleVisibility($nearTie, 'hero_activity_chain'));
        $this->assertSame('visible', $this->moduleVisibility($nearTie, 'pair_blend'));

        $lowClarity = $selector->build($this->projectionContext('normal', 'low_clarity', 'riasec_60'));
        $this->assertSame('collapsed', $this->moduleVisibility($lowClarity, 'hero_activity_chain'));
        $this->assertSame('collapsed', $this->moduleVisibility($lowClarity, 'pair_blend'));
        $this->assertSame('hidden', $this->moduleVisibility($lowClarity, 'occupation_examples'));

        $lowQuality = $selector->build($this->projectionContext('low_quality', 'low_quality', 'riasec_60'));
        $this->assertSame('hidden', $this->moduleVisibility($lowQuality, 'pair_blend'));
        $this->assertSame('hidden', $this->moduleVisibility($lowQuality, 'occupation_examples'));
        $this->assertSame('collapsed', $this->moduleVisibility($lowQuality, 'share_card'));
        $this->assertSame('collapsed', $this->moduleVisibility($lowQuality, 'pdf'));

        $enhanced140 = $selector->build($this->projectionContext('normal', 'clear_code', 'riasec_140'));
        $this->assertSame('hidden', $this->moduleVisibility($enhanced140, '140q_cta'));
        $this->assertSame('visible', $this->moduleVisibility($enhanced140, '140q_context_cards'));
    }

    public function test_backend_full_content_outputs_reject_forbidden_claims_and_public_exposure(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $activityExplorer = new RiasecActivityExplorerService;
        $lifecycle = new RiasecLifecycleCopyService;

        $payload = [
            'pairs' => array_values($registry->pairBlendSlots()),
            'top3' => array_values($registry->top3ChainSlots()),
            'aspirations' => array_values($registry->aspirationsSlots()),
            'disagree' => array_values($registry->disagreePathSlots()),
            'activity_explorer' => [
                $activityExplorer->build('IAS', 'zh-CN'),
                $activityExplorer->build('RCE', 'zh-CN'),
            ],
            'feedback_overlay' => [
                $this->overlay('IAS', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1'),
                $this->overlay('RIA', 'riasec_140', 'riasec_140_likert5_activity_context_space.v1'),
            ],
            'lifecycle' => $lifecycle->lifecycleCopyContract(true),
            'technical_note_summary' => $lifecycle->technicalNoteSummarySections(),
            'professional_method_boundary' => $lifecycle->professionalMethodBoundarySections(),
        ];

        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $hits = [];
        foreach ($this->visibleRows($payload) as $source => $texts) {
            foreach ($texts as $text) {
                foreach (self::FORBIDDEN_USER_CLAIMS as $claim) {
                    if (! $this->containsTerm($text, $claim)) {
                        continue;
                    }

                    if ($this->isNegatedBoundary($text, $claim)) {
                        continue;
                    }

                    $hits[] = "{$source}: {$claim} in {$text}";
                }
            }
        }

        $this->assertSame([], $hits, 'Visible backend full-content outputs must keep forbidden claims only in negative boundary contexts.');

        $scienceBoundaryHits = [];
        foreach ($this->visibleRows($payload) as $source => $texts) {
            foreach ($texts as $text) {
                foreach (self::FORBIDDEN_SCIENCE_BOUNDARY_COPY as $phrase) {
                    if ($this->containsTerm($text, $phrase)) {
                        $scienceBoundaryHits[] = "{$source}: {$phrase} in {$text}";
                    }
                }
            }
        }

        $this->assertSame([], $scienceBoundaryHits, 'Visible RIASEC content must not reintroduce chain templates, persona labels, or ability/outcome overclaims.');

        foreach (['R_S', 'I_E', 'A_C'] as $lowConsistencyPair) {
            $slot = $registry->resolvePairBlendSlot($lowConsistencyPair);
            $this->assertStringContainsString('距离较远', (string) ($slot['chemistry'] ?? ''));
            $this->assertStringContainsString('低一致性组合', (string) ($slot['chemistry'] ?? ''));
        }

        $this->assertStringNotContainsString('"frontend_fallback_allowed":true', $serialized);
        $this->assertStringNotContainsString('"raw_feedback"', $serialized);
        $this->assertStringNotContainsString('"snapshot_id"', $serialized);
        $this->assertStringNotContainsString('"source_url"', $serialized);
        $this->assertStringNotContainsString('"onet_code"', $serialized);
        $this->assertStringNotContainsString('"soc_code"', $serialized);
        $this->assertStringContainsString('content_example_not_registry_match', $serialized);
        $this->assertStringContainsString('riasec.lifecycle_copy.v1', $serialized);
    }

    public function test_public_projection_exposes_ordered_top3_reading_for_result_page_payload_matrix(): void
    {
        $projectionService = app(RiasecPublicProjectionService::class);

        foreach (self::TARGET_ORDERED_MATRIX as $unorderedTop3Key => $orderedCodes) {
            foreach ($orderedCodes as $orderedCode) {
                $letters = str_split($orderedCode);
                $projection = $projectionService->buildV2FromResult($this->resultForOrderedCode($orderedCode), 'zh-CN');
                $heroSlot = $this->firstSlotForModule($projection, 'hero_activity_chain');

                $this->assertSame('triad_blend_copy:'.$unorderedTop3Key, $heroSlot['slot_id']);
                $this->assertStringContainsString('第一位 '.self::DIMENSION_LABELS[$letters[0]], (string) data_get($heroSlot, 'content.primary_activity_chain'));
                $this->assertStringContainsString('第二位 '.self::DIMENSION_LABELS[$letters[1]], (string) data_get($heroSlot, 'content.secondary_support_line'));
                $this->assertStringContainsString('第三位 '.self::DIMENSION_LABELS[$letters[2]], (string) data_get($heroSlot, 'content.tertiary_stabilizer'));
                $this->assertStringContainsString($orderedCode.' 是本次测量的有序三字码', (string) data_get($heroSlot, 'content.ordered_code_handling'));
                $this->assertStringContainsString('不能推断人格身份、能力水平或职业结论', (string) data_get($heroSlot, 'content.core_reading'));
                $this->assertStringContainsString('不提供岗位答案', (string) data_get($heroSlot, 'content.positive_value'));
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionContext(string $qualityState, string $profileShape, string $formCode): array
    {
        return [
            'quality' => [
                'quality_state' => $qualityState,
            ],
            'interpretation_state' => [
                'profile_shape' => $profileShape,
            ],
            'form' => [
                'form_code' => $formCode,
            ],
        ];
    }

    private function moduleVisibility(array $policy, string $moduleKey): ?string
    {
        foreach ((array) ($policy['modules'] ?? []) as $module) {
            if (($module['key'] ?? null) === $moduleKey) {
                return (string) ($module['visibility'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function firstSlotForModule(array $projection, string $moduleKey): array
    {
        foreach ((array) data_get($projection, 'deep_content_slots_v1.slots', []) as $slot) {
            if (($slot['module_key'] ?? null) === $moduleKey) {
                return $slot;
            }
        }

        $this->fail('Missing RIASEC projection slot for module '.$moduleKey);
    }

    private function resultForOrderedCode(string $orderedCode): Result
    {
        $scores = array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], 0);
        foreach (str_split($orderedCode) as $index => $dimension) {
            $scores[$dimension] = [100, 75, 50][$index];
        }

        return new Result([
            'scale_code' => 'RIASEC',
            'type_code' => $orderedCode,
            'scores_pct' => $scores,
            'result_json' => [
                'top_code' => $orderedCode,
                'primary_type' => $orderedCode[0],
                'secondary_type' => $orderedCode[1],
                'tertiary_type' => $orderedCode[2],
                'answer_count' => 60,
                'form_code' => 'riasec_60',
                'scoring_spec_version' => 'riasec_standard_60_v1',
                'scores_0_100' => $scores,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function overlay(string $code, string $formCode, string $scoreSpaceVersion): array
    {
        return (new RiasecExplorationFeedbackOverlayService)->build(
            new Result([
                'scale_code' => 'RIASEC',
                'type_code' => $code,
                'result_json' => [
                    'form_code' => $formCode,
                    'score_space_version' => $scoreSpaceVersion,
                ],
            ]),
            [
                'holland_code' => [
                    'code' => $code,
                ],
                'form' => [
                    'form_code' => $formCode,
                    'score_space_version' => $scoreSpaceVersion,
                ],
            ],
            true
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,list<string>>
     */
    private function visibleRows(array $payload): array
    {
        $visible = [];

        foreach ((array) ($payload['pairs'] ?? []) as $index => $slot) {
            $visible['pair '.($index + 1)] = array_values(array_filter([
                (string) ($slot['pair_label'] ?? ''),
                (string) ($slot['short_label'] ?? ''),
                (string) ($slot['chemistry'] ?? ''),
                (string) ($slot['positive_value'] ?? ''),
                (string) ($slot['real_world_cost'] ?? ''),
                (string) ($slot['common_misread'] ?? ''),
                (string) ($slot['micro_experiment'] ?? ''),
                (string) ($slot['result_page_teaser'] ?? ''),
                (string) ($slot['deep_report_extension_hint'] ?? ''),
                (string) ($slot['user_visible_boundary'] ?? ''),
            ]));
        }

        foreach ((array) ($payload['top3'] ?? []) as $index => $slot) {
            $visible['top3 '.($index + 1)] = array_values(array_filter([
                (string) ($slot['strategy_label'] ?? ''),
                (string) ($slot['activity_chain'] ?? ''),
                (string) ($slot['core_reading'] ?? ''),
                (string) ($slot['positive_value'] ?? ''),
                (string) ($slot['real_world_cost'] ?? ''),
                (string) ($slot['title'] ?? ''),
                (string) ($slot['primary_activity_chain'] ?? ''),
                (string) ($slot['secondary_support_line'] ?? ''),
                (string) ($slot['tertiary_stabilizer'] ?? ''),
                (string) ($slot['likely_tension'] ?? ''),
                (string) ($slot['first_experiment'] ?? ''),
                (string) ($slot['ordered_code_handling'] ?? ''),
                (string) ($slot['low_risk_validation'] ?? ''),
                (string) ($slot['free_page_teaser'] ?? ''),
                (string) ($slot['deep_report_extension'] ?? ''),
                (string) ($slot['user_visible_boundary'] ?? ''),
            ]));
        }

        foreach ((array) ($payload['aspirations'] ?? []) as $index => $slot) {
            $visible['aspiration '.($index + 1)] = array_values(array_filter([
                (string) ($slot['title'] ?? ''),
                (string) ($slot['summary'] ?? ''),
                (string) ($slot['body'] ?? ''),
            ]));
        }

        foreach ((array) ($payload['disagree'] ?? []) as $index => $slot) {
            $visible['disagree '.($index + 1)] = array_values(array_filter([
                (string) ($slot['title'] ?? ''),
                (string) ($slot['summary'] ?? ''),
                (string) ($slot['body'] ?? ''),
            ]));
        }

        foreach ((array) data_get($payload, 'activity_explorer', []) as $index => $explorer) {
            foreach ((array) data_get($explorer, 'code_activity_pack.activities', []) as $activityIndex => $activity) {
                $visible["activity {$index}:".($activityIndex + 1)] = array_values(array_filter([
                    (string) ($activity['activity_name'] ?? ''),
                    (string) ($activity['task_example'] ?? ''),
                    (string) ($activity['validation_question'] ?? ''),
                    (string) ($activity['expected_observation'] ?? ''),
                    (string) ($activity['boundary'] ?? ''),
                    (string) ($activity['not_a_recommendation'] ?? ''),
                ]));

                foreach ((array) ($activity['occupation_examples'] ?? []) as $occupationIndex => $example) {
                    $visible["occupation {$index}:".($occupationIndex + 1)] = array_values(array_filter([
                        (string) ($example['occupation_example'] ?? ''),
                        (string) ($example['display_label'] ?? ''),
                        (string) ($example['user_visible_boundary'] ?? ''),
                        (string) ($example['education_boundary'] ?? ''),
                        (string) ($example['skill_boundary'] ?? ''),
                        (string) ($example['qualification_boundary'] ?? ''),
                    ]));
                }
            }
        }

        foreach ((array) data_get($payload, 'feedback_overlay', []) as $index => $overlay) {
            foreach ((array) data_get($overlay, 'action_lab_v1.starter_actions', []) as $actionIndex => $action) {
                $visible["action_lab {$index}:".($actionIndex + 1)] = array_values(array_filter([
                    (string) ($action['user_copy'] ?? ''),
                    (string) ($action['system_response'] ?? ''),
                    (string) ($action['next_step_copy'] ?? ''),
                ]));
            }

            foreach ((array) data_get($overlay, 'next_exploration_nodes_v1.nodes', []) as $nodeIndex => $node) {
                $visible["next_node {$index}:".($nodeIndex + 1)] = array_values(array_filter([
                    (string) ($node['title'] ?? ''),
                    (string) ($node['summary'] ?? ''),
                    (string) ($node['instruction'] ?? ''),
                ]));
            }
        }

        foreach ((array) data_get($payload, 'lifecycle.surfaces', []) as $index => $surface) {
            $visible['lifecycle surface '.($index + 1)] = [(string) ($surface['copy'] ?? '')];
        }
        foreach ((array) data_get($payload, 'lifecycle.faq_items', []) as $index => $faq) {
            $visible['lifecycle faq '.($index + 1)] = [(string) ($faq['q'] ?? ''), (string) ($faq['a'] ?? '')];
        }
        foreach ((array) ($payload['technical_note_summary'] ?? []) as $index => $section) {
            $visible['technical note '.($index + 1)] = [(string) ($section['title'] ?? ''), (string) ($section['copy'] ?? '')];
        }
        foreach ((array) ($payload['professional_method_boundary'] ?? []) as $index => $section) {
            $visible['method boundary '.($index + 1)] = [(string) ($section['title'] ?? ''), (string) ($section['body'] ?? '')];
        }

        return $visible;
    }

    private function containsTerm(string $text, string $term): bool
    {
        return mb_stripos($text, $term) !== false;
    }

    private function isNegatedBoundary(string $text, string $term): bool
    {
        $quoted = preg_quote($term, '/');

        return preg_match('/(不|不是|不能|不会|不得|不应|不该|不测|只说明|不代表|不能用于).{0,30}'.$quoted.'/u', $text) === 1
            || preg_match('/'.$quoted.'.{0,10}(不是|不代表|不能|不会|不得)/u', $text) === 1;
    }
}
