<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Content\BigFivePackLoader;
use App\Services\Observability\BigFiveTelemetry;
use RuntimeException;

final class BigFiveOceanDriver implements DriverInterface
{
    public function __construct(
        private readonly BigFivePackLoader $packLoader,
        private readonly BigFiveScorerV3 $scorer,
        private readonly BigFiveTelemetry $bigFiveTelemetry,
    ) {
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? BigFivePackLoader::PACK_VERSION));
        if ($version === '') {
            $version = BigFivePackLoader::PACK_VERSION;
        }

        $compiledQuestions = $this->packLoader->readCompiledJson('questions.compiled.json', $version);
        $compiledNorms = $this->packLoader->readCompiledJson('norms.compiled.json', $version);
        $compiledPolicy = $this->packLoader->readCompiledJson('policy.compiled.json', $version);

        if (!is_array($compiledQuestions) || !is_array($compiledNorms) || !is_array($compiledPolicy)) {
            throw new RuntimeException('BIG5_OCEAN compiled pack missing. Run content:compile --pack=BIG5_OCEAN --version=' . $version);
        }

        $questionIndex = is_array($compiledQuestions['question_index'] ?? null)
            ? $compiledQuestions['question_index']
            : [];
        if (count($questionIndex) !== 120) {
            throw new RuntimeException('BIG5_OCEAN compiled question index invalid.');
        }

        $answersById = $this->normalizeAnswers($answers);
        $policy = is_array($compiledPolicy['policy'] ?? null) ? $compiledPolicy['policy'] : [];

        $dto = $this->scorer->score($answersById, $questionIndex, $compiledNorms, $policy, [
            'locale' => (string) ($ctx['locale'] ?? ''),
            'region' => (string) ($ctx['region'] ?? ''),
            'country' => (string) ($ctx['region'] ?? ''),
            'age_band' => (string) ($ctx['age_band'] ?? 'all'),
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
            'duration_ms' => (int) ($ctx['duration_ms'] ?? 0),
            'validity_items' => is_array($ctx['validity_items'] ?? null) ? $ctx['validity_items'] : [],
        ]);

        $norms = is_array($dto['norms'] ?? null) ? $dto['norms'] : [];
        $quality = is_array($dto['quality'] ?? null) ? $dto['quality'] : [];
        $this->bigFiveTelemetry->recordScored(
            (int) ($ctx['org_id'] ?? 0),
            $this->numericUserId((string) ($ctx['user_id'] ?? '')),
            (string) ($ctx['anon_id'] ?? ''),
            (string) ($ctx['attempt_id'] ?? ''),
            (string) ($ctx['locale'] ?? ''),
            (string) ($ctx['region'] ?? ''),
            (string) ($norms['status'] ?? 'MISSING'),
            (string) ($norms['group_id'] ?? ''),
            (string) ($quality['level'] ?? 'D'),
            BigFivePackLoader::PACK_ID,
            $version
        );

        $domainsPct = is_array($dto['scores_0_100']['domains_percentile'] ?? null)
            ? $dto['scores_0_100']['domains_percentile']
            : [];

        return new ScoreResult(
            rawScore: (float) array_sum(array_map('floatval', (array) ($dto['raw_scores']['domains_mean'] ?? []))),
            finalScore: (float) array_sum(array_map('floatval', (array) ($dto['scores_0_100']['domains_percentile'] ?? []))),
            breakdownJson: [
                'score_method' => 'big5_ipipneo120_v3',
                'answer_count' => count($answersById),
                'score_result' => $dto,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => [
                    'domains_mean' => $dto['raw_scores']['domains_mean'] ?? [],
                    'facets_mean' => $dto['raw_scores']['facets_mean'] ?? [],
                ],
                'scores_pct' => $domainsPct,
                'axis_states' => [],
                'score_result' => $dto,
            ],
            normedJson: $dto,
        );
    }

    /**
     * @param array<int,mixed> $answers
     * @return array<int,int>
     */
    private function normalizeAnswers(array $answers): array
    {
        $out = [];

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $qidRaw = trim((string) ($answer['question_id'] ?? ''));
            if ($qidRaw === '' || !preg_match('/^\d+$/', $qidRaw)) {
                continue;
            }

            $qid = (int) $qidRaw;
            $valueRaw = $answer['code'] ?? ($answer['value'] ?? null);
            if ($valueRaw === null) {
                continue;
            }

            $value = (int) $valueRaw;
            $out[$qid] = $value;
        }

        return $out;
    }

    private function numericUserId(string $userId): ?int
    {
        $userId = trim($userId);
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }
}
