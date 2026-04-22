<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Models\Result;

final class EnneagramPublicProjectionService
{
    private const TYPE_ORDER = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const TYPE_LABELS = [
        'T1' => ['en' => 'Type 1', 'zh' => '一号'],
        'T2' => ['en' => 'Type 2', 'zh' => '二号'],
        'T3' => ['en' => 'Type 3', 'zh' => '三号'],
        'T4' => ['en' => 'Type 4', 'zh' => '四号'],
        'T5' => ['en' => 'Type 5', 'zh' => '五号'],
        'T6' => ['en' => 'Type 6', 'zh' => '六号'],
        'T7' => ['en' => 'Type 7', 'zh' => '七号'],
        'T8' => ['en' => 'Type 8', 'zh' => '八号'],
        'T9' => ['en' => 'Type 9', 'zh' => '九号'],
    ];

    /**
     * @return array<string,mixed>
     */
    public function buildFromResult(Result $result, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        return $this->build($this->extractScoreResult($result), $locale, $variant, $locked);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    public function build(array $scoreResult, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        $language = $this->normalizeLanguage($locale);
        $scoresPct = is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [];
        $ranking = is_array($scoreResult['ranking'] ?? null) ? $scoreResult['ranking'] : [];
        $rankByType = [];
        foreach ($ranking as $row) {
            if (! is_array($row)) {
                continue;
            }
            $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
            if ($typeCode !== '') {
                $rankByType[$typeCode] = $row;
            }
        }

        $typeVector = [];
        foreach (self::TYPE_ORDER as $typeCode) {
            $row = is_array($rankByType[$typeCode] ?? null) ? $rankByType[$typeCode] : [];
            $score = (float) ($scoresPct[$typeCode] ?? ($row['score_pct'] ?? 0.0));
            $typeVector[] = [
                'type_code' => $typeCode,
                'label' => $this->typeLabel($typeCode, $language),
                'score_pct' => round($score, 2),
                'rank' => (int) ($row['rank'] ?? 0),
                'band' => $this->bandForScore($score),
            ];
        }

        $rankedTypes = $this->rankedTypes($ranking, $scoresPct, $language);
        $primaryType = $this->normalizeTypeCode($scoreResult['primary_type'] ?? ($rankedTypes[0]['type_code'] ?? ''));
        $topTypes = array_slice($rankedTypes, 0, 3);
        $confidence = is_array($scoreResult['confidence'] ?? null) ? $scoreResult['confidence'] : [];
        $scoring = $this->buildScoringBlock($scoreResult);
        $analysis = $this->buildAnalysisBlock($scoreResult, $primaryType, $topTypes, $confidence);
        $display = $this->buildDisplayBlock($scoreResult, $rankedTypes, $language);

        $sections = [
            [
                'key' => 'summary',
                'title' => $language === 'zh' ? '九型人格结果概览' : 'Enneagram result summary',
                'blocks' => [
                    [
                        'kind' => 'summary',
                        'primary_type' => $primaryType,
                        'primary_label' => $this->typeLabel($primaryType, $language),
                        'confidence_level' => strtolower(trim((string) ($confidence['level'] ?? 'unknown'))),
                        'interpretation_state' => (string) ($analysis['interpretation_state'] ?? ''),
                    ],
                ],
            ],
            [
                'key' => 'scores',
                'title' => $language === 'zh' ? '九型得分排序' : 'Nine type ranking',
                'blocks' => [
                    [
                        'kind' => 'ranking',
                        'items' => $rankedTypes,
                    ],
                ],
            ],
        ];

        return [
            'schema_version' => 'enneagram.public_projection.v1',
            'scale_code' => 'ENNEAGRAM',
            'form_code' => trim((string) ($scoreResult['form_code'] ?? '')),
            'score_method' => trim((string) ($scoreResult['score_method'] ?? '')),
            'primary_type' => $primaryType,
            'primary_label' => $this->typeLabel($primaryType, $language),
            'type_vector' => $typeVector,
            'ranked_types' => $rankedTypes,
            'top_types' => $topTypes,
            'scoring' => $scoring,
            'analysis' => $analysis,
            'display' => $display,
            'confidence' => [
                'level' => strtolower(trim((string) ($confidence['level'] ?? 'unknown'))),
                'top1_top2_gap' => $confidence['top1_top2_gap'] ?? null,
                'score_separation' => $confidence['score_separation'] ?? ($analysis['score_separation'] ?? null),
                'interpretation_state' => $confidence['interpretation_state'] ?? ($analysis['interpretation_state'] ?? null),
            ],
            'quality' => is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [],
            'ordered_section_keys' => ['summary', 'scores'],
            'sections' => $sections,
            '_meta' => array_filter([
                'engine_version' => trim((string) ($scoreResult['engine_version'] ?? '')),
                'scoring_spec_version' => trim((string) ($scoreResult['scoring_spec_version'] ?? '')),
                'display_score_semantics' => $this->displayScoreSemantics($scoreResult),
                'variant' => $variant,
                'locked' => $locked,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload,
            data_get($payload, 'breakdown_json.score_result'),
            data_get($payload, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && strtoupper(trim((string) ($candidate['scale_code'] ?? ''))) === 'ENNEAGRAM') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranking
     * @param  array<string,mixed>  $scoresPct
     * @return list<array<string,mixed>>
     */
    private function rankedTypes(array $ranking, array $scoresPct, string $language): array
    {
        $rows = [];
        if ($ranking !== []) {
            foreach ($ranking as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
                if ($typeCode === '') {
                    continue;
                }
                $score = (float) ($row['score_pct'] ?? ($scoresPct[$typeCode] ?? 0.0));
                $rows[] = [
                    'type_code' => $typeCode,
                    'label' => $this->typeLabel($typeCode, $language),
                    'score_pct' => round($score, 2),
                    'rank' => (int) ($row['rank'] ?? 0),
                    'band' => $this->bandForScore($score),
                ];
            }
        }

        if ($rows === []) {
            foreach (self::TYPE_ORDER as $typeCode) {
                $score = (float) ($scoresPct[$typeCode] ?? 0.0);
                $rows[] = [
                    'type_code' => $typeCode,
                    'label' => $this->typeLabel($typeCode, $language),
                    'score_pct' => round($score, 2),
                    'rank' => 0,
                    'band' => $this->bandForScore($score),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $scoreCompare = ((float) $b['score_pct']) <=> ((float) $a['score_pct']);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string) $a['type_code'], (string) $b['type_code']);
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function buildScoringBlock(array $scoreResult): array
    {
        $scoring = is_array($scoreResult['scoring'] ?? null) ? $scoreResult['scoring'] : [];
        if ($scoring !== []) {
            return $scoring;
        }

        $rawScores = is_array($scoreResult['raw_scores'] ?? null) ? $scoreResult['raw_scores'] : [];
        if (is_array($rawScores['raw_intensity'] ?? null)) {
            return [
                'raw' => $rawScores['raw_intensity'],
                'dominance' => is_array($rawScores['dominance'] ?? null) ? $rawScores['dominance'] : [],
            ];
        }

        if (is_array($rawScores['type_counts'] ?? null)) {
            return [
                'wins' => $rawScores['type_counts'],
                'exposures' => is_array($rawScores['exposures'] ?? null) ? $rawScores['exposures'] : [],
            ];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  list<array<string,mixed>>  $topTypes
     * @param  array<string,mixed>  $confidence
     * @return array<string,mixed>
     */
    private function buildAnalysisBlock(array $scoreResult, string $primaryType, array $topTypes, array $confidence): array
    {
        $analysis = is_array($scoreResult['analysis'] ?? null) ? $scoreResult['analysis'] : [];

        return array_filter([
            'core_type' => (string) ($analysis['core_type'] ?? $primaryType),
            'top3' => is_array($analysis['top3'] ?? null)
                ? $analysis['top3']
                : array_values(array_map(static fn (array $row): string => (string) ($row['type_code'] ?? ''), $topTypes)),
            'wing_candidate' => $analysis['wing_candidate'] ?? null,
            'wing_neighbor_scores' => $analysis['wing_neighbor_scores'] ?? null,
            'runner_up' => $analysis['runner_up'] ?? null,
            'topology_relation_of_runner_up' => $analysis['topology_relation_of_runner_up'] ?? null,
            'score_separation' => $analysis['score_separation'] ?? ($confidence['score_separation'] ?? ($confidence['top1_top2_gap'] ?? null)),
            'tie_break_status' => $analysis['tie_break_status'] ?? null,
            'unresolved_tie' => $analysis['unresolved_tie'] ?? null,
            'close_call_candidates' => $analysis['close_call_candidates'] ?? null,
            'tie_break_scores' => $analysis['tie_break_scores'] ?? null,
            'interpretation_state' => $analysis['interpretation_state'] ?? ($confidence['interpretation_state'] ?? null),
            'confidence_band' => $analysis['confidence_band'] ?? ($confidence['level'] ?? null),
            'response_quality_summary' => $analysis['response_quality_summary'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  list<array<string,mixed>>  $rankedTypes
     * @return array<string,mixed>
     */
    private function buildDisplayBlock(array $scoreResult, array $rankedTypes, string $language): array
    {
        $display = is_array($scoreResult['display'] ?? null) ? $scoreResult['display'] : [];
        $profile100 = is_array($display['profile100'] ?? null) ? $display['profile100'] : [];
        $preference100 = is_array($display['preference100'] ?? null) ? $display['preference100'] : [];
        $scoreKey = $profile100 !== [] ? 'profile100' : 'preference100';
        $scores = $profile100 !== [] ? $profile100 : $preference100;

        $chartVector = [];
        foreach ($rankedTypes as $row) {
            $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
            if ($typeCode === '') {
                continue;
            }
            $chartVector[] = [
                'type_code' => $typeCode,
                'label' => $this->typeLabel($typeCode, $language),
                $scoreKey => $scores[$typeCode] ?? null,
                'rank' => (int) ($row['rank'] ?? 0),
            ];
        }

        return array_filter([
            'profile100' => $profile100 !== [] ? $profile100 : null,
            'preference100' => $preference100 !== [] ? $preference100 : null,
            'chart_vector' => $chartVector,
            'score_kind' => $display['score_kind'] ?? null,
            'score_note' => $display['score_note'] ?? null,
            'not_percentile' => true,
            'not_t_score' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,string|bool>
     */
    private function displayScoreSemantics(array $scoreResult): array
    {
        $formCode = trim((string) ($scoreResult['form_code'] ?? ''));
        if ($formCode === 'enneagram_forced_choice_144') {
            return [
                'display_score' => 'preference100',
                'basis' => 'wins divided by exposures',
                'not_percentile' => true,
                'not_t_score' => true,
            ];
        }

        return [
            'display_score' => 'profile100',
            'basis' => 'raw intensity mapped from [-2,2] to [0,100]',
            'not_percentile' => true,
            'not_t_score' => true,
        ];
    }

    private function normalizeTypeCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (preg_match('/^T([1-9])$/', $value, $matches) !== 1) {
            return '';
        }

        return 'T'.$matches[1];
    }

    private function typeLabel(string $typeCode, string $language): string
    {
        $labels = self::TYPE_LABELS[$typeCode] ?? null;
        if (! is_array($labels)) {
            return $typeCode;
        }

        return (string) ($labels[$language] ?? $labels['zh']);
    }

    private function bandForScore(float $score): string
    {
        if ($score >= 67.0) {
            return 'high';
        }
        if ($score <= 33.0) {
            return 'low';
        }

        return 'mid';
    }

    private function normalizeLanguage(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'en') ? 'en' : 'zh';
    }
}
