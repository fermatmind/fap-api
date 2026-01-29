<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Score\MbtiAttemptScorer;
use RuntimeException;

class MbtiDriver implements DriverInterface
{
    public function __construct(private MbtiAttemptScorer $scorer)
    {
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $questionsDoc = $ctx['questions'] ?? null;
        if (!is_array($questionsDoc)) {
            throw new RuntimeException('MBTI questions missing');
        }

        $items = $questionsDoc['items'] ?? null;
        if (!is_array($items)) {
            throw new RuntimeException('MBTI questions.items missing');
        }

        $defaultScoreMap = $spec['score_map'] ?? null;
        if (!is_array($defaultScoreMap)) {
            $defaultScoreMap = [];
        }

        $index = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qid = trim((string) ($item['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }

            $scoreMap = [];
            $options = $item['options'] ?? null;
            if (is_array($options)) {
                foreach ($options as $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $code = strtoupper((string) ($opt['code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    $scoreMap[$code] = (int) ($opt['score'] ?? 0);
                }
            }

            if (empty($scoreMap) && !empty($defaultScoreMap)) {
                foreach ($defaultScoreMap as $k => $v) {
                    $code = strtoupper((string) $k);
                    if ($code === '') {
                        continue;
                    }
                    $scoreMap[$code] = (int) $v;
                }
            }

            $index[$qid] = [
                'dimension' => $item['dimension'] ?? null,
                'key_pole' => $item['key_pole'] ?? null,
                'direction' => $item['direction'] ?? 1,
                'score_map' => $scoreMap,
            ];
        }

        $scored = $this->scorer->score($answers, $index);
        $scoresPct = $scored['scoresPct'] ?? [];
        $axisStates = $scored['axisStates'] ?? [];
        $scoresJson = $scored['scoresJson'] ?? [];
        $typeCode = $scored['typeCode'] ?? null;

        $answerCount = count($answers);
        $breakdown = [
            'score_method' => 'mbti_axes',
            'answer_count' => $answerCount,
            'time_bonus' => 0,
        ];

        $axisScores = [
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'scores_json' => $scoresJson,
        ];

        return new ScoreResult(
            (float) $answerCount,
            (float) $answerCount,
            $breakdown,
            is_string($typeCode) ? $typeCode : null,
            $axisScores,
            null
        );
    }
}
