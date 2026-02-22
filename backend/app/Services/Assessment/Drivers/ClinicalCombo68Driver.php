<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\ClinicalCombo68ScorerV1;
use App\Services\Content\ClinicalComboPackLoader;
use RuntimeException;

final class ClinicalCombo68Driver implements DriverInterface
{
    public function __construct(
        private readonly ClinicalComboPackLoader $packLoader,
        private readonly ClinicalCombo68ScorerV1 $scorer,
    ) {
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? ClinicalComboPackLoader::PACK_VERSION));
        if ($version === '') {
            $version = ClinicalComboPackLoader::PACK_VERSION;
        }

        $questionIndex = $this->packLoader->loadQuestionIndex($version);
        $optionSets = $this->packLoader->loadOptionSets($version);
        $policy = $this->packLoader->loadPolicy($version);

        if ($questionIndex === [] || $optionSets === [] || $policy === []) {
            throw new RuntimeException('CLINICAL_COMBO_68 pack data missing.');
        }

        $answersById = $this->normalizeAnswers($answers);
        $ctxMerged = array_merge($ctx, [
            'pack_id' => (string) ($ctx['pack_id'] ?? ClinicalComboPackLoader::PACK_ID),
            'dir_version' => $version,
            'content_manifest_hash' => $this->packLoader->resolveManifestHash($version),
        ]);

        $dto = $this->scorer->score($answersById, $questionIndex, $optionSets, $policy, $ctxMerged);

        $scores = is_array($dto['scores'] ?? null) ? $dto['scores'] : [];
        $rawScore = (float) (
            (int) data_get($scores, 'depression.raw', 0)
            + (int) data_get($scores, 'anxiety.raw', 0)
            + (int) data_get($scores, 'stress.raw', 0)
            + (int) data_get($scores, 'resilience.raw', 0)
            + (int) data_get($scores, 'perfectionism.raw', 0)
            + (int) data_get($scores, 'ocd.raw', 0)
        );

        $finalScore = (float) (
            (int) data_get($scores, 'depression.t_score', 0)
            + (int) data_get($scores, 'anxiety.t_score', 0)
            + (int) data_get($scores, 'stress.t_score', 0)
            + (int) data_get($scores, 'resilience.t_score', 0)
            + (int) data_get($scores, 'perfectionism.t_score', 0)
            + (int) data_get($scores, 'ocd.t_score', 0)
        );

        return new ScoreResult(
            rawScore: $rawScore,
            finalScore: $finalScore,
            breakdownJson: [
                'score_method' => 'clinical_combo_68_v1',
                'answer_count' => count($answersById),
                'score_result' => $dto,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => $scores,
                'scores_pct' => [],
                'axis_states' => [],
                'score_result' => $dto,
            ],
            normedJson: $dto,
        );
    }

    /**
     * @param array<int,mixed> $answers
     * @return array<int,mixed>
     */
    private function normalizeAnswers(array $answers): array
    {
        $out = [];

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

            $value = $answer['code'] ?? ($answer['value'] ?? ($answer['answer'] ?? null));
            if ($value === null) {
                continue;
            }

            $out[$qid] = $value;
        }

        return $out;
    }
}
