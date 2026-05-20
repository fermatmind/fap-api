<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use Tests\TestCase;

final class RiasecAspirationsDisagreeAssetPreflightTest extends TestCase
{
    private const EXPECTED_ASPIRATION_RECORDS = 70;

    private const EXPECTED_DISAGREE_RECORDS = 45;

    private const VALID_DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    private const ASPIRATION_REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'domain_key',
        'user_aspiration_label',
        'likely_overlap_dimensions',
        'overlap_reading',
        'reality_questions',
        'next_low_risk_experiment',
        'score_mutation_allowed',
        'measured_holland_code_mutation_allowed',
        'not_a_recommendation',
        'review_status',
        'required_boundaries',
        'forbidden_claims',
        'frontend_fallback_allowed',
    ];

    private const DISAGREE_REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'state',
        'title',
        'summary',
        'questions',
        'recommended_next_action',
        'retake_allowed',
        'score_mutation_allowed',
        'measured_holland_code_mutation_allowed',
        'snapshot_mutation_allowed',
        'share_pdf_exposure_allowed',
        'review_status',
        'required_boundaries',
        'forbidden_claims',
        'frontend_fallback_allowed',
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

    private const RESULT_OVERRIDE_PHRASES = [
        '系统错了',
        '你其实是',
        '反馈会改分',
        '愿望覆盖测评结果',
        '修正你的 Code',
        '改写分数',
        '改写 measured Holland Code',
    ];

    public function test_v7_3_aspirations_fixture_is_complete_and_non_mutating(): void
    {
        $records = $this->aspirationRows();

        $this->assertCount(self::EXPECTED_ASPIRATION_RECORDS, $records);

        foreach ($records as $index => $record) {
            foreach (self::ASPIRATION_REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.aspirations_calibration.v1', $record['schema_version']);
            $this->assertSame('riasec_aspirations_calibration_v1.zh-CN', $record['asset_version']);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:_[a-z0-9]+)*_[^_]+$/u', $record['domain_key']);
            $this->assertIsList($record['likely_overlap_dimensions']);
            $this->assertNotEmpty($record['likely_overlap_dimensions']);
            foreach ($record['likely_overlap_dimensions'] as $dimension) {
                $this->assertContains($dimension, self::VALID_DIMENSIONS);
            }
            $this->assertIsList($record['reality_questions']);
            $this->assertGreaterThanOrEqual(3, count($record['reality_questions']));
            $this->assertFalse($record['score_mutation_allowed']);
            $this->assertFalse($record['measured_holland_code_mutation_allowed']);
            $this->assertTrue($record['not_a_recommendation']);
            $this->assertFalse($record['frontend_fallback_allowed']);
        }
    }

    public function test_v7_3_disagree_fixture_is_complete_and_non_mutating(): void
    {
        $records = $this->disagreeRows();
        $states = array_column($records, 'state');

        $this->assertCount(self::EXPECTED_DISAGREE_RECORDS, $records);

        foreach ([
            'normal_disagree_学生',
            'near_tie_disagree',
            'broad_profile_disagree_学生',
            'low_quality_disagree',
            'aspiration_conflict',
            'result_too_broad',
            'result_too_narrow',
            '60Q_140Q_tension',
            'user_life_context_conflict',
            'role_environment_conflict',
            'task_interest_vs_job_reality_conflict',
        ] as $requiredState) {
            $this->assertContains($requiredState, $states);
        }

        foreach ($records as $index => $record) {
            foreach (self::DISAGREE_REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.disagree_path.v1', $record['schema_version']);
            $this->assertSame('riasec_disagree_path_v1.zh-CN', $record['asset_version']);
            $this->assertIsList($record['questions']);
            $this->assertGreaterThanOrEqual(3, count($record['questions']));
            $this->assertFalse($record['score_mutation_allowed']);
            $this->assertFalse($record['measured_holland_code_mutation_allowed']);
            $this->assertFalse($record['snapshot_mutation_allowed']);
            $this->assertFalse($record['share_pdf_exposure_allowed']);
            $this->assertFalse($record['frontend_fallback_allowed']);
        }
    }

    public function test_v7_3_aspirations_and_disagree_visible_copy_has_no_forbidden_claims_or_technical_keys(): void
    {
        $hits = [];

        foreach ($this->visibleRows() as $source => $texts) {
            foreach ($texts as $text) {
                foreach (self::FORBIDDEN_USER_CLAIMS as $claim) {
                    if ($this->containsTerm($text, $claim)) {
                        $hits[] = "{$source}: {$claim} in {$text}";
                    }
                }

                foreach (self::RESULT_OVERRIDE_PHRASES as $phrase) {
                    if ($this->containsTerm($text, $phrase) && ! $this->isNegatedBoundary($text, $phrase)) {
                        $hits[] = "{$source}: result override phrase {$phrase} in {$text}";
                    }
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/\b[a-z]+(?:_[a-z0-9]+)+\b/',
                    $text,
                    "{$source} exposes a technical key in user-facing copy",
                );
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_current_overlay_contract_blocks_result_and_public_surface_mutation(): void
    {
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

        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.scores_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.holland_code_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.report_snapshot_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'read_model.raw_feedback_included'));
        $this->assertArrayNotHasKey('attempt_id', $overlay);
    }

    public function test_preflight_decision_is_conditional_go_with_documented_mapping_and_public_surface_gaps(): void
    {
        $this->assertFileExists(base_path('docs/riasec/aspirations-disagree-pack-09-preflight.md'));

        $report = file_get_contents(base_path('docs/riasec/aspirations-disagree-pack-09-preflight.md'));
        $this->assertIsString($report);
        $this->assertStringContainsString('Decision: CONDITIONAL GO', $report);
        $this->assertStringContainsString('Mapping Gap', $report);
        $this->assertStringContainsString('Public-Surface Exclusion Gap', $report);
        $this->assertStringContainsString('must not mutate measured_holland_code, RIASEC scores, report snapshots, share, PDF, or history', $report);
    }

    public function test_current_slot_contract_has_expected_aspirations_and_disagree_slot_keys(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $this->assertArrayHasKey('intro', $registry->aspirationsSlots());
        $this->assertArrayHasKey('no_score_mutation_boundary', $registry->aspirationsSlots());
        $this->assertArrayHasKey('user_not_wrong_message', $registry->disagreePathSlots());
        $this->assertArrayHasKey('feedback_no_mutation_boundary', $registry->disagreePathSlots());

        $this->assertSame('unavailable', $registry->resolveAspirationsSlot('unsupported')['content_status']);
        $this->assertSame('unavailable', $registry->resolveDisagreePathSlot('unsupported')['content_status']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function aspirationRows(): array
    {
        return $this->loadJsonl(base_path('tests/Fixtures/Riasec/aspirations_calibration_v1.zh-CN.jsonl'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function disagreeRows(): array
    {
        return $this->loadJsonl(base_path('tests/Fixtures/Riasec/disagree_path_v1.zh-CN.jsonl'));
    }

    /**
     * @return array<string,list<string>>
     */
    private function visibleRows(): array
    {
        $visible = [];
        foreach ($this->aspirationRows() as $index => $record) {
            $visible['aspirations line '.($index + 1)] = array_values(array_filter(array_merge([
                (string) $record['user_aspiration_label'],
                (string) $record['overlap_reading'],
                (string) $record['next_low_risk_experiment'],
            ], array_map('strval', (array) $record['reality_questions']))));
        }

        foreach ($this->disagreeRows() as $index => $record) {
            $visible['disagree line '.($index + 1)] = array_values(array_filter(array_merge([
                (string) $record['title'],
                (string) $record['summary'],
                (string) $record['recommended_next_action'],
            ], array_map('strval', (array) $record['questions']))));
        }

        return $visible;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadJsonl(string $path): array
    {
        $this->assertFileExists($path);

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded, 'line '.($lineNumber + 1).' must decode to an object');
            $rows[] = $decoded;
        }

        return $rows;
    }

    private function containsTerm(string $text, string $term): bool
    {
        return mb_stripos($text, $term) !== false;
    }

    private function isNegatedBoundary(string $text, string $term): bool
    {
        return mb_stripos($text, '不会'.$term) !== false
            || mb_stripos($text, '不'.$term) !== false
            || mb_stripos($text, '不能'.$term) !== false;
    }
}
