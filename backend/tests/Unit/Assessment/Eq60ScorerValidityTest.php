<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Tests\TestCase;

final class Eq60ScorerValidityTest extends TestCase
{
    public function test_speeding_and_neutral_bias_downgrade_quality_to_d(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $result = $scorer->score(
            $this->buildAnswersWithCode('C'),
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            [
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 60,
            ]
        );

        $this->assertSame('D', (string) data_get($result, 'quality.level'));
        $this->assertContains('SPEEDING', (array) data_get($result, 'quality.flags', []));
        $this->assertContains('NEUTRAL_RESPONSE_BIAS', (array) data_get($result, 'quality.flags', []));
        $this->assertSame(1.0, (float) data_get($result, 'quality.metrics.neutral_rate', -1));
    }

    public function test_inconsistency_index_flag_is_emitted(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $policy = $loader->loadPolicy('v1');
        $answers = $this->buildAnswersForInconsistency($questionIndex, (array) ($policy['inconsistency_pairs'] ?? []));

        $result = $scorer->score(
            $answers,
            $questionIndex,
            $policy,
            [
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 360,
            ]
        );

        $this->assertContains('INCONSISTENT', (array) data_get($result, 'quality.flags', []));
        $this->assertSame('D', (string) data_get($result, 'quality.level'));
        $this->assertGreaterThanOrEqual(24, (int) data_get($result, 'quality.metrics.inconsistency_index', 0));
    }

    /**
     * @return array<int,string>
     */
    private function buildAnswersWithCode(string $code): array
    {
        $answers = [];
        $normalized = strtoupper(trim($code));
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = $normalized;
        }

        return $answers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<int,mixed>  $pairs
     * @return array<int,int>
     */
    private function buildAnswersForInconsistency(array $questionIndex, array $pairs): array
    {
        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = $this->rawValueForResolvedTarget($questionIndex, $i, 3);
        }

        foreach ($pairs as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }

            $qidA = (int) ($pair[0] ?? 0);
            $qidB = (int) ($pair[1] ?? 0);
            if ($qidA <= 0 || $qidB <= 0) {
                continue;
            }

            $answers[$qidA] = $this->rawValueForResolvedTarget($questionIndex, $qidA, 5);
            $answers[$qidB] = $this->rawValueForResolvedTarget($questionIndex, $qidB, 1);
        }

        return $answers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $questionIndex
     */
    private function rawValueForResolvedTarget(array $questionIndex, int $qid, int $resolvedTarget): int
    {
        $direction = (int) data_get($questionIndex, $qid.'.direction', 1);

        return $direction === -1 ? (6 - $resolvedTarget) : $resolvedTarget;
    }
}
