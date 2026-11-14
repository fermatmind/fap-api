<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Riasec\RiasecDeepCopySlotRegistry as Registry;
use App\Services\Riasec\RiasecPrivateResultSourceRepository;
use App\Services\Riasec\RiasecPublicProjectionService;
use Tests\TestCase;

final class RiasecChineseScoreContentTest extends TestCase
{
    public function test_chinese_projection_selects_distinct_work_scenarios_and_all_six_score_cards(): void
    {
        $repository = app(RiasecPrivateResultSourceRepository::class);
        $triads = $repository->asset('top3_code_chain_strategy_v1.zh-CN.jsonl');
        $dimensions = array_column($repository->asset('dimension_deep_copy_v1.zh-CN.r3.json')['dimensions'], null, 'dimension_code');
        $pairs = array_column($repository->asset('pair_blend_15_pairs_v1.zh-CN.jsonl'), 'work_division_v1', 'pair_key');
        $seenPairs = [];
        $seenBands = [];
        $seenScenes = [];
        $ordered = array_merge(...array_column(array_column($triads, 'desktop_reading_v1'), 'ordered'));
        foreach ($ordered as $orderedCode => $expectedContent) {
            $letters = str_split($orderedCode);
            $others = array_values(array_diff(Registry::DIMENSIONS, $letters));
            $scores = array_combine([...$letters, ...$others], [90, 72.5, 57.5, 45, 30, 15]);
            // Rotate the non-leading interests to cover different score bands across fixtures.
            if ($letters[0] !== 'R') {
                [$scores[$others[0]], $scores[$others[2]]] = [$scores[$others[2]], $scores[$others[0]]];
            }
            $projection = $this->project(implode('', $letters), $scores);
            $slots = collect($projection['deep_content_slots_v1']['slots'])->groupBy('slot_key');
            $this->assertCount(1, $slots['triad_blend_copy']);
            $content = $slots['triad_blend_copy'][0]['content']['desktop_reading_v1'];
            $this->assertSame($expectedContent, $content);
            $seenScenes[] = $content['scene'];
            $this->assertCount(3, $slots['pair_blend_copy']);
            foreach ($slots['pair_blend_copy'] as $pair) {
                $key = $pair['state']['pair_key'];
                $this->assertSame($pairs[$key], $pair['content']['work_division_v1']);
                $seenPairs[$key] = true;
            }
            $this->assertCount(6, $slots['dimension_deep_copy']);
            foreach ($slots['dimension_deep_copy'] as $slot) {
                $code = $slot['selection_v1']['dimension_code'];
                $band = match (true) {
                    $scores[$code] >= 67 => 'high',
                    $scores[$code] >= 34 => 'medium',
                    default => 'low',
                };
                $source = $dimensions[$code]['atlas_score_panels'][$band];
                $this->assertSame($band, $slot['selection_v1']['score_band']);
                $reading = $slot['selection_v1']['selected_detail_key'];
                $this->assertSame($dimensions[$code][$reading], $slot['content'][$reading]);
                $atlas = $dimensions[$code]['desktop_atlas_by_decile'][min(9, (int) floor($scores[$code] / 10))];
                $this->assertSame($atlas['examples'], $slot['content']['atlas_examples']);
                $this->assertSame($atlas['next_step'], $slot['content']['atlas_next_step']);
                $this->assertSame($source['profile_focus'], $slot['content']['atlas_profile_focus']);
                $seenBands[$code][$band] = true;
            }
        }
        $this->assertCount(120, array_unique($seenScenes));
        $this->assertCount(15, $seenPairs);
        foreach (Registry::DIMENSIONS as $code) {
            $this->assertCount(3, $seenBands[$code]);
            $this->assertCount(3, array_unique(array_column($dimensions[$code]['atlas_score_panels'], 'profile_focus')));
        }
    }

    public function test_near_tie_reads_the_group_without_inventing_a_precise_order(): void
    {
        $projection = $this->project('IAS', ['R' => 45, 'I' => 80, 'A' => 67.5, 'S' => 65, 'E' => 40, 'C' => 35]);
        $slots = collect($projection['deep_content_slots_v1']['slots'])->where('slot_key', 'triad_blend_copy');
        $this->assertSame('near_tie', $projection['interpretation_state']['tie_display_v1']['kind']);
        $this->assertCount(1, $slots);
        $slot = $slots->first();
        $triads = app(RiasecPrivateResultSourceRepository::class)->asset('top3_code_chain_strategy_v1.zh-CN.jsonl');
        $expected = array_column($triads, 'desktop_reading_v1', 'unordered_top3_key')['I_A_S'];
        unset($expected['ordered']);
        $this->assertSame($expected, $slot['content']['desktop_reading_v1']);
    }

    public function test_chinese_sidebar_and_atlas_select_each_score_interval_without_changing_the_calculated_band(): void
    {
        $assets = app(RiasecPrivateResultSourceRepository::class)->asset('dimension_deep_copy_v1.zh-CN.r3.json');
        $dimensions = array_column($assets['dimensions'], null, 'dimension_code');
        $scoresByInterval = [0, 10, 20, 30, 40, 50, 60, 70, 80, 100];
        foreach ($scoresByInterval as $interval => $score) {
            $scores = array_fill_keys(Registry::DIMENSIONS, $score);
            $projection = $this->project('RIA', $scores);
            $slots = collect($projection['deep_content_slots_v1']['slots'])->where('slot_key', 'dimension_deep_copy');
            $this->assertCount(6, $slots);
            foreach ($slots as $slot) {
                $code = $slot['selection_v1']['dimension_code'];
                $band = $score >= 67 ? 'high' : ($score >= 34 ? 'medium' : 'low');
                $this->assertSame($band, $slot['selection_v1']['score_band']);
                $this->assertSame($dimensions[$code]['desktop_insight_by_decile'][$interval], $slot['content']['desktop_insight_reading']);
                $atlas = $dimensions[$code]['desktop_atlas_by_decile'][$interval];
                $this->assertSame($atlas['reading'], $slot['content']['atlas_reading']);
                $this->assertSame($atlas['examples'], $slot['content']['atlas_examples']);
                $this->assertSame($atlas['next_step'], $slot['content']['atlas_next_step']);
            }
        }

        $decimalScores = ['I' => 100, 'A' => 90, 'S' => 89.9, 'R' => 40, 'E' => 39.9, 'C' => 9.9];
        $projection = $this->project('IAS', $decimalScores);
        $slots = collect($projection['deep_content_slots_v1']['slots'])
            ->where('slot_key', 'dimension_deep_copy')->keyBy('selection_v1.dimension_code');
        foreach (['I' => 9, 'A' => 9, 'S' => 8, 'R' => 4, 'E' => 3, 'C' => 0] as $code => $index) {
            $this->assertSame($dimensions[$code]['desktop_insight_by_decile'][$index], $slots[$code]['content']['desktop_insight_reading']);
            $this->assertSame($dimensions[$code]['desktop_atlas_by_decile'][$index]['reading'], $slots[$code]['content']['atlas_reading']);
        }
    }

    private function project(string $code, array $scores): array
    {
        $result = new Result([
            'scale_code' => 'RIASEC',
            'type_code' => $code,
            'scores_pct' => $scores,
            'result_json' => [
                'top_code' => $code,
                'primary_type' => $code[0],
                'secondary_type' => $code[1],
                'tertiary_type' => $code[2],
                'form_code' => 'riasec_60',
                'answer_count' => 60,
                'scoring_spec_version' => 'riasec_60_likert5_activity_sum_space.v1',
                'scores_0_100' => $scores,
            ],
        ]);

        return app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'zh-CN', true, true);
    }
}
