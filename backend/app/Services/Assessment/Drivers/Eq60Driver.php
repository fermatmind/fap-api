<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Content\Eq60PackLoader;
use RuntimeException;

final class Eq60Driver implements DriverInterface
{
    public function __construct(
        private readonly Eq60PackLoader $packLoader,
    ) {}

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? Eq60PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Eq60PackLoader::PACK_VERSION;
        }

        $questionIndex = $this->packLoader->loadQuestionIndex($version);
        $options = $this->packLoader->loadOptions($version);
        $policy = $this->packLoader->loadPolicy($version);

        $scoreMap = is_array($options['score_map'] ?? null) ? $options['score_map'] : [];
        if ($questionIndex === [] || $scoreMap === []) {
            throw new RuntimeException('EQ_60 pack data missing.');
        }

        $answersById = $this->normalizeAnswers($answers);
        if (count($answersById) !== count($questionIndex)) {
            throw new \InvalidArgumentException('EQ_60 requires exactly 60 answers.');
        }

        $dimScores = [
            'SA' => 0,
            'ER' => 0,
            'SE' => 0,
            'RM' => 0,
        ];
        $rawTotal = 0;
        $resolvedTotal = 0;

        foreach ($questionIndex as $qid => $meta) {
            if (!array_key_exists($qid, $answersById)) {
                throw new \InvalidArgumentException('missing answer for question_id=' . $qid);
            }

            $code = strtoupper(trim((string) $answersById[$qid]));
            if ($code === '' || !array_key_exists($code, $scoreMap)) {
                throw new \InvalidArgumentException('invalid answer code for question_id=' . $qid);
            }

            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            if (!array_key_exists($dimension, $dimScores)) {
                throw new \InvalidArgumentException('invalid question dimension for question_id=' . $qid);
            }

            $direction = (int) ($meta['direction'] ?? 1);
            if (!in_array($direction, [1, -1], true)) {
                throw new \InvalidArgumentException('invalid question direction for question_id=' . $qid);
            }

            $raw = (int) $scoreMap[$code];
            $resolved = $direction === -1 ? 6 - $raw : $raw;

            $rawTotal += $raw;
            $resolvedTotal += $resolved;
            $dimScores[$dimension] += $resolved;
        }

        $manifestHash = trim((string) ($ctx['content_manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            $manifestHash = $this->packLoader->resolveManifestHash($version);
        }

        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ($policy['scoring_spec_version'] ?? 'eq60_spec_2026_v1')));
        if ($scoringSpecVersion === '') {
            $scoringSpecVersion = 'eq60_spec_2026_v1';
        }

        $dimPct = [];
        foreach ($dimScores as $dimension => $score) {
            $dimPct[$dimension] = round(($score - 15) / 60 * 100, 2);
        }

        $scoreResult = [
            'scale_code' => 'EQ_60',
            'engine_version' => 'eq60_likert_v1',
            'version_snapshot' => [
                'pack_id' => (string) ($ctx['pack_id'] ?? Eq60PackLoader::PACK_ID),
                'pack_version' => $version,
                'policy_version' => trim((string) ($policy['engine_version'] ?? 'eq60_likert_v1')),
                'scoring_spec_version' => $scoringSpecVersion,
                'content_manifest_hash' => $manifestHash,
            ],
            'dim_scores' => $dimScores,
            'total_score' => $resolvedTotal,
            'raw_total_before_reverse' => $rawTotal,
            'answer_count' => count($answersById),
        ];

        return new ScoreResult(
            rawScore: (float) $resolvedTotal,
            finalScore: (float) $resolvedTotal,
            breakdownJson: [
                'score_method' => 'eq60_likert_v1',
                'answer_count' => count($answersById),
                'dim_scores' => $dimScores,
                'total_score' => $resolvedTotal,
                'raw_total_before_reverse' => $rawTotal,
                'score_result' => $scoreResult,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => [
                    'dim_scores' => $dimScores,
                    'total_score' => $resolvedTotal,
                    'raw_total_before_reverse' => $rawTotal,
                ],
                'scores_pct' => array_merge($dimPct, [
                    'TOTAL' => round(($resolvedTotal - 60) / 240 * 100, 2),
                ]),
                'axis_states' => [],
                'score_result' => $scoreResult,
            ],
            normedJson: $scoreResult,
        );
    }

    /**
     * @param array<int|string,mixed> $answers
     * @return array<int,mixed>
     */
    private function normalizeAnswers(array $answers): array
    {
        $out = [];

        if ($answers === []) {
            return $out;
        }

        $isList = array_keys($answers) === range(0, count($answers) - 1);
        if ($isList) {
            foreach ($answers as $answer) {
                if (!is_array($answer)) {
                    continue;
                }

                $qidRaw = trim((string) ($answer['question_id'] ?? ''));
                if ($qidRaw === '' || preg_match('/^\d+$/', $qidRaw) !== 1) {
                    continue;
                }

                $qid = (int) $qidRaw;
                if ($qid <= 0) {
                    continue;
                }

                $out[$qid] = $answer['code'] ?? ($answer['value'] ?? ($answer['answer'] ?? null));
            }

            ksort($out, SORT_NUMERIC);

            return $out;
        }

        foreach ($answers as $qidRaw => $value) {
            $qidKey = trim((string) $qidRaw);
            if ($qidKey === '' || preg_match('/^\d+$/', $qidKey) !== 1) {
                continue;
            }

            $qid = (int) $qidKey;
            if ($qid <= 0) {
                continue;
            }

            if (is_array($value)) {
                $out[$qid] = $value['code'] ?? ($value['value'] ?? ($value['answer'] ?? null));
                continue;
            }

            $out[$qid] = $value;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }
}
