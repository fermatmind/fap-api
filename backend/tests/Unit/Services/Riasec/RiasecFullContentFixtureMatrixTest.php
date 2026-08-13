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
    public function test_frozen_runtime_assets_parse_and_pass_editorial_hygiene_scan(): void
    {
        $manifestPath = base_path('content_assets/riasec/qa/result_content_freeze.v1.2026-08-13-r6.json');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $baseManifest = json_decode((string) file_get_contents(base_path($manifest['base_manifest'])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($manifest['base_package_sha256'], $baseManifest['package_sha256']);
        $this->assertCount($manifest['effective_file_count'], $manifest['files']);
        $packageLines = '';

        foreach ((array) ($manifest['files'] ?? []) as $relativePath => $expectedSha) {
            $path = base_path((string) $relativePath);
            $this->assertFileExists($path);
            $this->assertSame($expectedSha, hash_file('sha256', $path), 'Frozen asset SHA drifted: '.$relativePath);
            $raw = (string) file_get_contents($path);
            $packageLines .= $expectedSha.'  backend/'.$relativePath."\n";

            if (str_ends_with((string) $relativePath, '.jsonl')) {
                foreach (array_filter(explode("\n", $raw), static fn (string $line): bool => trim($line) !== '') as $line) {
                    json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            } elseif (str_ends_with((string) $relativePath, '.json')) {
                json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }

            foreach ([
                '。；', '不比较 ，', 'TODO', 'TBD', 'placeholder',
                '前三个兴趣维度 说明', '覆盖 本次', '替换 本次', '当前 本次',
                '把 近似并列 当作', '近似并列 和', '不同表单 原始', '代码 或',
                '而不是谁更准，而不是直接改写结果', '。 当前',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $raw, 'Editorial hygiene failure in '.$relativePath);
            }
        }

        $this->assertSame($manifest['package_sha256'], hash('sha256', $packageLines));
        $this->assertSame('PASS', data_get($manifest, 'gates.scientific_boundary_scan'));
        $this->assertSame('PASS', data_get($manifest, 'gates.runtime_visible_copy_scan'));
        $this->assertSame('PASS', data_get($manifest, 'gates.independent_editorial_review'));
        $this->assertSame([
            'faq_markdown_reference',
            'professional_method_boundary',
            'history_cross_form',
        ], $manifest['intentional_fail_closed_surfaces']);
    }

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

        $runtimeLifecycle = $lifecycle->runtimeLifecycleCopyContract(true, 'zh-CN');
        $this->assertFalse($runtimeLifecycle['faq_markdown_reference_available']);
        $this->assertSame('', $runtimeLifecycle['professional_method_boundary_asset_id']);
        $this->assertNotContains('history_cross_form', array_column($runtimeLifecycle['surfaces'], 'surface'));
        $this->assertSame('editorial_gate_failed_fail_closed', $runtimeLifecycle['disabled_reason']);
        $runtimeSerialized = json_encode($runtimeLifecycle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['near-tie', '不同 form', 'raw score', 'raw score delta', '能量入口'] as $blockedCopy) {
            $this->assertStringNotContainsString($blockedCopy, $runtimeSerialized);
        }

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

    public function test_result_summary_matrix_is_complete_localized_and_bounded(): void
    {
        $service = app(RiasecPublicProjectionService::class);
        $results = [];
        foreach (self::TARGET_ORDERED_MATRIX as $orderedCodes) {
            foreach ($orderedCodes as $orderedCode) {
                $results[] = $this->resultForOrderedCode($orderedCode);
            }
        }
        $results[] = $this->resultFor140qLayerTension();

        foreach ($results as $result) {
            foreach ([['zh-CN', 900], ['en', 500]] as [$locale, $limit]) {
                $summary = $service->buildV2FromResult($result, $locale, true)['result_summary_v1'];
                $this->assertSame('riasec.result_summary.v1', $summary['schema_version']);
                $this->assertTrue($summary['snapshot_bound']);
                $this->assertCount(3, $summary['highlights']);
                $this->assertCount(3, array_unique(array_column($summary['highlights'], 'dimension_code')));
                foreach ($summary['highlights'] as $highlight) {
                    $this->assertMatchesRegularExpression('/^[RIASEC]$/', $highlight['dimension_code']);
                    $this->assertNotSame('', trim($highlight['label']));
                    $this->assertNotSame('', trim($highlight['text']));
                }

                $strings = [];
                array_walk_recursive($summary, static function (mixed $value) use (&$strings): void {
                    if (is_string($value)) {
                        $strings[] = $value;
                    }
                });
                $text = implode($locale === 'zh-CN' ? '' : ' ', $strings);
                $length = $locale === 'zh-CN' ? mb_strlen($text) : str_word_count($text);
                $this->assertLessThanOrEqual($limit, $length);
            }
        }
    }

    public function test_public_deep_content_slots_use_the_identity_redacted_review_contract(): void
    {
        $projection = app(RiasecPublicProjectionService::class)
            ->buildV2FromResult($this->resultForOrderedCode('RIA'), 'zh-CN');
        $slots = (array) data_get($projection, 'deep_content_slots_v1.slots', []);

        $this->assertNotEmpty($slots);
        foreach ($slots as $slot) {
            $this->assertContains($slot['review_state'] ?? null, ['approved', 'pending', 'rejected', 'unknown']);
            $this->assertArrayHasKey('last_reviewed_at', $slot);
            $this->assertNull($slot['last_reviewed_at']);
            $this->assertArrayHasKey('reviewer', $slot);
            $this->assertNull($slot['reviewer']);
        }
    }

    public function test_public_result_does_not_repeat_complete_editorial_sentences(): void
    {
        $projections = [
            app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultForOrderedCode('RIA'), 'zh-CN'),
            app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultFor140qLayerTension(), 'zh-CN'),
        ];

        foreach ($projections as $projection) {
            $counts = [];
            foreach ((array) data_get($projection, 'deep_content_slots_v1.slots', []) as $slot) {
                foreach ((array) ($slot['content'] ?? []) as $value) {
                    foreach (is_array($value) ? $value : [$value] as $text) {
                        if (! is_string($text)) {
                            continue;
                        }
                        foreach (preg_split('/(?<=[。！？.!?])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                            $sentence = preg_replace('/\s+/u', ' ', trim($sentence)) ?? trim($sentence);
                            if (mb_strlen($sentence) >= 12 && preg_match('/[。！？.!?]$/u', $sentence) === 1) {
                                $counts[$sentence] = ($counts[$sentence] ?? 0) + 1;
                            }
                        }
                    }
                }
            }

            $duplicates = array_filter(
                $counts,
                fn (int $count, string $sentence): bool => $count > 1 && ! $this->isWhitelistedRepeatedTemplate($sentence),
                ARRAY_FILTER_USE_BOTH
            );
            $this->assertSame([], $duplicates, 'A public result must not repeat complete editorial sentences.');
        }

        $pairSlots = array_values(array_filter(
            (array) data_get($projections[0], 'deep_content_slots_v1.slots', []),
            static fn (array $slot): bool => ($slot['module_key'] ?? null) === 'pair_blend'
        ));
        $this->assertCount(3, $pairSlots);
        foreach ($pairSlots as $slot) {
            $this->assertGreaterThanOrEqual(4, count((array) data_get($slot, 'content.activities_to_validate', [])));
            $this->assertNotEmpty(data_get($slot, 'content.chemistry'));
            $this->assertNotEmpty(data_get($slot, 'content.real_world_cost'));
            $this->assertNotEmpty(data_get($slot, 'content.common_misread'));
        }
    }

    private function isWhitelistedRepeatedTemplate(string $sentence): bool
    {
        foreach ([
            '它只说明本次结果中哪些活动值得优先观察',
            'RIASEC 只提供兴趣活动线索',
            '高分只提高观察优先级',
            '它只说明这类活动在当前排序里不靠前',
            '训练门槛、资源权限、反馈节奏和责任强度',
            '更稳妥的读法是：它只提示两类活动值得并排验证',
            '这不是组合身份',
            '把两个记录放在同一页',
            '如果要合并观察，只选择一个低风险场景',
            '第一维与后续维度差距较明显',
            '六维分布显示一个相对更靠前的兴趣入口',
            '它只让工作日常线索更具体',
            '可以先按三字码阅读',
            '可以先从三字母顺序进入结果',
            '先做一个',
            '再做一个',
        ] as $approvedTemplate) {
            if (str_contains($sentence, $approvedTemplate)) {
                return true;
            }
        }

        return false;
    }

    public function test_public_projection_propagates_locale_into_lifecycle_copy(): void
    {
        $projection = app(RiasecPublicProjectionService::class)
            ->buildV2FromResult($this->resultForOrderedCode('RIA'), 'en-US');

        $this->assertSame('en', data_get($projection, 'locale'));
        $this->assertSame('en', data_get($projection, 'lifecycle_copy_v1.locale'));
        $this->assertSame('unavailable', data_get($projection, 'lifecycle_copy_v1.status'));
        $this->assertSame([], data_get($projection, 'lifecycle_copy_v1.surfaces'));
        $this->assertSame('locale_content_unavailable', data_get($projection, 'lifecycle_copy_v1.runtime_review_gate'));
    }

    public function test_140q_projection_selects_layer_emphasis_without_cross_form_score_comparison(): void
    {
        $projection = app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultFor140qLayerTension(), 'zh-CN');

        $this->assertFalse((bool) data_get($projection, 'form.raw_score_delta_allowed'));
        $this->assertSame('emphasis_difference_only', data_get($projection, 'form.cross_form_interpretation'));
        $this->assertSame('task_environment_role_emphasis_only', data_get($projection, 'structural_difference.basis'));
        $this->assertSame('explicit_layer_state_or_unavailable_without_score_delta', data_get($projection, 'structural_difference.selection_rule'));
        $this->assertFalse((bool) data_get($projection, 'structural_difference.raw_score_delta_allowed'));
        $this->assertFalse((bool) data_get($projection, 'structural_difference.raw_scores_used_for_selection'));
        $this->assertSame('tension', data_get($projection, 'structural_difference.layer_states.environment'));
        $this->assertSame(
            '60Q 与 140Q 只能读作线索强调不同，不比较原始分数，不输出优劣或覆盖判断。',
            data_get($projection, 'structural_difference.public_copy_boundary')
        );
        $this->assertArrayNotHasKey('raw_scores_delta', $projection);
        $this->assertArrayNotHasKey('domains_delta', $projection);

        foreach ((array) data_get($projection, 'deep_content_slots_v1.slots', []) as $slot) {
            if (($slot['slot_group'] ?? null) !== '140q_layer_copy') {
                continue;
            }

            $this->assertStringNotContainsString('raw score', strtolower((string) ($slot['user_visible_boundary'] ?? '')));
        }

        $environmentSlot = $this->firstSlotForModuleAndState($projection, '140q_context_cards', [
            'layer' => 'environment',
            'layer_state' => 'tension',
        ]);
        $this->assertStringContainsString('环境层-张力', (string) data_get($environmentSlot, 'content.environment_card'));

        $aggregateSlot = $this->firstSlotForModuleAndState($projection, '140q_context_cards', [
            'slot_name' => 'layer_tension',
            'layer_state' => 'tension',
        ]);
        $this->assertStringContainsString('张力', (string) data_get($aggregateSlot, 'content.title'));
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

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,string>  $state
     * @return array<string,mixed>
     */
    private function firstSlotForModuleAndState(array $projection, string $moduleKey, array $state): array
    {
        foreach ((array) data_get($projection, 'deep_content_slots_v1.slots', []) as $slot) {
            if (($slot['module_key'] ?? null) !== $moduleKey) {
                continue;
            }

            foreach ($state as $key => $value) {
                if (data_get($slot, 'state.'.$key) !== $value) {
                    continue 2;
                }
            }

            return $slot;
        }

        $this->fail('Missing RIASEC projection slot for module '.$moduleKey.' and state '.json_encode($state, JSON_THROW_ON_ERROR));
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

    private function resultFor140qLayerTension(): Result
    {
        $scores = ['R' => 100, 'I' => 75, 'A' => 50, 'S' => 0, 'E' => 0, 'C' => 0];

        return new Result([
            'scale_code' => 'RIASEC',
            'type_code' => 'RIA',
            'scores_pct' => $scores,
            'result_json' => [
                'top_code' => 'RIA',
                'primary_type' => 'R',
                'secondary_type' => 'I',
                'tertiary_type' => 'A',
                'answer_count' => 140,
                'form_code' => 'riasec_140',
                'scoring_spec_version' => 'riasec_enhanced_140_v1',
                'scores_0_100' => $scores,
                'structural_difference_state' => 'layer_tension',
                'riasec_140q_layer_states' => [
                    'task' => 'agreement',
                    'environment' => 'tension',
                    'role' => 'agreement',
                ],
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
