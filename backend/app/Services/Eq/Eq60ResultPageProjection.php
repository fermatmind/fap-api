<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Services\Content\Eq60PackLoader;
use DomainException;

/** Response-only editorial projection. Scores and classification remain report authority. */
final class Eq60ResultPageProjection
{
    public function __construct(private readonly Eq60PackLoader $loader) {}

    public function project(array $report): array
    {
        if (($report['scale_code'] ?? '') !== 'EQ_60' || ($report['eq_report_mode'] ?? '') !== 'self_report'
            || ($report['schema_version'] ?? '') !== 'eq_60.report.v2') {
            throw new DomainException('EQ_60_RESULT_PAGE_REPORT_INVALID');
        }
        $content = $this->loader->loadResultPageLibrary();
        $library = $content['library'];
        $id = (string) data_get($report, 'interpretation.core_formulation_id');
        if (! isset($library['result_labels'][$id])) {
            throw new DomainException('EQ_60_RESULT_PAGE_CLASSIFICATION_UNKNOWN');
        }
        $quality = $id === 'low_confidence_result';
        $copy = [];
        foreach ($library['locales'] as $locale) {
            $copy[$locale] = $quality ? $library['shared'][$locale] : array_merge(
                $library['shared'][$locale], $library['profiles'][$id][$locale],
                ['dimensions' => $this->dimensions($library, (array) data_get($report, 'scores.dimensions'), $locale, true)],
            );
        }

        return ['schema' => 'eq60.result_page.v1', 'source_hash' => $content['source_hash'],
            'compiled_hash' => $content['compiled_hash'], 'result_id' => $id, 'copy' => $copy,
            'result_labels' => $library['result_labels'], 'dimension_bands' => $library['dimension_bands'],
            'sources' => $library['sources'], 'quality' => $quality ? $library['quality_result'] : null];
    }

    /** Public-by-design numeric overview only; no report body or practice assets. */
    public function unlock(array $score): ?array
    {
        if (($score['scale_code'] ?? '') !== 'EQ_60') {
            return null;
        }
        $library = $this->loader->loadResultPageLibrary()['library'];
        $level = strtoupper((string) data_get($score, 'quality.level'));
        $low = in_array($level, ['C', 'D', 'INVALID'], true)
            || array_intersect((array) data_get($score, 'quality.flags', []), ['SPEEDING', 'LONGSTRING', 'INCONSISTENCY']) !== [];
        $dimensions = [];
        foreach ($library['locales'] as $locale) {
            $dimensions[$locale] = $low ? [] : $this->dimensions($library, (array) ($score['scores'] ?? []), $locale, false);
        }

        return ['schema' => 'eq60.unlock.v1', 'low_confidence' => $low, 'dimensions' => $dimensions];
    }

    private function dimensions(array $library, array $scores, string $locale, bool $full): array
    {
        $dimensions = [];
        foreach ($library['dimension_meta'] as $meta) {
            $node = $scores[$meta['code']] ?? [];
            $score = $node['standard_score'] ?? $node['std_score'] ?? null;
            $raw = $node['raw_score'] ?? $node['raw_sum'] ?? null;
            if (! is_numeric($score) || ! is_numeric($raw) || ! is_finite((float) $score)) {
                throw new DomainException('EQ_60_RESULT_PAGE_SCORE_MISSING');
            }
            $band = null;
            foreach ($library['dimension_bands'] as $candidate) {
                if ($score >= $candidate['min'] && $score < $candidate['max_exclusive']) {
                    $band = $candidate;
                    break;
                }
            }
            if ($band === null) {
                throw new DomainException('EQ_60_RESULT_PAGE_SCORE_RANGE_INVALID');
            }
            $dimension = ['code' => $meta['code'], 'name' => $meta['labels'][$locale],
                'en_name' => $meta['en_name'], 'color' => $meta['color'], 'score' => $score, 'raw' => $raw,
                'band_code' => $band['code'], 'band_label' => $band['labels'][$locale]];
            $dimensions[] = $full ? array_merge($dimension, $library['dimension_cards'][$meta['code']][$band['code']][$locale]) : $dimension;
        }

        return $dimensions;
    }
}
