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

        $snapshotBound = (bool) ($ctx['snapshot_bound'] ?? false);
        $projection = $this->projectionService->buildFromResult($result, $locale);
        $projectionV2 = $this->projectionService->buildV2FromResult($result, $locale, $snapshotBound);
        $resultPageV2 = $this->buildResultPageV2RuntimeWrapper($attempt, $result, $variant, $projectionV2, $ctx);
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
                '_meta' => array_filter([
                    'riasec_public_projection_v1' => $projection,
                    'riasec_public_projection_v2' => $projectionV2,
                    'snapshot_binding_v1' => $snapshotBound ? $this->buildSnapshotBinding($ctx, $projectionV2) : null,
                    'result_page_v2' => $resultPageV2,
                ], static fn (mixed $value): bool => $value !== null),
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>|null
     */
    private function buildResultPageV2RuntimeWrapper(Attempt $attempt, Result $result, string $variant, array $projectionV2, array $ctx): ?array
    {
        if (! $this->resultPageV2GateAllowsRuntime($ctx)) {
            return null;
        }
        if ($variant !== ReportAccess::VARIANT_FULL) {
            return null;
        }
        if (! $this->resultPageV2ProjectionIsUsable($projectionV2)) {
            return null;
        }

        $attemptId = (string) ($attempt->attempt_id ?? '');
        $locale = trim((string) ($attempt->locale ?? data_get($projectionV2, 'locale', 'zh-CN')));

        return [
            'schema_version' => 'fap.riasec.result_page_v2.runtime_wrapper.v0.1',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_wrapper_enabled' => true,
            'production_rollout_enabled' => false,
            'frontend_fallback_allowed' => false,
            'private_payload_exported' => false,
            'gate' => [
                'enabled' => true,
                'staging_runtime_enabled' => (bool) config('riasec_result_page_v2.staging_runtime_enabled', false),
                'pilot_runtime_enabled' => (bool) config('riasec_result_page_v2.pilot_runtime_enabled', false),
                'production_runtime_enabled' => false,
                'environment' => app()->environment(),
            ],
            'identity' => [
                'scale_code' => 'RIASEC',
                'attempt_id_included' => false,
                'attempt_ref' => $attemptId === '' ? null : substr(hash('sha256', $attemptId), 0, 16),
                'form_code' => (string) data_get($projectionV2, 'form.form_code', ''),
                'locale' => $locale === '' ? 'zh-CN' : $locale,
                'top_code' => (string) ($result->type_code ?? data_get($projectionV2, 'holland_code.code', '')),
            ],
            'selector_inputs' => [
                'quality_state' => (string) data_get($projectionV2, 'quality.quality_state', 'normal'),
                'profile_shape' => (string) data_get($projectionV2, 'interpretation_state.profile_shape', 'low_clarity'),
                'module_visibility_policy_id' => (string) data_get($projectionV2, 'module_visibility_policy.policy_id', ''),
                'deep_content_slots_schema_version' => (string) data_get($projectionV2, 'deep_content_slots_v1.schema_version', ''),
            ],
            'payload_refs' => [
                'module_visibility_policy' => data_get($projectionV2, 'module_visibility_policy'),
                'deep_content_slots_v1' => data_get($projectionV2, 'deep_content_slots_v1'),
            ],
            'redaction_policy' => [
                'variant' => $variant,
                'locked_payload_allowed' => false,
                'free_payload_allowed' => false,
                'omit_when_invalid' => true,
                'fail_closed' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     */
    private function resultPageV2GateAllowsRuntime(array $ctx): bool
    {
        if ((bool) config('riasec_result_page_v2.production_runtime_enabled', false)) {
            return false;
        }
        if ((bool) config('riasec_result_page_v2.production_rollout_enabled', false)) {
            return false;
        }
        if ((bool) config('riasec_result_page_v2.production_rollout_manual_approval_granted', false)) {
            return false;
        }
        if (! (bool) config('riasec_result_page_v2.enabled', false)) {
            return false;
        }

        $allowedEnvironments = array_map(
            static fn (mixed $environment): string => trim((string) $environment),
            (array) config('riasec_result_page_v2.allowed_environments', [])
        );
        if (! in_array(app()->environment(), $allowedEnvironments, true)) {
            return false;
        }

        if ((bool) ($ctx['riasec_result_page_v2_staging'] ?? false) && (bool) config('riasec_result_page_v2.staging_runtime_enabled', false)) {
            return true;
        }

        return (bool) ($ctx['riasec_result_page_v2_pilot'] ?? false)
            && (bool) config('riasec_result_page_v2.pilot_runtime_enabled', false);
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     */
    private function resultPageV2ProjectionIsUsable(array $projectionV2): bool
    {
        return (string) ($projectionV2['schema_version'] ?? '') === 'riasec.public_projection.v2'
            && is_array($projectionV2['module_visibility_policy'] ?? null)
            && is_array($projectionV2['deep_content_slots_v1'] ?? null)
            && (bool) data_get($projectionV2, 'deep_content_slots_v1.source_policy.frontend_fallback_allowed', true) === false;
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildSnapshotBinding(array $ctx, array $projectionV2): array
    {
        return [
            'schema_version' => 'riasec.snapshot_binding.v1',
            'snapshot_bound' => true,
            'snapshot_version' => trim((string) ($ctx['snapshot_version'] ?? 'v1')),
            'report_engine_version' => trim((string) ($ctx['report_engine_version'] ?? 'v1.2')),
            'measurement_contract_version' => data_get($projectionV2, 'measurement_evidence.measurement_contract_version'),
            'scoring_spec_version' => data_get($projectionV2, 'measurement_evidence.scoring_spec_version'),
            'score_space_version' => data_get($projectionV2, 'measurement_evidence.score_space_version'),
            'form_code' => data_get($projectionV2, 'form.form_code'),
            'validation_status' => data_get($projectionV2, 'measurement_evidence.validation_status'),
            'deep_content_slots_schema_version' => data_get($projectionV2, 'deep_content_slots_v1.schema_version'),
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
