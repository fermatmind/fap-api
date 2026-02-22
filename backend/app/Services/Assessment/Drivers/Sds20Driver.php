<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Content\Sds20PackLoader;

final class Sds20Driver implements DriverInterface
{
    public function __construct(
        private readonly Sds20PackLoader $packLoader,
    ) {
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $version = trim((string) ($ctx['dir_version'] ?? Sds20PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Sds20PackLoader::PACK_VERSION;
        }

        $normalizedAnswers = $this->normalizeAnswers($answers);
        $completionTimeSeconds = (int) floor(((int) ($ctx['duration_ms'] ?? 0)) / 1000);

        $dto = [
            'scale_code' => 'SDS_20',
            'engine_version' => 'v2.0_Factor_Logic',
            'quality' => [
                'level' => 'D',
                'flags' => ['SDS20_SCORER_PENDING'],
                'crisis_alert' => false,
                'completion_time_seconds' => max(0, $completionTimeSeconds),
            ],
            'scores' => [
                'global' => [
                    'raw' => 0,
                    'index_score' => 0,
                    'clinical_level' => 'normal',
                    'percentile' => null,
                ],
                'factors' => [
                    'psycho_affective' => ['score' => 0, 'max' => 8, 'severity' => 'low'],
                    'somatic' => ['score' => 0, 'max' => 32, 'severity' => 'low'],
                    'psychomotor' => ['score' => 0, 'max' => 12, 'severity' => 'low'],
                    'cognitive' => ['score' => 0, 'max' => 28, 'severity' => 'low'],
                ],
            ],
            'report_tags' => ['engine:sds20_pr1_placeholder'],
            'version_snapshot' => [
                'pack_id' => (string) ($ctx['pack_id'] ?? Sds20PackLoader::PACK_ID),
                'pack_version' => $version,
                'policy_version' => (string) data_get($this->packLoader->loadPolicy($version), 'scoring_spec_version', ''),
                'engine_version' => 'v2.0_Factor_Logic',
                'scoring_spec_version' => 'v2.0_Factor_Logic',
                'content_manifest_hash' => (string) ($ctx['content_manifest_hash'] ?? ''),
            ],
            'answer_count' => count($normalizedAnswers),
        ];

        return new ScoreResult(
            rawScore: 0.0,
            finalScore: 0.0,
            breakdownJson: [
                'score_method' => 'sds_20_placeholder_pr1',
                'answer_count' => count($normalizedAnswers),
                'score_result' => $dto,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => [],
                'scores_pct' => [],
                'axis_states' => [],
                'score_result' => $dto,
            ],
            normedJson: $dto,
        );
    }

    /**
     * @param array<int,mixed> $answers
     * @return array<int,string>
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

            $code = strtoupper(trim((string) ($answer['code'] ?? ($answer['value'] ?? ''))));
            if (!in_array($code, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }

            $out[$qid] = $code;
        }

        ksort($out);

        return $out;
    }
}
