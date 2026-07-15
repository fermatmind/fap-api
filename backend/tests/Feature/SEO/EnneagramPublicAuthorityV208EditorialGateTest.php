<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EnneagramPublicAuthorityV208EditorialGateTest extends TestCase
{
    private const LEDGER_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07';

    public function test_complete_candidate_produces_exactly_116_passing_qa_rows_without_human_or_release_claims(): void
    {
        $result = $this->gate()->validateEditorial($this->candidate(), $this->registry(), $this->maps());

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('ready_for_human_review', $result['status']);
        $this->assertSame(116, $result['target_count']);
        $this->assertSame(116, $result['qa_row_count']);
        $this->assertCount(116, $result['qa_rows']);
        $this->assertSame(['pass'], collect($result['qa_rows'])->pluck('status')->unique()->values()->all());
        $this->assertSame([], $result['issues']);
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_eligible']);
        foreach (['writes_committed', 'cms_write_attempted', 'database_mutation_attempted', 'indexability_mutation_attempted', 'sitemap_mutation_attempted', 'llms_mutation_attempted', 'search_submission_attempted', 'deploy_attempted'] as $field) {
            $this->assertFalse($result[$field], $field);
        }
    }

    public function test_duplicate_template_bilingual_faq_and_exercise_negative_fixtures_fail_closed(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][1]['answer_first'] = $candidate['assets'][0]['answer_first'];
        $candidate['assets'][0]['sections'][1]['body'] = $candidate['assets'][0]['sections'][0]['body'];
        $candidate['assets'][2]['sections'][0]['body'] = 'Type 1 notices a recurring pressure signal in a specific meeting context, checks a concrete counterexample, and records a uniquely tagged alternative explanation before acting.';
        $candidate['assets'][3]['sections'][0]['body'] = 'Type 2 notices a recurring pressure signal in a specific meeting context, checks a concrete counterexample, and records a uniquely tagged alternative explanation before acting.';
        $pair = $this->localePairIndexes($candidate, 'hub:enneagram');
        $candidate['assets'][$pair['zh-CN']]['authoring']['outline'] = $candidate['assets'][$pair['en']]['authoring']['outline'];
        $candidate['assets'][1]['faqs'][0]['answer'] = $candidate['assets'][0]['faqs'][0]['answer'];
        $candidate['assets'][0]['observation_exercise'] = [
            'duration_days' => 7,
            'context' => 'For the next seven days, observe whatever happens during ordinary situations without choosing a page-specific context.',
            'observable_signal' => 'Notice general thoughts and feelings without defining an observable behavior that belongs to this page.',
            'page_specific_signal' => 'Use a generic journal prompt that could be copied unchanged onto every type, wing, subtype, or center page.',
            'alternative_explanation' => 'Write any alternative explanation without connecting it to the situation, role, culture, or current demand.',
            'reflection_prompt' => 'Reflect generally at the end of the week without testing a concrete hypothesis from this page.',
        ];

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertSame('fail_closed', $result['status']);
        $this->assertContains('duplicate_paragraph', $codes);
        $this->assertContains('duplicate_sentence', $codes);
        $this->assertContains('type_number_substitution_template', $codes);
        $this->assertContains('identical_locale_outline', $codes);
        $this->assertContains('repeated_faq_answer', $codes);
        $this->assertContains('generic_seven_day_exercise', $codes);
        $this->assertFalse($result['writes_committed']);
    }

    public function test_science_prediction_hidden_evidence_competitor_and_model_review_fixtures_fail_closed(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['observation_exercise']['reflection_prompt'] = 'Truity wording is scientifically proven by neuroscience and predicts career success, relationship success, and the perfect partner.';
        $candidate['assets'][0]['visible_evidence']['visible'] = false;
        $candidate['assets'][0]['visible_evidence']['claim_ids'] = ['claim.blocked.predictive_outcomes'];
        $candidate['assets'][0]['visible_evidence']['limitations'] = ['', 'placeholder'];
        $candidate['assets'][0]['review_truth'] = [
            'status' => 'approved',
            'reviewer' => 'Codex model QA',
            'reviewed_at' => '2026-07-15T00:00:00Z',
            'human_review_completed' => true,
            'review_source' => 'model_review',
        ];

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported_science_claim', $codes);
        $this->assertContains('career_or_relationship_prediction', $codes);
        $this->assertContains('competitor_language_detected', $codes);
        $this->assertContains('visible_evidence_or_limitations_missing', $codes);
        $this->assertContains('factual_claim_hidden', $codes);
        $this->assertContains('visible_claim_not_declared', $codes);
        $this->assertContains('visible_claim_not_authorized', $codes);
        $this->assertContains('manual_review_truth_invalid', $codes);
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['publish_eligible']);
    }

    public function test_blocked_claim_and_missing_target_fail_claim_and_coverage_gates(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['claim_ids'][] = 'claim.blocked.predictive_outcomes';
        array_pop($candidate['assets']);

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('claim_not_authorized_for_page', $codes);
        $this->assertContains('target_count_invalid', $codes);
        $this->assertContains('target_asset_missing', $codes);
        $this->assertSame(116, $result['qa_row_count']);
    }

    public function test_answerability_placeholders_fail_closed(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['answerability'] = [
            'direct_answer_supported' => true,
            'questions' => ['a', ' b ', 'c'],
        ];

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('geo_answerability_insufficient', collect($result['issues'])->pluck('code')->all());
    }

    public function test_repeated_observation_exercise_text_fails_duplicate_gate(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][1]['observation_exercise']['context'] = $candidate['assets'][0]['observation_exercise']['context'];

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('duplicate_paragraph', collect($result['issues'])->pluck('code')->all());
    }

    public function test_unsafe_visible_section_heading_fails_claim_safety_gate(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['sections'][0]['heading'] = 'Scientifically proven perfect career prediction by Truity';

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported_science_claim', $codes);
        $this->assertContains('competitor_language_detected', $codes);
    }

    public function test_mapped_limitation_must_remain_visible(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['visible_evidence']['limitations'] = [
            'This generic limitation is long enough to satisfy the substantive text length requirement by itself.',
            'This second generic limitation is also long enough but does not preserve the mapped page boundary.',
        ];

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('mapped_limitation_hidden', collect($result['issues'])->pluck('code')->all());
    }

    public function test_pronoun_and_hyphenated_prediction_phrasing_fails_claim_safety_gate(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['sections'][0]['heading'] = 'Predicts your career success';
        $candidate['assets'][1]['sections'][0]['heading'] = 'Career-success predictor';
        $candidate['assets'][58]['sections'][0]['heading'] = '预测你的职业成功';

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());
        $predictionIssues = collect($result['issues'])->where('code', 'career_or_relationship_prediction');

        $this->assertFalse($result['ok']);
        $this->assertCount(3, $predictionIssues);
    }

    public function test_full_blocked_predictive_outcome_vocabulary_fails_claim_safety_gate(): void
    {
        foreach ([
            'Salary predictor',
            'Turnover predictor',
            'Predicts your health outcome',
            'Predicts your admission outcome',
            'Legal outcome predictor',
            'Financial outcome predictor',
            '预测你的薪资结果',
            '预测您的升学结果',
        ] as $phrase) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['sections'][0]['heading'] = $phrase;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertFalse($result['ok'], $phrase);
            $this->assertContains('career_or_relationship_prediction', collect($result['issues'])->pluck('code')->all(), $phrase);
        }
    }

    public function test_explicitly_negated_boundary_claims_remain_allowed(): void
    {
        foreach ([
            'This page does not predict your career success.',
            'This content is not scientifically proven.',
            'This is not a precise career recommendation.',
            '本页不能预测你的职业成功。',
            '本页并非精准职业推荐。',
        ] as $limitation) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['sections'][0]['heading'] = $limitation;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertTrue($result['ok'], $limitation.': '.json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_diagnosis_and_screening_claim_vocabulary_fails_claim_safety_gate(): void
    {
        foreach ([
            'Medical diagnosis',
            'Clinical diagnostic result',
            'Diagnoses your personality',
            'Diagnose your personality',
            'Personality diagnosis',
            'Treats your anxiety',
            'Hiring fit',
            'Job suitability guarantee',
            '诊断你的性格',
            '治愈你的焦虑',
            '岗位适配保证',
        ] as $phrase) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['sections'][0]['heading'] = $phrase;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertFalse($result['ok'], $phrase);
            $this->assertContains('diagnosis_or_screening_claim', collect($result['issues'])->pluck('code')->all(), $phrase);
        }
    }

    public function test_bare_medical_claims_fail_without_rejecting_bounded_negative_language(): void
    {
        foreach ([
            'Unlock to know your diagnosis',
            'Diagnosis',
            'Treatment',
            'Cure',
            '诊断',
            '确诊',
            '治疗',
            '治愈',
        ] as $phrase) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['sections'][0]['heading'] = $phrase;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertFalse($result['ok'], $phrase);
            $this->assertContains('diagnosis_or_screening_claim', collect($result['issues'])->pluck('code')->all(), $phrase);
        }

        $bounded = $this->gate()->validateEditorial($this->candidate(), $this->registry(), $this->maps());
        $this->assertTrue($bounded['ok'], json_encode($bounded['issues'], JSON_UNESCAPED_UNICODE));
    }

    public function test_deterministic_recommendation_vocabulary_fails_claim_safety_gate(): void
    {
        foreach ([
            'Precise career recommendation',
            'Best career for you',
            'Perfect job match',
            'RIASEC ranks your best career',
            'Determines your income',
            'Salary guarantee',
            '最适合你的职业',
            '决定你的能力',
        ] as $phrase) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['sections'][0]['heading'] = $phrase;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertFalse($result['ok'], $phrase);
            $this->assertContains('deterministic_recommendation_claim', collect($result['issues'])->pluck('code')->all(), $phrase);
        }
    }

    public function test_generic_seven_day_text_cannot_bypass_the_gate_with_mismatched_duration(): void
    {
        $candidate = $this->candidate();
        $candidate['assets'][0]['observation_exercise']['duration_days'] = 6;
        $candidate['assets'][0]['observation_exercise']['context'] = 'For the next seven days, observe whatever happens during ordinary situations while keeping the metadata duration at six.';

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('generic_seven_day_exercise', collect($result['issues'])->pluck('code')->all());
    }

    public function test_numeric_seven_day_exercise_prompts_fail_closed(): void
    {
        foreach ([
            'For the next 7 days, observe whatever happens during ordinary situations while keeping this prompt generic.',
            'For the next ７-day period, journal whatever happens during ordinary situations while keeping this prompt generic.',
            '连续7天观察普通情境中发生的任何事情，同时保留这段可以复制到所有页面的通用提示文字。',
        ] as $context) {
            $candidate = $this->candidate();
            $candidate['assets'][0]['observation_exercise']['duration_days'] = 6;
            $candidate['assets'][0]['observation_exercise']['context'] = $context;

            $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

            $this->assertFalse($result['ok'], $context);
            $this->assertContains('generic_seven_day_exercise', collect($result['issues'])->pluck('code')->all(), $context);
        }
    }

    public function test_chinese_type_numerals_are_normalized_for_template_detection(): void
    {
        $candidate = $this->candidate();
        $typeOne = $this->localePairIndexes($candidate, 'core_type:type-1')['zh-CN'];
        $typeTwo = $this->localePairIndexes($candidate, 'core_type:type-2')['zh-CN'];
        $candidate['assets'][$typeOne]['sections'][0]['body'] = '第一型在这段测试文本中记录一个具体请求发生时最先注意到的信息、被推迟的选项、随后能够被他人观察到的行动，并把推测动机与可见行为分开，再检查角色、文化、疲劳和当下任务是否提供更好的替代解释。';
        $candidate['assets'][$typeTwo]['sections'][0]['body'] = '第二型在这段测试文本中记录一个具体请求发生时最先注意到的信息、被推迟的选项、随后能够被他人观察到的行动，并把推测动机与可见行为分开，再检查角色、文化、疲劳和当下任务是否提供更好的替代解释。';

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('type_number_substitution_template', collect($result['issues'])->pluck('code')->all());
    }

    public function test_english_spelled_type_numbers_are_normalized_for_template_detection(): void
    {
        $candidate = $this->candidate();
        $typeOne = $this->localePairIndexes($candidate, 'core_type:type-1')['en'];
        $typeTwo = $this->localePairIndexes($candidate, 'core_type:type-2')['en'];
        $candidate['assets'][$typeOne]['sections'][0]['body'] = 'Type One in this regression paragraph records which information drew attention first, which option was delayed, and which action another person could observe, then separates inferred motive from visible behavior and tests role, culture, fatigue, and current demands as alternative explanations.';
        $candidate['assets'][$typeTwo]['sections'][0]['body'] = 'Type Two in this regression paragraph records which information drew attention first, which option was delayed, and which action another person could observe, then separates inferred motive from visible behavior and tests role, culture, fatigue, and current demands as alternative explanations.';

        $result = $this->gate()->validateEditorial($candidate, $this->registry(), $this->maps());

        $this->assertFalse($result['ok']);
        $this->assertContains('type_number_substitution_template', collect($result['issues'])->pluck('code')->all());
    }

    public function test_command_is_read_only_and_fails_closed_without_a_source(): void
    {
        $exit = Artisan::call('personality:enneagram-authority-v2-integrity-gate', [
            '--editorial-source' => '/tmp/does-not-exist-enneagram-editorial-candidate.json',
            '--json' => true,
        ]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($result['ok']);
        $this->assertSame('command_error', $result['issues'][0]['code']);
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['publish_eligible']);
        $this->assertFalse($result['writes_committed']);
    }

    public function test_command_reads_a_complete_candidate_and_emits_a_non_mutating_summary(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'enneagram-editorial-gate-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($this->candidate(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $exit = Artisan::call('personality:enneagram-authority-v2-integrity-gate', ['--editorial-source' => $path]);
            $output = Artisan::output();
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('status=ready_for_human_review', $output);
        $this->assertStringContainsString('qa_row_count=116', $output);
        $this->assertStringContainsString('human_review_completed=0', $output);
        $this->assertStringContainsString('publish_eligible=0', $output);
        $this->assertStringContainsString('writes_committed=0', $output);
    }

    public function test_documented_contract_freezes_all_ten_gates_and_negative_fixtures(): void
    {
        $contract = $this->readJson('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-editorial-gate-08/editorial-gate-contract.json');

        $this->assertSame(116, $contract['qa_row_contract']['expected_count']);
        $this->assertCount(10, $contract['qa_row_contract']['gates']);
        $this->assertCount(10, $contract['required_negative_fixtures']);
        $this->assertSame('ready_for_human_review', $contract['automated_pass_means']);
        $this->assertSame('pending_manual_review', $contract['manual_review_truth']['status']);
        $this->assertNull($contract['manual_review_truth']['reviewer']);
        $this->assertFalse($contract['manual_review_truth']['human_review_completed']);
        $this->assertSame([false], array_values(array_unique($contract['execution_boundaries'])));
    }

    private function gate(): EnneagramPublicAuthorityV2IntegrityGate
    {
        return app(EnneagramPublicAuthorityV2IntegrityGate::class);
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        $claims = collect($this->registry()['claims'])->keyBy('id');
        $assets = [];
        foreach ($this->maps()['page_maps'] as $index => $map) {
            $locale = $map['locale'];
            $identity = $map['identity_key'];
            $token = substr(hash('sha256', $locale.'|'.$identity), 0, 12);
            $english = $locale === 'en';
            $claimIds = collect($map['claim_ids'])
                ->filter(fn (string $claimId): bool => ($claims->get($claimId)['allowed_as_public_claim'] ?? false) === true)
                ->values()
                ->all();
            $assets[] = [
                'identity_key' => $identity,
                'locale' => $locale,
                'entity_type' => $map['entity_type'],
                'code' => $map['code'],
                'path' => $map['path'],
                'title' => $english ? "Observation guide {$identity} {$token}" : "观察指南 {$identity} {$token}",
                'answer_first' => $english
                    ? "The {$identity} page uses the unique marker {$token} to frame a limited self-observation hypothesis: compare motive with visible behavior, test a counterexample, and consider role, culture, context, and current demands before treating the pattern as useful."
                    : "{$identity} 页面以唯一标记 {$token} 提出有限的自我观察假设：把动机与可见行为分开，主动寻找反例，并在认为模式有用之前检查角色、文化、具体情境和当下任务要求。",
                'authoring' => [
                    'mode' => 'independent_original',
                    'source_locale' => null,
                    'independence_note' => $english
                        ? "Original English editorial route {$token} starts from an observable decision and does not translate another locale."
                        : "中文原创编辑路径 {$token} 从具体情境中的注意与选择展开，不以其他语言版本作为逐句翻译来源。",
                    'outline' => $english
                        ? ['answer_first', 'observable_pattern', 'alternative_explanation', 'counterexample', 'exercise']
                        : ['answer_first', 'counterexample', 'contextual_alternative', 'observable_pattern', 'exercise'],
                    'page_specific_signals' => $english
                        ? [
                            "{$token} decision cue under a time-limited request",
                            "{$token} feedback response when the expected role changes",
                            "{$token} counterexample when context rewards another behavior",
                        ]
                        : [
                            "{$token} 在限时请求中的具体决策线索",
                            "{$token} 在预期角色改变时的反馈反应",
                            "{$token} 在环境鼓励另一种行为时出现的反例",
                        ],
                ],
                'sections' => [
                    [
                        'kind' => 'observable_pattern',
                        'heading' => $english ? "Pattern check {$token}" : "模式检查 {$token}",
                        'body' => $english
                            ? "In the {$token} situation, record what drew attention first, which option was delayed, and what observable action followed; then separate the reported motive from the behavior another person could actually see."
                            : "在 {$token} 情境中，先记录注意力最早落在哪里、哪个选项被推迟、随后出现了什么可观察行动，再把自述动机与他人实际可见的行为区分开。",
                    ],
                    [
                        'kind' => 'counterexample',
                        'heading' => $english ? "Counterexample {$token}" : "反例 {$token}",
                        'body' => $english
                            ? "A {$token} counterexample is a comparable moment in which the expected pattern did not appear; check whether authority, fatigue, incentives, safety, learned skill, or cultural norms better explain the difference."
                            : "{$token} 的反例是在可比较时刻没有出现预期模式；应检查权力关系、疲劳、激励、安全感、已训练技能或文化规范是否更能解释差异。",
                    ],
                    [
                        'kind' => 'boundary',
                        'heading' => $english ? "Use boundary {$token}" : "使用边界 {$token}",
                        'body' => $english
                            ? "Treat the {$token} observation as a revisable working hypothesis, never as diagnosis, fixed identity, ability judgment, hiring screen, relationship verdict, or forecast of career and income outcomes."
                            : "把 {$token} 观察当作可修正的工作假设，不把它当成诊断、固定身份、能力判断、录用筛选、关系结论，或对职业和收入结果的预测。",
                    ],
                ],
                'faqs' => collect(range(1, 3))->map(fn (int $faqIndex): array => [
                    'question' => $english
                        ? "What should I check for {$identity} question {$faqIndex} {$token}?"
                        : "观察 {$identity} 时，第 {$faqIndex} 个问题应检查什么 {$token}？",
                    'answer' => $english
                        ? "For {$token} FAQ {$faqIndex}, compare one concrete event with a counterexample, record the visible action separately from the inferred motive, and keep role, culture, incentives, fatigue, and current demands available as alternative explanations."
                        : "针对 {$token} 的第 {$faqIndex} 个问答，请比较一个具体事件与一个反例，把可见行动和推测动机分开记录，并保留角色、文化、激励、疲劳和当下任务要求等替代解释。",
                ])->all(),
                'observation_exercise' => [
                    'duration_days' => ($index % 5) + 2,
                    'context' => $english
                        ? "Choose one recurring {$token} context with a clear request, deadline, and another person who can observe the resulting action."
                        : "选择一个反复出现的 {$token} 情境，其中应有明确请求、时间限制，以及能够观察最终行动的另一位参与者。",
                    'observable_signal' => $english
                        ? "Record the first visible {$token} action, the delayed option, and the words used before assigning any motive to the event."
                        : "记录 {$token} 事件中最先出现的可见行动、被推迟的选项和当时使用的原话，再考虑可能的动机。",
                    'page_specific_signal' => $english
                        ? "Test the unique {$token} decision-and-feedback cue named in this page rather than a generic personality journaling prompt."
                        : "检验本页命名的 {$token} 决策与反馈线索，而不是套用任何人格页面都能使用的通用记录提示。",
                    'alternative_explanation' => $english
                        ? "Before interpreting {$token}, write one role, culture, incentive, fatigue, safety, skill, or situational explanation that could produce the same behavior."
                        : "解释 {$token} 之前，先写出一种角色、文化、激励、疲劳、安全感、技能或具体情境因素，它也可能产生同样行为。",
                    'reflection_prompt' => $english
                        ? "Compare the {$token} hypothesis with the counterexample and state what evidence would make you revise or discard the interpretation."
                        : "把 {$token} 假设与反例进行比较，并说明哪些证据会让你修正或放弃当前解释。",
                ],
                'answerability' => [
                    'direct_answer_supported' => true,
                    'questions' => $english
                        ? ["What is {$identity} {$token}?", "How can {$identity} {$token} be observed?", "What are the limits of {$identity} {$token}?"]
                        : ["{$identity} {$token} 是什么？", "如何观察 {$identity} {$token}？", "{$identity} {$token} 有哪些边界？"],
                ],
                'claim_ids' => $claimIds,
                'visible_evidence' => [
                    'visible' => true,
                    'claim_ids' => $map['factual_claim_ids'],
                    'limitations' => [
                        ...$map['limitations'],
                        $english
                            ? "The {$token} editorial example does not establish FermatMind reliability, validity, norms, percentiles, prediction, or clinical utility."
                            : "{$token} 编辑示例不能证明费马测试自身的信度、效度、常模、百分位、预测能力或临床用途。",
                    ],
                ],
                'review_truth' => [
                    'status' => 'pending_manual_review',
                    'reviewer' => null,
                    'reviewed_at' => null,
                    'human_review_completed' => false,
                    'review_source' => 'unassigned',
                ],
                'release_truth' => [
                    'draft_only' => true,
                    'publish_eligible' => false,
                    'indexability_changed' => false,
                    'sitemap_changed' => false,
                    'llms_changed' => false,
                ],
            ];
        }

        return [
            'schema_version' => EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_SCHEMA_VERSION,
            'artifact' => 'test-complete-candidate',
            'framework' => 'enneagram',
            'assets' => $assets,
        ];
    }

    /** @param array<string, mixed> $candidate @return array{en: int, zh-CN: int} */
    private function localePairIndexes(array $candidate, string $identity): array
    {
        $pair = [];
        foreach ($candidate['assets'] as $index => $asset) {
            if ($asset['identity_key'] === $identity) {
                $pair[$asset['locale']] = $index;
            }
        }
        $this->assertArrayHasKey('en', $pair);
        $this->assertArrayHasKey('zh-CN', $pair);

        return $pair;
    }

    /** @return array<string, mixed> */
    private function registry(): array
    {
        return $this->readJson(self::LEDGER_DIR.'/source-registry.json');
    }

    /** @return array<string, mixed> */
    private function maps(): array
    {
        return $this->readJson(self::LEDGER_DIR.'/page-claim-maps.json');
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(base_path($path)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
