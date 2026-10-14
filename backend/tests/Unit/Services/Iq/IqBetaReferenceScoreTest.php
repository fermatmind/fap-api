<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Iq;

use App\Services\Iq\IqBetaReferenceScore;
use App\Services\Iq\IqResultPayloadRedactor;
use PHPUnit\Framework\TestCase;

final class IqBetaReferenceScoreTest extends TestCase
{
    public function test_all_31_approved_rows_use_unrounded_cdf_and_half_up_display(): void
    {
        $scores = [73, 85, 89, 93, 96, 100, 102, 104, 106, 108, 110, 112, 114, 116, 118, 120, 122, 124, 126, 128, 130, 132, 133, 135, 136, 138, 139, 141, 142, 144, 145];
        $percentiles = [3.59, 15.87, 22.66, 30.85, 40.13, 50.00, 55.30, 60.51, 65.54, 70.31, 74.75, 78.81, 82.47, 85.69, 88.49, 90.88, 92.88, 94.52, 95.85, 96.90, 97.72, 98.21, 98.61, 98.93, 99.18, 99.38, 99.53, 99.65, 99.74, 99.81, 99.87];
        $previous = 0;
        foreach ($scores as $raw => $expected) {
            $reference = (new IqBetaReferenceScore)->fromScore($this->score($raw));
            $this->assertSame($expected, $reference['display_score'], 'raw='.$raw);
            $this->assertEqualsWithDelta($percentiles[$raw], round($reference['percentile'], 2), 0.001, 'raw='.$raw);
            $this->assertGreaterThan($previous, $reference['display_score']);
            $this->assertFalse($reference['claim_eligible']);
            $this->assertFalse($reference['population_percentile_eligible']);
            $this->assertSame(IqBetaReferenceScore::MODEL_VERSION, $reference['model_version']);
            $previous = $expected;
        }
        $this->assertSame(92.5, (new IqBetaReferenceScore)->fromScore($this->score(3))['standard_score']);
    }

    public function test_blocked_partial_invalid_and_other_banks_are_not_real_zero(): void
    {
        $service = new IqBetaReferenceScore;
        foreach ([['status' => 'blocked_unscored'], ['technical_failure' => true], ['bank_id' => 'IQ_BETA_50'], ['raw_score' => -1], ['raw_score' => 31], ['raw_score' => 1.5], ['raw_score' => NAN], ['raw_score' => INF], ['raw_score' => '6'], ['expected_item_count' => 3], ['answer_count' => 29]] as $override) {
            $this->assertNull($service->fromScore(array_merge($this->score(0), $override)));
        }
        $this->assertSame(73, $service->fromScore($this->score(0))['display_score']);
        $score = array_merge($this->score(6), ['quality' => ['flags' => ['SPEEDING']]]);
        $this->assertSame(102, $service->fromScore($score)['display_score']);
    }

    public function test_read_interpretation_preserves_legacy_values_and_redaction(): void
    {
        $stored = ['result' => ['normed_json' => array_merge($this->score(6), ['beta_standard_score' => 107, 'answer_key' => ['IQ001' => 'A']])]];
        $public = IqResultPayloadRedactor::redactAnswerKeys($stored);
        $this->assertSame(107, $public['result']['normed_json']['beta_standard_score']);
        $this->assertSame(102, $public['result']['normed_json']['beta_reference']['display_score']);
        $this->assertArrayNotHasKey('answer_key', $public['result']['normed_json']);
        $this->assertArrayNotHasKey('beta_reference', $stored['result']['normed_json']);
        $frozen = array_merge($this->score(6), ['beta_reference' => null]);
        $this->assertSame($frozen, (new IqBetaReferenceScore)->withCurrentInterpretation($frozen));
    }

    public function test_full_legacy_report_gets_additive_interpretation_but_redacted_report_does_not(): void
    {
        $report = ['schema_version' => 'iq.report.v1', 'summary' => ['raw_score' => 6], 'scoring' => ['status' => 'scored', 'bank_id' => IqBetaReferenceScore::BANK_ID], 'access' => ['report_access_level' => 'full']];
        $this->assertSame(102, IqResultPayloadRedactor::redactAnswerKeys($report)['beta_reference']['display_score']);
        $report['access']['report_access_level'] = 'free';
        $this->assertArrayNotHasKey('beta_reference', IqResultPayloadRedactor::redactAnswerKeys($report));
    }

    private function score(int $raw): array
    {
        return ['status' => 'scored', 'bank_id' => IqBetaReferenceScore::BANK_ID, 'raw_score' => $raw, 'expected_item_count' => 30, 'answer_count' => 30];
    }
}
