<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\EnneagramForcedChoice144Scorer;
use App\Services\Assessment\Scorers\EnneagramLikert105Scorer;
use App\Services\Content\EnneagramPackLoader;

final class EnneagramDriver implements DriverInterface
{
    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
        private readonly EnneagramLikert105Scorer $likert105Scorer,
        private readonly EnneagramForcedChoice144Scorer $forcedChoice144Scorer,
    ) {}

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? EnneagramPackLoader::PACK_VERSION));
        if ($version === '') {
            $version = EnneagramPackLoader::PACK_VERSION;
        }

        $policy = $this->packLoader->loadPolicy($version);
        $questionIndex = $this->packLoader->loadQuestionIndex($version);
        $formCode = trim((string) ($policy['form_code'] ?? ($ctx['form_code'] ?? '')));
        $scoringMode = trim((string) ($policy['scoring_mode'] ?? ''));

        if ($formCode === 'enneagram_forced_choice_144' || $scoringMode === 'forced_choice_pair') {
            $scorePayload = $this->forcedChoice144Scorer->score(
                $this->normalizeChoiceAnswers($answers),
                $questionIndex,
                $policy
            );
        } else {
            $scorePayload = $this->likert105Scorer->score(
                $this->normalizeLikertAnswers($answers),
                $questionIndex,
                $policy
            );
        }

        $manifestHash = trim((string) ($ctx['content_manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            $manifestHash = $this->packLoader->resolveManifestHash($version);
        }

        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ($policy['scoring_spec_version'] ?? '')));
        if ($scoringSpecVersion === '') {
            $scoringSpecVersion = (string) ($scorePayload['scoring_spec_version'] ?? 'enneagram_spec_v1');
        }

        $scorePayload['version_snapshot'] = [
            'pack_id' => (string) ($ctx['pack_id'] ?? EnneagramPackLoader::PACK_ID),
            'pack_version' => $version,
            'engine_version' => (string) ($scorePayload['engine_version'] ?? ($policy['engine_version'] ?? 'enneagram_v1.0.0')),
            'scoring_spec_version' => $scoringSpecVersion,
            'content_manifest_hash' => $manifestHash,
        ];

        $scoresJson = is_array($scorePayload['raw_scores'] ?? null) ? $scorePayload['raw_scores'] : [];
        $scoresPct = is_array($scorePayload['scores_0_100'] ?? null) ? $scorePayload['scores_0_100'] : [];
        $finalScore = (float) max(array_map('floatval', $scoresPct !== [] ? $scoresPct : [0.0]));
        $rawScore = $this->resolvePrimaryRawScore($scorePayload);

        return new ScoreResult(
            rawScore: $rawScore,
            finalScore: $finalScore,
            breakdownJson: [
                'score_method' => (string) ($scorePayload['score_method'] ?? 'enneagram_v1'),
                'answer_count' => (int) ($scorePayload['answer_count'] ?? count($answers)),
                'primary_type' => (string) ($scorePayload['primary_type'] ?? ''),
                'top_types' => is_array($scorePayload['top_types'] ?? null) ? $scorePayload['top_types'] : [],
                'score_result' => $scorePayload,
            ],
            typeCode: (string) ($scorePayload['primary_type'] ?? ''),
            axisScoresJson: [
                'scores_json' => $scoresJson,
                'scores_pct' => $scoresPct,
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
    private function normalizeLikertAnswers(array $answers): array
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
     * @return array<int,string>
     */
    private function normalizeChoiceAnswers(array $answers): array
    {
        $out = [];
        foreach ($this->answerIterable($answers) as $qid => $value) {
            $questionId = (int) $qid;
            if ($questionId <= 0) {
                continue;
            }

            $out[$questionId] = strtoupper(trim((string) $value));
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

    /**
     * @param  array<string,mixed>  $scorePayload
     */
    private function resolvePrimaryRawScore(array $scorePayload): float
    {
        $primaryType = trim((string) ($scorePayload['primary_type'] ?? ''));
        if ($primaryType === '') {
            return 0.0;
        }

        $rawScores = is_array($scorePayload['raw_scores'] ?? null) ? $scorePayload['raw_scores'] : [];
        $intensity = is_array($rawScores['raw_intensity'] ?? null) ? $rawScores['raw_intensity'] : [];
        if (array_key_exists($primaryType, $intensity)) {
            return (float) $intensity[$primaryType];
        }

        $counts = is_array($rawScores['type_counts'] ?? null) ? $rawScores['type_counts'] : [];

        return (float) ($counts[$primaryType] ?? 0.0);
    }
}
