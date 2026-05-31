<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Scorers\EqSjt16Scorer;
use InvalidArgumentException;
use Tests\TestCase;

final class EqSjt16ScorerTest extends TestCase
{
    public function test_golden_cases_match_scorer_output(): void
    {
        $scorer = new EqSjt16Scorer;
        $items = $this->items();
        $goldenCases = $this->jsonFile(base_path('content_packs/EQ_SJT_16/v1/raw/golden_cases.json'));

        foreach ((array) ($goldenCases['cases'] ?? []) as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            $expected = (array) ($case['expected'] ?? []);
            $result = $scorer->score((array) ($case['answers'] ?? []), $items, [
                'scoring_spec_version' => 'eq_sjt_16_partial_credit_v1',
                'content_version' => 'EQ_SJT_16/v1',
            ]);

            $this->assertSame('EQ_SJT_16', $result['scale_code'] ?? null, $caseId);
            $this->assertSame('scenario_based_emotional_judgment', $result['measurement_type'] ?? null, $caseId);
            $this->assertSame('likely_response', $result['answer_mode'] ?? null, $caseId);
            $this->assertSame(16, (int) ($result['answer_count'] ?? 0), $caseId);
            $this->assertSame(16, (int) ($result['scored_item_count'] ?? 0), $caseId);
            $this->assertSame((float) ($expected['raw_score'] ?? -1), (float) ($result['raw_score'] ?? -2), $caseId);
            $this->assertSame((float) ($expected['score_pct'] ?? -1), (float) ($result['score_pct'] ?? -2), $caseId);
            $this->assertSame($expected['band'] ?? null, $result['band'] ?? null, $caseId);
            $this->assertSame($expected['top_strategy'] ?? null, $result['top_strategy'] ?? null, $caseId);
            $this->assertSame($expected['lowest_strategy'] ?? null, $result['lowest_strategy'] ?? null, $caseId);
            $this->assertSame($expected['quality_level'] ?? null, data_get($result, 'quality.level'), $caseId);
            $this->assertSame($expected['quality_flags'] ?? [], data_get($result, 'quality.flags'), $caseId);
        }
    }

    public function test_strategy_scores_are_separate_from_overall_score(): void
    {
        $scorer = new EqSjt16Scorer;
        $goldenCases = $this->jsonFile(base_path('content_packs/EQ_SJT_16/v1/raw/golden_cases.json'));
        $boundaryGap = collect((array) ($goldenCases['cases'] ?? []))
            ->firstWhere('case_id', 'boundary_gap');

        $result = $scorer->score((array) ($boundaryGap['answers'] ?? []), $this->items());

        $this->assertSame(75.0, (float) ($result['score_pct'] ?? 0));
        $this->assertSame(16.67, (float) data_get($result, 'strategy_scores.BND.score_pct'));
        $this->assertSame('BND', $result['lowest_strategy'] ?? null);
        $this->assertSame('effective', $result['band'] ?? null);
    }

    public function test_scorer_rejects_missing_answers_and_invalid_options(): void
    {
        $scorer = new EqSjt16Scorer;
        $items = $this->items();
        $answers = array_fill_keys(array_keys($items), 'A');

        unset($answers['constructive_influence_02']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required items');
        $scorer->score($answers, $items);
    }

    public function test_scorer_rejects_invalid_option_code(): void
    {
        $scorer = new EqSjt16Scorer;
        $items = $this->items();
        $answers = array_fill_keys(array_keys($items), 'A');
        $answers['emotion_cue_reading_01'] = 'Z';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid option Z');
        $scorer->score($answers, $items);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function items(): array
    {
        $payload = $this->jsonFile(base_path('content_packs/EQ_SJT_16/v1/raw/items.json'));
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $this->assertIsArray($item);
            $itemId = (string) ($item['item_id'] ?? '');
            $this->assertNotSame('', $itemId);
            $items[$itemId] = $item;
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
