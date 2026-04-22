<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\RiasecScorer;
use App\Services\Content\RiasecPackLoader;

final class RiasecDriver implements DriverInterface
{
    public function __construct(
        private readonly RiasecPackLoader $packLoader,
        private readonly RiasecScorer $scorer,
    ) {}

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? RiasecPackLoader::PACK_VERSION));
        if ($version === '') {
            $version = RiasecPackLoader::PACK_VERSION;
        }

        $policy = $this->packLoader->loadPolicy($version);
        $questionIndex = $this->packLoader->loadQuestionIndex($version);
        $scorePayload = $this->scorer->score($this->normalizeAnswers($answers), $questionIndex, $policy);

        $manifestHash = trim((string) ($ctx['content_manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            $manifestHash = $this->packLoader->resolveManifestHash($version);
        }

        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ($policy['scoring_spec_version'] ?? '')));
        if ($scoringSpecVersion === '') {
            $scoringSpecVersion = (string) ($scorePayload['scoring_spec_version'] ?? 'riasec_standard_60_v1');
        }

        $scorePayload['version_snapshot'] = [
            'pack_id' => (string) ($ctx['pack_id'] ?? RiasecPackLoader::PACK_ID),
            'pack_version' => $version,
            'engine_version' => (string) ($scorePayload['engine_version'] ?? ($policy['engine_version'] ?? 'riasec_v1.0.0')),
            'scoring_spec_version' => $scoringSpecVersion,
            'content_manifest_hash' => $manifestHash,
        ];

        $scores = is_array($scorePayload['scores_0_100'] ?? null) ? $scorePayload['scores_0_100'] : [];
        $rawScores = is_array($scorePayload['raw_scores'] ?? null) ? $scorePayload['raw_scores'] : [];
        $primaryType = (string) ($scorePayload['primary_type'] ?? '');
        $topCode = (string) ($scorePayload['top_code'] ?? $primaryType);
        $finalScore = (float) max(array_map('floatval', $scores !== [] ? $scores : [0.0]));

        return new ScoreResult(
            rawScore: (float) ($rawScores[$primaryType] ?? 0.0),
            finalScore: $finalScore,
            breakdownJson: [
                'score_method' => (string) ($scorePayload['score_method'] ?? 'riasec_v1'),
                'answer_count' => (int) ($scorePayload['answer_count'] ?? count($answers)),
                'top_code' => $topCode,
                'primary_type' => $primaryType,
                'top_types' => is_array($scorePayload['top_types'] ?? null) ? $scorePayload['top_types'] : [],
                'score_result' => $scorePayload,
            ],
            typeCode: $topCode,
            axisScoresJson: [
                'scores_json' => $rawScores,
                'scores_pct' => $scores,
                'axis_states' => [],
                'score_result' => $scorePayload,
            ],
            normedJson: $scorePayload,
        );
    }

    /**
     * @param  array<int|string,mixed>  $answers
     * @return array<int,int>
     */
    private function normalizeAnswers(array $answers): array
    {
        $out = [];
        foreach ($this->answerIterable($answers) as $qid => $value) {
            $questionId = (int) $qid;
            if ($questionId <= 0) {
                continue;
            }

            $out[$questionId] = (int) $value;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @param  array<int|string,mixed>  $answers
     * @return array<int|string,mixed>
     */
    private function answerIterable(array $answers): array
    {
        if ($answers === []) {
            return [];
        }

        $out = [];
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

                $out[(int) $qidRaw] = $answer['code'] ?? ($answer['value'] ?? ($answer['answer'] ?? null));
            }

            return $out;
        }

        foreach ($answers as $qidRaw => $value) {
            $qidKey = trim((string) $qidRaw);
            if ($qidKey === '' || preg_match('/^\d+$/', $qidKey) !== 1) {
                continue;
            }

            if (is_array($value)) {
                $out[(int) $qidKey] = $value['code'] ?? ($value['value'] ?? ($value['answer'] ?? null));

                continue;
            }

            $out[(int) $qidKey] = $value;
        }

        return $out;
    }
}
