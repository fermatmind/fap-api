<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Scorers\CareerAssessmentScorer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CareerAssessmentScorerTest extends TestCase
{
    public function test_bilingual_questionnaire_has_balanced_axes_and_stable_unique_ids(): void
    {
        $form = (new CareerAssessmentScorer)->questionnaire();
        $counts = [];
        foreach ($form['items'] as $item) {
            $counts[$item['section']][$item['axis']] = ($counts[$item['section']][$item['axis']] ?? 0) + 1;
            foreach (['zh-CN', 'en'] as $locale) {
                self::assertNotEmpty($item['text'][$locale]);
                if ($item['section'] === 'context') {
                    foreach ($item['options'] as $option) {
                        self::assertNotEmpty($option[$locale]);
                    }
                } else {
                    self::assertCount(5, $form['anchors'][$item['section']][$locale]);
                }
            }
        }

        self::assertCount(94, $form['items']);
        self::assertCount(94, array_unique(array_column($form['items'], 'id')));
        self::assertSame(array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], 7), $counts['interest']);
        self::assertSame([4, 4, 4, 4, 4], array_values($counts['style']));
        self::assertSame([4, 4, 4, 4, 4, 4], array_values($counts['value']));
        self::assertSame(array_fill(0, 8, 1), array_values($counts['context']));
    }

    public function test_same_interest_can_prefer_different_ways_of_working(): void
    {
        $scorer = new CareerAssessmentScorer;
        $answers = $this->answers();
        $collaborative = $answers;
        // The first two items place collaboration at the right endpoint, the last two at the left.
        foreach (['043' => 1, '048' => 1, '053' => 5, '058' => 5] as $id => $response) {
            $answers['career_v1_'.$id] = $response;
            $collaborative['career_v1_'.$id] = 6 - $response;
        }
        $alone = $scorer->score('career_v1', $answers);
        $together = $scorer->score('career_v1', $collaborative);
        $independentJob = $this->job();
        $teamJob = $independentJob;
        $teamJob['style']['coordination'] = 100;

        self::assertSame(['I', 'A'], $alone['interest_shape']['highest_axes']);
        self::assertSame($alone['axes']['interest'], $together['axes']['interest']);
        self::assertSame(77.0833, $scorer->compare($alone, $teamJob)['interest']);
        self::assertSame($scorer->compare($alone, $teamJob)['interest'], $scorer->compare($together, $teamJob)['interest']);
        self::assertSame(100.0, $scorer->compare($alone, $independentJob)['style']['score']);
        self::assertSame(80.0, $scorer->compare($alone, $teamJob)['style']['score']);
        self::assertSame(80.0, $scorer->compare($together, $independentJob)['style']['score']);
        self::assertSame(100.0, $scorer->compare($together, $teamJob)['style']['score']);
    }

    public function test_values_reward_provision_and_constraints_use_explicit_facts(): void
    {
        $scorer = new CareerAssessmentScorer;
        $answers = $this->answers();
        $answers['career_v1_093'] = 'cannot';
        $answers['career_v1_094'] = 'accept';
        $result = $scorer->score('career_v1', $answers);
        $job = $this->job();
        $job['requirements'] = ['night_shifts' => true, 'travel' => true];
        $first = $scorer->compare($result, $job);

        $job['value']['predictable_hours'] = 100;
        $job['requirements']['night_shifts'] = false;
        $second = $scorer->compare($result, $job);

        self::assertSame(40.0, $first['value']['score']);
        self::assertSame(50.0, $second['value']['score']);
        self::assertSame(['night_shifts' => 'conflict', 'travel' => 'not_restricted'], $first['constraints']);
        self::assertSame('no_conflict', $second['constraints']['night_shifts']);
        unset($job['requirements']['night_shifts']);
        self::assertSame('unknown', $scorer->compare($result, $job)['constraints']['night_shifts']);
        self::assertSame('unknown', $scorer->compare($scorer->score('career_v1', $this->answers()), $job)['constraints']['travel']);
    }

    public function test_missing_and_flat_scores_never_become_fake_information(): void
    {
        $scorer = new CareerAssessmentScorer;
        $form = $scorer->questionnaire();
        $neutral = array_fill_keys(array_column(array_slice($form['items'], 0, 86), 'id'), 3);
        $flat = $scorer->score('career_v1', $neutral);

        self::assertSame(['state' => 'flat', 'range' => 0.0, 'highest_axes' => []], $flat['interest_shape']);
        self::assertNull($scorer->compare($flat, $this->job())['interest']);

        $answers = $this->answers();
        $answers['career_v1_002'] = null;
        $oneSkipped = $scorer->score('career_v1', $answers);
        self::assertSame(['score' => 100.0, 'answered' => 6, 'total' => 7], $oneSkipped['axes']['interest']['I']);
        unset($answers['career_v1_008']);
        $result = $scorer->score('career_v1', $answers);
        self::assertSame(['score' => null, 'answered' => 5, 'total' => 7], $result['axes']['interest']['I']);
        self::assertSame('incomplete', $result['interest_shape']['state']);

        $job = $this->job();
        unset($job['style']['coordination'], $job['value']['predictable_hours']);
        $partial = $scorer->compare($result, $job);
        self::assertNull($partial['interest']);
        self::assertNull($partial['style']['score']);
        self::assertNull($partial['value']['score']);
        self::assertSame(100.0, $partial['style']['dimensions']['task_switching']);
        self::assertSame(40.0, $partial['value']['dimensions']['stable_income']);
        unset($job['interest']['C']);
        self::assertNull($scorer->compare($oneSkipped, $job)['interest']);
    }

    public function test_legacy_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CareerAssessmentScorer)->score('riasec_140', []);
    }

    public function test_unknown_item_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CareerAssessmentScorer)->score('career_v1', [1 => 5]);
    }

    public function test_invalid_scale_answer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CareerAssessmentScorer)->score('career_v1', ['career_v1_001' => '3']);
    }

    public function test_invalid_context_answer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CareerAssessmentScorer)->score('career_v1', ['career_v1_093' => 5]);
    }

    private function answers(): array
    {
        $answers = [];
        foreach ((new CareerAssessmentScorer)->questionnaire()['items'] as $item) {
            if ($item['section'] === 'context') {
                continue;
            }

            $answers[$item['id']] = match (true) {
                $item['section'] !== 'interest' => 3,
                in_array($item['axis'], ['I', 'A'], true) => 5,
                default => 1,
            };
        }

        return $answers;
    }

    private function job(): array
    {
        return [
            'interest' => ['R' => 0, 'I' => 100, 'A' => 75, 'S' => 25, 'E' => 0, 'C' => 0],
            'style' => ['coordination' => 0, 'method_discretion' => 50, 'task_switching' => 50, 'external_contact' => 50, 'physical_activity' => 50],
            'value' => array_fill_keys(['predictable_hours', 'learning_support', 'stable_income', 'visible_benefit', 'advancement_path', 'flexible_location'], 40),
        ];
    }
}
