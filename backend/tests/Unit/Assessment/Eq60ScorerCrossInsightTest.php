<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Tests\TestCase;

final class Eq60ScorerCrossInsightTest extends TestCase
{
    public function test_overthinking_burn_tag_is_triggered_for_high_sa_low_er_profile(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $policy = $loader->loadPolicy('v1');
        $answers = $this->buildAnswersByDimensionTargets($questionIndex, [
            'SA' => 5,
            'ER' => 1,
            'EM' => 3,
            'RM' => 3,
        ]);

        $result = $scorer->score(
            $answers,
            $questionIndex,
            $policy,
            [
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 420,
            ]
        );

        $tags = (array) data_get($result, 'report_tags', []);
        $this->assertContains('profile:overthinking_burn', $tags);
        $this->assertContains('focus:regulation_skills', $tags);
        $this->assertGreaterThanOrEqual(108.0, (float) data_get($result, 'scores.SA.std_score', 0.0));
        $this->assertLessThanOrEqual(92.0, (float) data_get($result, 'scores.ER.std_score', 999.0));
        $this->assertSame(75, (int) data_get($result, 'scores.SA.raw_sum', 0));
        $this->assertSame(15, (int) data_get($result, 'scores.ER.raw_sum', 0));
    }

    /**
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,int>  $resolvedTargetByDimension
     * @return array<int,int>
     */
    private function buildAnswersByDimensionTargets(array $questionIndex, array $resolvedTargetByDimension): array
    {
        $answers = [];
        foreach ($questionIndex as $qid => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $questionId = (int) $qid;
            if ($questionId <= 0) {
                continue;
            }

            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            $direction = (int) ($meta['direction'] ?? 1);
            $target = (int) ($resolvedTargetByDimension[$dimension] ?? 3);
            if ($target < 1 || $target > 5) {
                $target = 3;
            }

            $answers[$questionId] = $direction === -1 ? (6 - $target) : $target;
        }

        ksort($answers, SORT_NUMERIC);

        return $answers;
    }
}
