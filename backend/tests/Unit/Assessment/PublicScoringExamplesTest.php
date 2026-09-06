<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\IqBetaStandardScore;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Assessment\Scorers\RiasecScorer;
use App\Services\Psychometrics\Big5\Big5Standardizer;
use App\Services\Score\MbtiAttemptScorer;
use Tests\TestCase;

class PublicScoringExamplesTest extends TestCase
{
    public function test_eq_example_distinguishes_pomp_from_provisional_standard_score(): void
    {
        $answers = $index = [];
        foreach (['SA', 'ER', 'EM', 'RM'] as $d => $code) {
            for ($n = 1; $n <= 15; $n++) {
                $qid = $d * 15 + $n;
                $index[$qid] = ['dimension' => $code, 'direction' => $n === 1 ? -1 : 1];
                $answers[$qid] = $n === 1 ? 2 : 4;
            }
        }
        $result = (new Eq60ScorerV1NormedValidity)->score($answers, $index, [], ['server_duration_seconds' => 600]);
        $this->assertSame(60, $result['scores']['SA']['raw_sum']);
        $this->assertSame(75.0, $result['scores']['SA']['pomp']);
        $this->assertSame(113.0, $result['scores']['SA']['std_score']);
        $this->assertSame(113.0, $result['scores']['global']['std_score']);
        $this->assertSame('PROVISIONAL', $result['norms']['status']);
    }

    public function test_big5_example_uses_normal_cdf_not_linear_pomp(): void
    {
        $result = (new Big5Standardizer)->standardize(3.5, 3.0, 0.5);
        $this->assertSame(1.0, $result['z']);
        $this->assertSame(60, $result['t']);
        $this->assertSame(84, $result['pct']);
    }

    public function test_iq_beta_example_is_not_population_normed(): void
    {
        $result = (new IqBetaStandardScore)->fromRawScore(8);
        $this->assertSame(121, $result['beta_standard_score']);
        $this->assertFalse($result['production_normed']);
        $this->assertFalse($result['population_percentile_eligible']);
        $this->assertNull($result['percentile']);
    }

    public function test_riasec_raw_38_maps_to_70(): void
    {
        $answers = $index = [];
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $d => $code) {
            for ($n = 1; $n <= 10; $n++) {
                $qid = $d * 10 + $n;
                $answers[$qid] = $n <= 8 ? 4 : 3;
                $index[$qid] = ['dimension' => $code, 'subscale' => 'activity'];
            }
        }
        $result = (new RiasecScorer)->score($answers, $index, []);
        $this->assertSame(70.0, $result['score_R']);
        $this->assertSame('RIA', $result['top_code']);
        $this->assertSame(0.0, $result['clarity_index']);
    }

    public function test_mbti_example_maps_axis_sum_8_over_10_items_to_70(): void
    {
        $answers = $index = [];
        foreach (['EI' => 'E', 'SN' => 'S', 'TF' => 'T', 'JP' => 'J', 'AT' => 'A'] as $dimension => $pole) {
            for ($n = 1; $n <= 10; $n++) {
                $qid = $dimension.$n;
                $answers[] = ['question_id' => $qid, 'code' => $n <= 4 ? 'A' : 'C'];
                $index[$qid] = ['dimension' => $dimension, 'key_pole' => $pole, 'direction' => 1];
            }
        }
        $result = (new MbtiAttemptScorer)->score($answers, $index);
        $this->assertSame(70, $result['scoresPct']['EI']);
        $this->assertSame('ESTJ-A', $result['typeCode']);
    }
}
