<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Assessment\Scorers\RiasecScorer;
use App\Services\Riasec\RiasecInterpretationRuleContract;
use PHPUnit\Framework\TestCase;

final class RiasecInterpretationRuleContractTest extends TestCase
{
    public function test_clear_code_emits_backend_owned_interpretation_state(): void
    {
        $state = (new RiasecInterpretationRuleContract)->build([
            'top_code' => 'RIA',
            'scores_0_100' => ['R' => 92, 'I' => 62, 'A' => 48, 'S' => 33, 'E' => 25, 'C' => 12],
        ]);

        $this->assertSame(RiasecInterpretationRuleContract::VERSION, $state['interpretation_rule_version']);
        $this->assertSame('clear_code', $state['profile_shape']);
        $this->assertSame(RiasecInterpretationRuleContract::PROFILE_SHAPE_VERSION, $state['profile_shape_version']);
        $this->assertSame('high', $state['clarity_label']);
        $this->assertSame('none', $state['near_tie_state']['state']);
        $this->assertFalse($state['alternate_code']['show']);
        $this->assertSame('high', $state['top_code_confidence']['level']);
        $this->assertSame('readability strength, not probability', $state['top_code_confidence']['meaning']);
        $this->assertSame('normal_reading', $state['reading_strength']);
        $this->assertSame('single_chain', $state['result_page_strategy']['primary_reading_mode']);
        $this->assertSame('backend_owned', $state['field_authority']['profile_shape']);
        $this->assertSame(RiasecInterpretationRuleContract::MODULE_VISIBILITY_POLICY_ID, $state['module_visibility_policy_id']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_near_tie_emits_safe_alternate_code_as_reading_aid(): void
    {
        $state = (new RiasecInterpretationRuleContract)->build([
            'top_code' => 'IAS',
            'scores_0_100' => ['I' => 80, 'A' => 78, 'S' => 72, 'C' => 40, 'R' => 25, 'E' => 20],
        ]);

        $this->assertSame('near_tie', $state['profile_shape']);
        $this->assertSame('top1_top2_near_tie', $state['near_tie_state']['state']);
        $this->assertSame(['I', 'A'], $state['near_tie_state']['dimensions']);
        $this->assertTrue($state['alternate_code']['show']);
        $this->assertSame(['AIS'], $state['alternate_code']['codes']);
        $this->assertStringContainsString('not a second measured result', $state['alternate_code']['display_boundary']);
        $this->assertSame('medium', $state['top_code_confidence']['level']);
        $this->assertSame('candidate_chains', $state['result_page_strategy']['primary_reading_mode']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_broad_profile_routes_to_activity_filter_first_before_near_tie(): void
    {
        $state = (new RiasecInterpretationRuleContract)->build([
            'scores_0_100' => ['R' => 58, 'I' => 57, 'A' => 56, 'S' => 55, 'E' => 54, 'C' => 53],
        ]);

        $this->assertSame('broad_profile', $state['profile_shape']);
        $this->assertSame('multi_near_tie', $state['near_tie_state']['state']);
        $this->assertSame('activity_filter_first', $state['result_page_strategy']['primary_reading_mode']);
        $this->assertSame('cautious_reading', $state['reading_strength']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_low_quality_suppresses_strong_interpretation_fields(): void
    {
        $state = (new RiasecInterpretationRuleContract)->build([
            'top_code' => 'RIA',
            'scores_0_100' => ['R' => 90, 'I' => 65, 'A' => 45, 'S' => 30, 'E' => 20, 'C' => 10],
        ], [
            'quality_state' => 'low_quality',
        ]);

        $this->assertSame('low_quality', $state['profile_shape']);
        $this->assertSame('not_readable', $state['clarity_label']);
        $this->assertSame('none', $state['near_tie_state']['state']);
        $this->assertFalse($state['alternate_code']['show']);
        $this->assertSame('not_available', $state['top_code_confidence']['level']);
        $this->assertSame('retake_recommended', $state['reading_strength']);
        $this->assertSame('retake_first', $state['result_page_strategy']['primary_reading_mode']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_contract_does_not_mutate_scorer_payload_or_scoring_math(): void
    {
        $answers = [];
        for ($qid = 1; $qid <= 60; $qid++) {
            $answers[$qid] = $qid <= 10 ? 5 : 1;
        }

        $payload = (new RiasecScorer)->score($answers, $this->questionIndex(60), [
            'form_kind' => 'standard',
            'scoring_spec_version' => 'riasec_standard_60_v1',
        ]);
        $before = $payload;
        $state = (new RiasecInterpretationRuleContract)->build($payload);

        $this->assertSame($before, $payload);
        $this->assertSame('RIA', $payload['top_code']);
        $this->assertSame(100.0, $payload['score_R']);
        $this->assertSame(0.0, $payload['score_I']);
        $this->assertSame(100.0, $payload['clarity_index']);
        $this->assertContains($state['profile_shape'], ['clear_code', 'near_tie']);
        $this->assertNoForbiddenClaims($state);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function questionIndex(int $count): array
    {
        $dimensions = ['R', 'I', 'A', 'S', 'E', 'C'];
        $index = [];
        $qid = 1;
        foreach ($dimensions as $dimension) {
            for ($i = 0; $i < 10; $i++) {
                $index[$qid] = ['dimension' => $dimension, 'subscale' => 'activity'];
                $qid++;
            }
        }

        for (; $qid <= $count; $qid++) {
            $index[$qid] = ['dimension' => '', 'subscale' => 'quality'];
        }

        return $index;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoForbiddenClaims(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $forbidden = [
            'Matches',
            'career match',
            'occupation match',
            'job fit',
            'fit score',
            'success prediction',
            'best career',
            'recommended career',
            '适合度',
            '匹配度',
            '最适合',
            '职业成功',
            '岗位匹配',
            'more accurate',
            '更准确',
            'raw delta',
            'score increased',
            'score decreased',
            '140Q more accurate',
            '60Q wrong',
            'AI-generated formal report',
        ];

        foreach ($forbidden as $phrase) {
            $this->assertStringNotContainsString($phrase, $json);
        }
    }
}
