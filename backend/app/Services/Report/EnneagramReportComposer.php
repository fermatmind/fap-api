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
        $projectionV2 = $this->projectionService->buildV2($scoreResult, $locale, $variant, $locked);
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
                    'enneagram_public_projection_v2' => $projectionV2,
                    'snapshot_binding_v1' => $this->buildSnapshotBinding($projectionV2),
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

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildSnapshotBinding(array $projectionV2): array
    {
        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => data_get($projectionV2, 'form.form_code'),
            'form_kind' => data_get($projectionV2, 'form.form_kind'),
            'score_method' => data_get($projectionV2, 'form.score_method'),
            'scoring_spec_version' => data_get($projectionV2, 'form.scoring_spec_version'),
            'score_space_version' => data_get($projectionV2, 'form.score_space_version'),
            'projection_version' => data_get($projectionV2, 'algorithmic_meta.projection_version'),
            'report_schema_version' => data_get($projectionV2, 'algorithmic_meta.report_schema_version'),
            'report_engine_version' => data_get($projectionV2, 'algorithmic_meta.report_engine_version'),
            'close_call_rule_version' => data_get($projectionV2, 'algorithmic_meta.close_call_rule_version'),
            'confidence_policy_version' => data_get($projectionV2, 'algorithmic_meta.confidence_policy_version'),
            'quality_policy_version' => data_get($projectionV2, 'algorithmic_meta.quality_policy_version'),
            'technical_note_version' => data_get($projectionV2, 'algorithmic_meta.technical_note_version'),
            'interpretation_context_id' => data_get($projectionV2, 'content_binding.interpretation_context_id'),
            'content_release_hash' => data_get($projectionV2, 'content_binding.content_release_hash'),
            'content_release_hash_status' => data_get($projectionV2, 'content_binding.content_release_hash_status'),
            'content_snapshot_id' => data_get($projectionV2, 'content_binding.content_snapshot_id'),
            'content_snapshot_hash' => data_get($projectionV2, 'content_binding.content_snapshot_hash'),
            'content_snapshot_status' => data_get($projectionV2, 'content_binding.content_snapshot_status'),
            'compare_compatibility_group' => data_get($projectionV2, 'methodology.compare_compatibility_group'),
            'cross_form_comparable' => data_get($projectionV2, 'methodology.cross_form_comparable'),
        ];
    }
}
