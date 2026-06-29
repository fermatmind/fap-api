<?php

declare(strict_types=1);

namespace App\Services\Assessment;

final class IqBetaStandardScore
{
    public const STATUS_SIMULATION_CALIBRATED_BETA = 'simulation_calibrated_beta';

    public const STATUS_INVALID_RAW_SCORE = 'invalid_raw_score';

    public const SOURCE = 'IQ_OWNER_ORIGINAL_30_RANDOM_BASELINE_STANDARD_SCORE_V1';

    public const SOURCE_KIND = 'random_simulation_baseline';

    public const SOURCE_REF = 'iq-owner-30-random-simulation-500-for-gpt.md';

    public const RANDOM_BASELINE_MEAN = 5.096;

    public const RANDOM_BASELINE_SD = 2.034;

    private const MIN_RAW_SCORE = 0.0;

    private const MAX_RAW_SCORE = 30.0;

    private const MIN_STANDARD_SCORE = 55;

    private const MAX_STANDARD_SCORE = 145;

    /**
     * @return array{
     *   beta_standard_score:?int,
     *   beta_standard_score_status:string,
     *   beta_standard_score_source:string,
     *   random_baseline_mean:float,
     *   random_baseline_sd:float,
     *   random_baseline_z:?float,
     *   above_random_baseline:?bool,
     *   production_normed:bool,
     *   claim_eligible:bool,
     *   population_percentile_eligible:bool,
     *   percentile:null,
     *   source_kind:string,
     *   source_ref:string
     * }
     */
    public function fromRawScore(float|int $rawScore): array
    {
        $rawScore = (float) $rawScore;
        $base = [
            'beta_standard_score' => null,
            'beta_standard_score_status' => self::STATUS_INVALID_RAW_SCORE,
            'beta_standard_score_source' => self::SOURCE,
            'random_baseline_mean' => self::RANDOM_BASELINE_MEAN,
            'random_baseline_sd' => self::RANDOM_BASELINE_SD,
            'random_baseline_z' => null,
            'above_random_baseline' => null,
            'production_normed' => false,
            'claim_eligible' => false,
            'population_percentile_eligible' => false,
            'percentile' => null,
            'source_kind' => self::SOURCE_KIND,
            'source_ref' => self::SOURCE_REF,
        ];

        if ($rawScore < self::MIN_RAW_SCORE || $rawScore > self::MAX_RAW_SCORE) {
            return $base;
        }

        $zScore = ($rawScore - self::RANDOM_BASELINE_MEAN) / self::RANDOM_BASELINE_SD;
        $standardScore = (int) round(100 + (15 * $zScore));

        $base['beta_standard_score'] = max(
            self::MIN_STANDARD_SCORE,
            min(self::MAX_STANDARD_SCORE, $standardScore)
        );
        $base['beta_standard_score_status'] = self::STATUS_SIMULATION_CALIBRATED_BETA;
        $base['random_baseline_z'] = round($zScore, 4);
        $base['above_random_baseline'] = $rawScore > self::RANDOM_BASELINE_MEAN;

        return $base;
    }
}
