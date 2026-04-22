<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Scorers\RiasecScorer;
use PHPUnit\Framework\TestCase;

final class RiasecScorerTest extends TestCase
{
    public function test_standard_60_scores_six_dimensions_and_top_code(): void
    {
        $answers = [];
        for ($qid = 1; $qid <= 60; $qid++) {
            $answers[$qid] = $qid <= 10 ? 5 : 1;
        }

        $result = (new RiasecScorer)->score($answers, $this->questionIndex(60), [
            'form_kind' => 'standard',
            'scoring_spec_version' => 'riasec_standard_60_v1',
        ]);

        $this->assertSame('RIA', $result['top_code']);
        $this->assertSame(100.0, $result['score_R']);
        $this->assertSame(0.0, $result['score_I']);
        $this->assertSame(0.0, $result['score_C']);
        $this->assertSame(100.0, $result['clarity_index']);
        $this->assertSame('A', $result['quality_grade']);
    }

    public function test_enhanced_140_scores_layered_breakdown_and_quality_flags(): void
    {
        $answers = [];
        for ($qid = 1; $qid <= 140; $qid++) {
            $answers[$qid] = 1;
        }
        foreach (range(1, 10) as $qid) {
            $answers[$qid] = 5;
        }
        foreach (range(61, 72) as $qid) {
            $answers[$qid] = 5;
        }
        $answers[133] = 3;
        $answers[137] = 2;
        $answers[138] = $answers[13];
        $answers[139] = $answers[31];
        $answers[140] = $answers[51];

        $result = (new RiasecScorer)->score($answers, $this->questionIndex(140), [
            'form_kind' => 'enhanced',
            'scoring_spec_version' => 'riasec_enhanced_140_v1',
        ]);

        $this->assertSame('RIA', $result['top_code']);
        $this->assertSame(100.0, $result['activity_R']);
        $this->assertSame(100.0, $result['env_R']);
        $this->assertSame(100.0, $result['role_R']);
        $this->assertSame('A', $result['quality_grade']);
        $this->assertSame([], $result['quality_flags']);
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

        if ($count >= 140) {
            foreach ($dimensions as $dimension) {
                for ($i = 0; $i < 6; $i++) {
                    $index[$qid] = ['dimension' => $dimension, 'subscale' => 'activity'];
                    $qid++;
                }
                for ($i = 0; $i < 3; $i++) {
                    $index[$qid] = ['dimension' => $dimension, 'subscale' => 'environment'];
                    $qid++;
                }
                for ($i = 0; $i < 3; $i++) {
                    $index[$qid] = ['dimension' => $dimension, 'subscale' => 'role'];
                    $qid++;
                }
            }
            for (; $qid <= 140; $qid++) {
                $index[$qid] = ['dimension' => '', 'subscale' => 'quality'];
            }
        }

        return $index;
    }
}
