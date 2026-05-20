<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use App\Services\Riasec\RiasecTechnicalNoteService;
use Tests\TestCase;

final class RiasecLifecycleCopyPreflightTest extends TestCase
{
    private const EXPECTED_SHARE_SURFACES = 7;

    private const EXPECTED_FAQ_QUESTIONS = 20;

    private const EXPECTED_TECHNICAL_NOTE_SECTIONS = 6;

    private const EXPECTED_METHOD_BOUNDARY_SECTIONS = 8;

    private const REQUIRED_BOUNDARIES = [
        'interest_evidence_only',
        'not_personality_identity',
        'not_ability_or_skill_measure',
        'not_career_recommendation',
        'examples_not_matches',
        'not_job_fit',
        'not_success_prediction',
        'not_hiring_or_screening_use',
        'no_60q_140q_raw_delta',
        '140q_contextual_not_more_accurate',
        'feedback_does_not_mutate_measured_result',
        'missing_content_fails_closed',
        'frontend_fallback_forbidden',
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
        'more accurate',
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

    private const ALLOWED_GOVERNANCE_TERMS = [
        'theory_based',
        'under_validation',
        'internally_pilot_required',
        'externally_validated',
        'snapshot_id',
    ];

    public function test_v7_3_lifecycle_fixtures_are_complete_and_safe(): void
    {
        $share = $this->sharePdfHistory();
        $faq = $this->faq();
        $technicalNote = $this->technicalNoteSummary();
        $methodBoundary = $this->professionalMethodBoundary();

        $this->assertSame('share_pdf_history_v1.zh-CN', $share['asset_id']);
        $this->assertCount(self::EXPECTED_SHARE_SURFACES, $share['surfaces']);
        $this->assertSame([
            'share_safe_card',
            'share_detail_boundary',
            'pdf_personal',
            'pdf_counselor_discussion',
            'history_same_form',
            'history_cross_form',
            'low_quality_share',
        ], array_column($share['surfaces'], 'surface'));
        foreach ($share['surfaces'] as $surface) {
            $this->assertFalse($surface['raw_scores_allowed']);
            $this->assertFalse($surface['raw_feedback_allowed']);
        }
        $this->assertSame('omit_module', $share['fallback_behavior']);
        $this->assertFalse($share['frontend_fallback_allowed']);
        $this->assertRequiredBoundaries($share['required_boundaries']);

        $this->assertSame('faq_v1.zh-CN', $faq['asset_id']);
        $this->assertCount(self::EXPECTED_FAQ_QUESTIONS, $faq['questions']);
        foreach ($faq['questions'] as $entry) {
            $this->assertArrayHasKey('q', $entry);
            $this->assertArrayHasKey('a', $entry);
            $this->assertNotSame('', trim((string) $entry['q']));
            $this->assertNotSame('', trim((string) $entry['a']));
        }
        $this->assertFalse($faq['frontend_fallback_allowed']);
        $this->assertSame('omit_module', $faq['fallback_behavior']);
        $this->assertRequiredBoundaries($faq['required_boundaries']);

        $this->assertSame('technical_note_user_summary_v1.zh-CN', $technicalNote['asset_id']);
        $this->assertCount(self::EXPECTED_TECHNICAL_NOTE_SECTIONS, $technicalNote['summary_sections']);
        $this->assertSame([
            '这个测试测什么',
            '这个测试不测什么',
            '60Q 和 140Q 的关系',
            '如何读分数',
            '如何读职业例子',
            '反馈如何使用',
        ], array_column($technicalNote['summary_sections'], 'title'));
        $this->assertFalse($technicalNote['frontend_fallback_allowed']);
        $this->assertSame('omit_module', $technicalNote['fallback_behavior']);
        $this->assertRequiredBoundaries($technicalNote['required_boundaries']);

        $this->assertSame('professional_method_boundary_v1.zh-CN', $methodBoundary['asset_id']);
        $this->assertCount(self::EXPECTED_METHOD_BOUNDARY_SECTIONS, $methodBoundary['sections']);
        $this->assertSame([
            'measurement_object',
            'score_space',
            'forms',
            'examples',
            'validation_status',
            'feedback',
            'privacy',
            'restricted_use',
        ], array_column($methodBoundary['sections'], 'key'));
        $this->assertFalse($methodBoundary['frontend_fallback_allowed']);
        $this->assertRequiredBoundaries($methodBoundary['required_boundaries']);
    }

    public function test_faq_markdown_fixture_exists_and_is_readable(): void
    {
        $path = base_path('tests/Fixtures/Riasec/faq_v1.zh-CN.md');
        $this->assertFileExists($path);

        $markdown = file_get_contents($path);
        $this->assertIsString($markdown);
        $this->assertStringContainsString('#', $markdown);
        $this->assertStringContainsString('140Q', $markdown);
        $this->assertStringContainsString('PDF', $markdown);
    }

    public function test_visible_lifecycle_copy_has_no_positive_forbidden_claims_or_technical_keys(): void
    {
        $hits = [];

        foreach ($this->visibleRows() as $source => $texts) {
            foreach ($texts as $text) {
                foreach (self::FORBIDDEN_USER_CLAIMS as $claim) {
                    if (! $this->containsTerm($text, $claim)) {
                        continue;
                    }

                    if ($this->isQuestionPrompt($source) || $this->isNegatedBoundary($text, $claim)) {
                        continue;
                    }

                    $hits[] = "{$source}: {$claim} in {$text}";
                }

                preg_match_all('/\b[a-z]+(?:_[a-z0-9]+)+\b/u', $text, $matches);
                $unexpected = array_values(array_diff(array_unique($matches[0] ?? []), self::ALLOWED_GOVERNANCE_TERMS));
                $this->assertSame([], $unexpected, "{$source} exposes an unexpected technical key in user-facing copy");
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_current_runtime_contracts_remain_snapshot_bound_and_public_safe(): void
    {
        $contract = app(RiasecTechnicalNoteService::class)->contract();

        $this->assertSame('riasec_technical_note.v0.1', data_get($contract, 'technical_note_v1.technical_note_version'));
        $this->assertSame('riasec.method_boundary.v0.1', data_get($contract, 'technical_note_v1.method_boundary_version'));
        $this->assertContains('snapshot_boundary', array_column((array) data_get($contract, 'technical_note_v1.sections', []), 'section_key'));
        $this->assertContains('feedback_overlay_boundary', array_column((array) data_get($contract, 'technical_note_v1.disclaimers', []), 'key'));
        $this->assertContains('cross_form_raw_score_delta', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
        $this->assertContains('job_fit', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));

        $overlay = (new RiasecExplorationFeedbackOverlayService)->build(
            new Result([
                'scale_code' => 'RIASEC',
                'type_code' => 'IAS',
                'result_json' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ]),
            [
                'holland_code' => ['code' => 'IAS'],
                'form' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ],
            true
        );

        $this->assertTrue((bool) data_get($overlay, 'snapshot_identity.snapshot_bound'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.report_snapshot_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'read_model.raw_feedback_included'));
    }

    public function test_preflight_decision_is_conditional_go_and_defers_frontend(): void
    {
        $path = base_path('docs/riasec/lifecycle-copy-pack-11-be-preflight.md');
        $this->assertFileExists($path);

        $report = file_get_contents($path);
        $this->assertIsString($report);
        $this->assertStringContainsString('Decision for PACK-11-BE: CONDITIONAL GO', $report);
        $this->assertStringContainsString('PACK-11-FE decision: DEFERRED', $report);
        $this->assertStringContainsString('Current backend bridge gap', $report);
        $this->assertStringContainsString('Current fap-web dependency scan', $report);
        $this->assertStringContainsString('This preflight does not import runtime share_pdf_history, faq, technical_note_user_summary, or professional_method_boundary content.', $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function sharePdfHistory(): array
    {
        return $this->loadJson(base_path('tests/Fixtures/Riasec/share_pdf_history_v1.zh-CN.json'));
    }

    /**
     * @return array<string,mixed>
     */
    private function faq(): array
    {
        return $this->loadJson(base_path('tests/Fixtures/Riasec/faq_v1.zh-CN.json'));
    }

    /**
     * @return array<string,mixed>
     */
    private function technicalNoteSummary(): array
    {
        return $this->loadJson(base_path('tests/Fixtures/Riasec/technical_note_user_summary_v1.zh-CN.json'));
    }

    /**
     * @return array<string,mixed>
     */
    private function professionalMethodBoundary(): array
    {
        return $this->loadJson(base_path('tests/Fixtures/Riasec/professional_method_boundary_v1.zh-CN.json'));
    }

    /**
     * @return array<string,list<string>>
     */
    private function visibleRows(): array
    {
        $visible = [];

        foreach ($this->sharePdfHistory()['surfaces'] as $index => $surface) {
            $visible['share surface '.($index + 1)] = [(string) $surface['copy']];
        }

        foreach ($this->faq()['questions'] as $index => $entry) {
            $visible['faq question '.($index + 1)] = [(string) $entry['q']];
            $visible['faq answer '.($index + 1)] = [(string) $entry['a']];
        }

        foreach ($this->technicalNoteSummary()['summary_sections'] as $index => $entry) {
            $visible['technical note section '.($index + 1)] = [
                (string) $entry['title'],
                (string) $entry['copy'],
            ];
        }

        foreach ($this->professionalMethodBoundary()['sections'] as $index => $entry) {
            $visible['method boundary section '.($index + 1)] = [
                (string) $entry['title'],
                (string) $entry['body'],
            ];
        }

        return $visible;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, "{$path} must decode to an object");

        return $decoded;
    }

    /**
     * @param  list<string>  $boundaries
     */
    private function assertRequiredBoundaries(array $boundaries): void
    {
        foreach (self::REQUIRED_BOUNDARIES as $boundary) {
            $this->assertContains($boundary, $boundaries);
        }
    }

    private function containsTerm(string $text, string $term): bool
    {
        return mb_stripos($text, $term) !== false;
    }

    private function isQuestionPrompt(string $source): bool
    {
        return str_starts_with($source, 'faq question ');
    }

    private function isNegatedBoundary(string $text, string $term): bool
    {
        $quoted = preg_quote($term, '/');

        return preg_match('/(不|不是|不能|不会|不得|不应|不该|不测|只说明|不代表|不能用于).{0,30}'.$quoted.'/u', $text) === 1
            || preg_match('/'.$quoted.'.{0,10}(不是|不代表|不能|不会|不得)/u', $text) === 1;
    }
}
