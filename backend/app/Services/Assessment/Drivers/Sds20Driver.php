<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Assessment\Scorers\Sds20ScorerV2FactorLogic;
use App\Services\Content\Sds20PackLoader;
use RuntimeException;

final class Sds20Driver implements DriverInterface
{
    public function __construct(
        private readonly Sds20PackLoader $packLoader,
        private readonly Sds20ScorerV2FactorLogic $scorer,
    ) {
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? Sds20PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Sds20PackLoader::PACK_VERSION;
        }

        $questionIndex = $this->packLoader->loadQuestionIndex($version);
        $policy = $this->packLoader->loadPolicy($version);
        if ($questionIndex === [] || $policy === []) {
            throw new RuntimeException('SDS_20 pack data missing.');
        }

        $answersById = $this->normalizeAnswers($answers);
        $ctxMerged = array_merge($ctx, [
            'pack_id' => (string) ($ctx['pack_id'] ?? Sds20PackLoader::PACK_ID),
            'dir_version' => $version,
            'content_manifest_hash' => $this->packLoader->resolveManifestHash($version),
            'country' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
            'age_band' => (string) ($ctx['age_band'] ?? ''),
            'age' => isset($ctx['age']) ? (int) $ctx['age'] : 0,
        ]);

        $dto = $this->scorer->score($answersById, $questionIndex, $policy, $ctxMerged);

        $raw = (int) data_get($dto, 'scores.global.raw', 0);
        $indexScore = (int) data_get($dto, 'scores.global.index_score', 0);

        return new ScoreResult(
            rawScore: (float) $raw,
            finalScore: (float) $indexScore,
            breakdownJson: [
                'score_method' => 'sds_20_v2_factor_logic',
                'answer_count' => count($answersById),
                'score_result' => $dto,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => is_array($dto['scores'] ?? null) ? $dto['scores'] : [],
                'scores_pct' => [],
                'axis_states' => [],
                'score_result' => $dto,
            ],
            normedJson: $dto,
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

                $out[$qid] = $answer['code'] ?? ($answer['value'] ?? $answer['answer'] ?? null);
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
                $out[$qid] = $value['code'] ?? ($value['value'] ?? $value['answer'] ?? null);
                continue;
            }

            $out[$qid] = $value;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }
}
