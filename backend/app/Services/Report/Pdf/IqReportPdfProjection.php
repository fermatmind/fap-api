<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Iq\IqBetaReferenceScore;
use App\Services\Report\ReportAccess;

/** Display-only projection of the same atomic sources consumed by lib/iq/presentation.ts. */
final class IqReportPdfProjection
{
    public const BANK = 'IQ_OWNER_ORIGINAL_30';

    public function project(Attempt $attempt, Result $result, array $gate): array
    {
        $report = (array) ($gate['report'] ?? []);
        if (($gate['ok'] ?? false) !== true || ($gate['locked'] ?? true) !== false
            || ($gate['variant'] ?? '') !== 'full' || ($gate['generating'] ?? false)
            || ($gate['snapshot_error'] ?? false)
            || data_get($gate, 'meta.generating', false) || data_get($gate, 'meta.snapshot_error', false)
            || ! in_array(ReportAccess::MODULE_IQ_FULL, (array) ($gate['modules_allowed'] ?? []), true)
            || ($report['schema_version'] ?? '') !== 'iq.report.v1'
            || ($report['access']['report_access_level'] ?? '') !== 'full'
            || ($report['access']['variant'] ?? '') !== 'full'
            || (int) $attempt->question_count !== 30
            || ! in_array((string) $attempt->locale, ['en', 'zh-CN'], true)
            || ($report['locale'] ?? null) !== $attempt->locale) {
            $this->unavailable();
        }
        $json = (array) ($result->result_json ?? []);
        $score = [];
        // Same source order as IqReportBuilder. Never inspect question/answer fields.
        foreach ([$result->normed_json, $json['normed_json'] ?? null,
            data_get($json, 'breakdown_json.score_result'), data_get($json, 'axis_scores_json.score_result'), $json] as $candidate) {
            if (is_array($candidate) && array_intersect(['dimension_scores', 'quality', 'result_stability', 'norms', 'status'], array_keys($candidate))) {
                $score = $candidate;
                break;
            }
        }
        $summary = (array) ($report['summary'] ?? []);
        $scoring = (array) ($report['scoring'] ?? []);
        if (($scoring['bank_id'] ?? '') !== self::BANK || ($score['bank_id'] ?? '') !== self::BANK
            || ($scoring['status'] ?? '') !== 'scored' || ($score['status'] ?? '') !== 'scored'
            || ($score['technical_failure'] ?? false) === true
            || (isset($score['expected_item_count']) && $this->count($score['expected_item_count']) !== 30)) {
            $this->unavailable();
        }
        $correct = $this->count($summary['raw_score'] ?? null);
        if ($correct === null || $correct > 30 || $this->count($score['raw_score'] ?? null) !== $correct) {
            $this->unavailable();
        }
        $dimensions = [];
        foreach (['VSPR' => 'visual_spatial_pattern_reasoning', 'VSI' => 'visual_spatial_insight', 'NPR' => 'numerical_pattern_reasoning'] as $code => $key) {
            $row = (array) ($report['dimensions'][$key] ?? []);
            $total = $this->count($row['item_count'] ?? null);
            $value = $this->count(array_key_exists('correct_count', $row) ? $row['correct_count'] : ($row['raw_score'] ?? null));
            if ($total === null || $total < 1 || $value === null || $value > $total
                || ! is_string($row['dimension_name'] ?? null) || trim($row['dimension_name']) === '') {
                $this->unavailable();
            }
            $sourceRow = (array) ($score['dimension_scores'][$code] ?? []);
            if ($this->count($sourceRow['item_count'] ?? null) !== $total
                || $this->count(array_key_exists('correct_count', $sourceRow) ? $sourceRow['correct_count'] : ($sourceRow['raw_score'] ?? null)) !== $value) {
                $this->unavailable();
            }
            $dimensions[] = ['code' => $code, 'label' => $row['dimension_name'], 'correct' => $value,
                'total' => $total, 'percent' => $value / $total * 100];
        }
        if (array_sum(array_column($dimensions, 'total')) !== 30 || array_sum(array_column($dimensions, 'correct')) !== $correct) {
            $this->unavailable();
        }
        $locale = (string) ($report['locale'] ?? $attempt->locale);
        if (! in_array($locale, ['en', 'zh-CN'], true)) {
            $this->unavailable();
        }
        $duration = $this->number($score['duration_ms'] ?? null);
        $quality = (array) ($report['quality'] ?? []);
        $flags = array_values(array_filter((array) ($quality['flags'] ?? []), fn ($flag) => in_array($flag,
            ['SPEEDING', 'STRAIGHTLINING', 'NO_VALID_ANSWERS', 'PARTIAL_COMPLETION'], true)));

        return [
            'locale' => $locale, 'correct' => $correct, 'total' => 30, 'percent' => $correct / 30 * 100.0,
            'duration_ms' => $duration !== null && $duration > 0 && $duration < PHP_INT_MAX ? $duration : null,
            'dimensions' => $dimensions,
            'quality' => in_array($quality['level'] ?? null, ['A', 'B', 'C', 'D'], true) ? $quality['level'] : null,
            'quality_flags' => $flags,
            'other_quality_flags' => count((array) ($quality['flags'] ?? [])) - count($flags),
            'stability' => in_array(data_get($report, 'stability.status'), ['stable', 'review_with_caution', 'unstable'], true)
                ? data_get($report, 'stability.status') : null,
            'scoring_version' => $this->version($scoring['scoring_engine_version'] ?? null),
            'reference' => $this->reference([$summary, $scoring, $report, $score], $locale),
        ];
    }

    private function reference(array $sources, string $locale): ?array
    {
        $atomic = static function (string $key) use ($sources): ?array {
            foreach ($sources as $source) {
                if (array_key_exists($key, $source)) {
                    return is_array($source[$key]) ? $source[$key] : null;
                }
            }

            return null;
        };
        $beta = $atomic('beta_reference');
        $value = $this->number($beta['standard_score'] ?? null);
        $display = $this->count($beta['display_score'] ?? null);
        $percentile = $this->number($beta['percentile'] ?? null);
        if (($beta['status'] ?? '') === 'available' && ($beta['source_kind'] ?? '') === 'designed_reference'
            && ($beta['model_version'] ?? '') === IqBetaReferenceScore::MODEL_VERSION
            && ($beta['production_normed'] ?? null) === false && ($beta['population_percentile_eligible'] ?? null) === false
            && $value !== null && $value >= 73 && $value <= 145 && $display === (int) floor($value + 0.5)
            && $percentile !== null && $percentile > 0 && $percentile < 100) {
            return ['kind' => 'beta_reference', 'score' => $display, 'percentile' => $percentile,
                'minimum' => 73, 'maximum' => 145, 'source' => 'designed_reference',
                'model_version' => IqBetaReferenceScore::MODEL_VERSION, 'norm_version' => null, 'population' => null];
        }
        $norm = $atomic('normative_reasoning');
        $value = $this->number($norm['standard_score'] ?? null);
        $percentile = $this->number($norm['percentile'] ?? null);
        $population = $norm['reference_population'][$locale === 'en' ? 'label_en' : 'label_zh'] ?? null;
        if (($norm['status'] ?? '') === 'available' && ($norm['eligible'] ?? false) === true
            && is_string($population) && trim($population) !== ''
            && $this->version($norm['norm_table_version'] ?? null) !== null && $this->version($norm['model_version'] ?? null) !== null
            && $value !== null && $percentile !== null && $percentile > 0 && $percentile < 100) {
            return ['kind' => 'normative_reasoning', 'score' => (int) min(145, max(55, floor($value + 0.5))),
                'percentile' => $percentile, 'minimum' => 55, 'maximum' => 145, 'source' => 'normative_reasoning',
                'model_version' => $norm['model_version'], 'norm_version' => $norm['norm_table_version'], 'population' => $population];
        }

        return null;
    }

    private function count(mixed $value): ?int
    {
        $n = $this->number($value);

        return $n !== null && $n >= 0 && $n < PHP_INT_MAX && floor($n) === $n ? (int) $n : null;
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '' && is_numeric($value)))
            && is_finite((float) $value) ? (float) $value : null;
    }

    private function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/', $value) === 1 ? $value : null;
    }

    private function unavailable(): never
    {
        throw new ApiProblemException(409, 'IQ_PDF_NOT_AVAILABLE', 'A complete original-30 report is not available. Keep using the result text.', headers: ['Cache-Control' => 'private, no-store']);
    }
}
