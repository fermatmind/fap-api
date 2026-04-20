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
            'confidence' => [
                'level' => strtolower(trim((string) ($confidence['level'] ?? 'unknown'))),
                'top1_top2_gap' => $confidence['top1_top2_gap'] ?? null,
            ],
            'quality' => is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [],
            'ordered_section_keys' => ['summary', 'scores'],
            'sections' => $sections,
            '_meta' => array_filter([
                'engine_version' => trim((string) ($scoreResult['engine_version'] ?? '')),
                'scoring_spec_version' => trim((string) ($scoreResult['scoring_spec_version'] ?? '')),
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
