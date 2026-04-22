<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Enneagram\EnneagramPublicProjectionService;

final class EnneagramReportComposer
{
    public function __construct(
        private readonly EnneagramPublicProjectionService $projectionService,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $locked = $variant === ReportAccess::VARIANT_FREE;
        $locale = trim((string) ($attempt->locale ?? $ctx['locale'] ?? config('content_packs.default_locale', 'zh-CN')));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $scoreResult = $this->extractScoreResult($result);
        if ($scoreResult === []) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'ENNEAGRAM score result missing.',
                'status' => 500,
            ];
        }

        $projection = $this->projectionService->build($scoreResult, $locale, $variant, $locked);
        $sections = is_array($projection['sections'] ?? null) ? $projection['sections'] : [];

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'enneagram.report.v1',
                'scale_code' => 'ENNEAGRAM',
                'variant' => $variant,
                'primary_type' => (string) ($projection['primary_type'] ?? ''),
                'primary_label' => (string) ($projection['primary_label'] ?? ''),
                'scores' => is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [],
                'ranked_types' => is_array($projection['ranked_types'] ?? null) ? $projection['ranked_types'] : [],
                'scoring' => is_array($projection['scoring'] ?? null) ? $projection['scoring'] : [],
                'analysis' => is_array($projection['analysis'] ?? null) ? $projection['analysis'] : [],
                'display' => is_array($projection['display'] ?? null) ? $projection['display'] : [],
                'confidence' => is_array($projection['confidence'] ?? null) ? $projection['confidence'] : [],
                'quality' => is_array($projection['quality'] ?? null) ? $projection['quality'] : [],
                'sections' => $sections,
                '_meta' => [
                    'enneagram_public_projection_v1' => $projection,
                ],
                'generated_at' => now()->toISOString(),
            ],
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
}
