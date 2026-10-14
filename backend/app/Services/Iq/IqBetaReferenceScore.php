<?php

declare(strict_types=1);

namespace App\Services\Iq;

/** A designed display scale; neither item calibration nor a human norm. */
final class IqBetaReferenceScore
{
    public const MODEL_VERSION = 'owner30_design_anchors_2026_10_14_v1';

    public const BANK_ID = 'IQ_OWNER_ORIGINAL_30';

    public function fromScore(array $score): ?array
    {
        $raw = $score['raw_score'] ?? null;
        if (($score['status'] ?? null) !== 'scored'
            || ($score['bank_id'] ?? null) !== self::BANK_ID
            || ($score['technical_failure'] ?? false) === true
            || (! is_int($raw) && ! is_float($raw))
            || ! is_finite((float) $raw) || floor((float) $raw) !== (float) $raw
            || $raw < 0 || $raw > 30
            || (isset($score['expected_item_count']) && (int) $score['expected_item_count'] !== 30)
            || (isset($score['answer_count']) && (int) $score['answer_count'] !== 30)) {
            return null;
        }

        $standardScore = match (true) {
            $raw == 0 => 73.0,
            $raw <= 5 => 85.0 + 3.75 * ($raw - 1),
            $raw <= 20 => 100.0 + 2.0 * ($raw - 5),
            default => 130.0 + 1.5 * ($raw - 20),
        };

        return [
            'status' => 'available',
            'model_version' => self::MODEL_VERSION,
            'source_kind' => 'designed_reference',
            'raw_score' => (int) $raw,
            'standard_score' => $standardScore,
            'display_score' => (int) round($standardScore, 0, PHP_ROUND_HALF_UP),
            // CDF uses the unrounded score, not the displayed integer.
            'percentile' => 100.0 * $this->normalCdf(($standardScore - 100.0) / 15.0),
            'reference_mean' => 100,
            'reference_sd' => 15,
            'display_min' => 73,
            'display_max' => 145,
            'production_normed' => false,
            'claim_eligible' => false,
            'population_percentile_eligible' => false,
        ];
    }

    /** Read-time interpretation is additive; stored legacy fields remain unchanged. */
    public function withCurrentInterpretation(array $payload): array
    {
        if (array_key_exists('beta_reference', $payload)) {
            return $payload;
        }

        $score = $payload;
        if (($payload['schema_version'] ?? null) === 'iq.report.v1') {
            if (($payload['access']['report_access_level'] ?? null) !== 'full') {
                return $payload;
            }
            $score = array_merge($payload['scoring'] ?? [], $payload['summary'] ?? []);
        }

        $reference = $this->fromScore($score);
        if ($reference !== null) {
            $payload['beta_reference'] = $reference;
        }

        return $payload;
    }

    /** Abramowitz–Stegun 26.2.17, absolute error below 7.5e-8. */
    private function normalCdf(float $z): float
    {
        if ($z === 0.0) {
            return 0.5;
        }
        $x = abs($z);
        $t = 1.0 / (1.0 + 0.2316419 * $x);
        $tail = exp(-$x * $x / 2.0) / sqrt(2.0 * M_PI)
            * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return $z > 0 ? 1.0 - $tail : $tail;
    }
}
