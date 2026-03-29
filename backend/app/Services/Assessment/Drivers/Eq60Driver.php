<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use RuntimeException;

final class Eq60Driver implements DriverInterface
{
    public function __construct(
        private readonly Eq60PackLoader $packLoader,
        private readonly Eq60ScorerV1NormedValidity $scorer,
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

        $scorePayload = $this->scorer->score(
            $answersById,
            $questionIndex,
            $policy,
            array_merge($ctx, [
                'pack_id' => (string) ($ctx['pack_id'] ?? Eq60PackLoader::PACK_ID),
                'dir_version' => $version,
                'score_map' => $scoreMap,
            ])
        );

        $manifestHash = trim((string) ($ctx['content_manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            $manifestHash = $this->packLoader->resolveManifestHash($version);
        }

        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ($policy['scoring_spec_version'] ?? 'eq60_spec_2026_v2')));
        if ($scoringSpecVersion === '') {
            $scoringSpecVersion = 'eq60_spec_2026_v2';
        }

        $dimScores = [
            'SA' => (int) data_get($scorePayload, 'scores.SA.raw_sum', 0),
            'ER' => (int) data_get($scorePayload, 'scores.ER.raw_sum', 0),
            'EM' => (int) data_get($scorePayload, 'scores.EM.raw_sum', 0),
            'RM' => (int) data_get($scorePayload, 'scores.RM.raw_sum', 0),
        ];
        $resolvedTotal = (int) data_get($scorePayload, 'scores.global.raw_sum', array_sum($dimScores));
        $rawTotal = array_sum($dimScores);

        $dimPct = [];
        foreach ($dimScores as $dimension => $score) {
            $dimPct[$dimension] = round(($score - 15) / 60 * 100, 2);
        }

        $scoreResult = [
            'scale_code' => (string) ($scorePayload['scale_code'] ?? 'EQ_60'),
            'engine_version' => (string) ($scorePayload['engine_version'] ?? 'v1.0_normed_validity'),
            'version_snapshot' => array_merge(
                [
                    'pack_id' => (string) ($ctx['pack_id'] ?? Eq60PackLoader::PACK_ID),
                    'pack_version' => $version,
                    'policy_version' => $scoringSpecVersion,
                    'scoring_spec_version' => $scoringSpecVersion,
                    'content_manifest_hash' => $manifestHash,
                ],
                is_array($scorePayload['version_snapshot'] ?? null) ? $scorePayload['version_snapshot'] : []
            ),
            'dim_scores' => $dimScores,
            'total_score' => $resolvedTotal,
            'raw_total_before_reverse' => $rawTotal,
            'answer_count' => count($answersById),
            'quality' => is_array($scorePayload['quality'] ?? null) ? $scorePayload['quality'] : [],
            'norms' => is_array($scorePayload['norms'] ?? null) ? $scorePayload['norms'] : [],
            'scores' => is_array($scorePayload['scores'] ?? null) ? $scorePayload['scores'] : [],
            'report_tags' => array_values(array_filter(
                array_map('strval', (array) ($scorePayload['report_tags'] ?? [])),
                static fn (string $tag): bool => $tag !== ''
            )),
        ];

        return new ScoreResult(
            rawScore: (float) $resolvedTotal,
            finalScore: (float) $resolvedTotal,
            breakdownJson: [
                'score_method' => 'v1.0_normed_validity',
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
                    'scores' => is_array($scorePayload['scores'] ?? null) ? $scorePayload['scores'] : [],
                ],
                'scores_pct' => array_merge($dimPct, [
                    'TOTAL' => round(($resolvedTotal - 60) / 240 * 100, 2),
                ]),
                'axis_states' => [],
                'score_result' => $scoreResult,
            ],
            normedJson: $scorePayload,
        );
    }

    /**
     * @param  array<int|string,mixed>  $answers
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
                if (! is_array($answer)) {
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
