<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Riasec\RiasecPublicProjectionService;

final class RiasecReportComposer
{
    public function __construct(
        private readonly RiasecPublicProjectionService $projectionService,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $locale = trim((string) ($attempt->locale ?? $ctx['locale'] ?? config('content_packs.default_locale', 'zh-CN')));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $projection = $this->projectionService->buildFromResult($result, $locale);
        $topCode = trim((string) ($projection['top_code'] ?? $result->type_code ?? ''));
        if ($topCode === '') {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'RIASEC score result missing.',
                'status' => 500,
            ];
        }

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'riasec.report.v1',
                'scale_code' => 'RIASEC',
                'variant' => $variant,
                'top_code' => $topCode,
                'primary_type' => (string) ($projection['primary_type'] ?? ''),
                'secondary_type' => (string) ($projection['secondary_type'] ?? ''),
                'tertiary_type' => (string) ($projection['tertiary_type'] ?? ''),
                'scores' => is_array($projection['scores_0_100'] ?? null) ? $projection['scores_0_100'] : [],
                'quality' => [
                    'grade' => (string) ($projection['quality_grade'] ?? ''),
                    'flags' => array_values(array_filter(array_map(
                        static fn (mixed $value): string => trim((string) $value),
                        (array) ($projection['quality_flags'] ?? [])
                    ))),
                ],
                'indices' => [
                    'clarity_index' => (float) ($projection['clarity_index'] ?? 0),
                    'breadth_index' => (float) ($projection['breadth_index'] ?? 0),
                ],
                'sections' => $this->buildSections($projection),
                '_meta' => [
                    'riasec_public_projection_v1' => $projection,
                ],
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return list<array<string,mixed>>
     */
    private function buildSections(array $projection): array
    {
        $topCode = trim((string) ($projection['top_code'] ?? ''));
        $labels = is_array($projection['dimension_labels'] ?? null) ? $projection['dimension_labels'] : [];
        $scores = is_array($projection['scores_0_100'] ?? null) ? $projection['scores_0_100'] : [];
        $enhanced = is_array($projection['enhanced_breakdown'] ?? null) ? $projection['enhanced_breakdown'] : [];

        $sections = [
            [
                'key' => 'riasec.summary',
                'access' => ReportAccess::CARD_ACCESS_FREE,
                'title' => 'RIASEC summary',
                'body' => $topCode !== ''
                    ? 'Your Holland interest code is '.$topCode.'.'
                    : 'Your Holland interest profile is ready.',
                'top_code' => $topCode,
                'primary_type' => (string) ($projection['primary_type'] ?? ''),
                'secondary_type' => (string) ($projection['secondary_type'] ?? ''),
                'tertiary_type' => (string) ($projection['tertiary_type'] ?? ''),
            ],
            [
                'key' => 'riasec.scores',
                'access' => ReportAccess::CARD_ACCESS_FREE,
                'title' => 'RIASEC dimensions',
                'scores' => array_map(
                    static fn (string $code, mixed $score): array => [
                        'code' => $code,
                        'label' => trim((string) ($labels[$code] ?? $code)),
                        'score' => round((float) $score, 2),
                    ],
                    array_keys($scores),
                    array_values($scores)
                ),
            ],
        ];

        $hasEnhancedBreakdown = false;
        foreach (['activity', 'environment', 'role'] as $key) {
            if (is_array($enhanced[$key] ?? null) && $enhanced[$key] !== []) {
                $hasEnhancedBreakdown = true;
                break;
            }
        }

        if ($hasEnhancedBreakdown) {
            $sections[] = [
                'key' => 'riasec.enhanced_breakdown',
                'access' => ReportAccess::CARD_ACCESS_FREE,
                'title' => 'Enhanced breakdown',
                'breakdown' => $enhanced,
            ];
        }

        return $sections;
    }
}
