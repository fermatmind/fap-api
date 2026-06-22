<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Riasec\RiasecPublicProjectionService;
use Illuminate\Support\Facades\Log;

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
        $gateDecision = $this->resultPageV2GateDecision($attempt, $projectionV2, $ctx);
        if ((bool) ($ctx['riasec_result_page_v2_pilot'] ?? false)) {
            $this->recordResultPageV2PilotGateDecision($gateDecision);
        }

        if (! (bool) $gateDecision['allowed']) {
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
                'mode' => $gateDecision['mode'],
                'pilot_gate_decision' => $gateDecision['allowed'] ? 'allow' : 'deny',
                'pilot_gate_reason' => $gateDecision['reason'],
                'pilot_gate_matched_rule' => $gateDecision['matched_rule'],
                'pilot_kill_switch_enabled' => (bool) config('riasec_result_page_v2.pilot_kill_switch_enabled', false),
                'raw_identifier_exported' => false,
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
     * @param  array<string,mixed>  $projectionV2
     * @return array{allowed: bool, mode: string, reason: string, matched_rule: string|null, context: array<string,string>}
     */
    private function resultPageV2GateDecision(Attempt $attempt, array $projectionV2, array $ctx): array
    {
        $context = $this->resultPageV2PilotContext($attempt, $projectionV2);

        if ((bool) config('riasec_result_page_v2.production_runtime_enabled', false)) {
            return $this->resultPageV2GateDenied('none', 'production_runtime_flag_denied', $context);
        }
        if ((bool) config('riasec_result_page_v2.production_rollout_enabled', false)) {
            return $this->resultPageV2GateDenied('none', 'production_rollout_flag_denied', $context);
        }
        if ((bool) config('riasec_result_page_v2.production_rollout_manual_approval_granted', false)) {
            return $this->resultPageV2GateDenied('none', 'production_manual_approval_flag_denied', $context);
        }
        if (! (bool) config('riasec_result_page_v2.enabled', false)) {
            return $this->resultPageV2GateDenied('none', 'runtime_gate_disabled', $context);
        }

        $allowedEnvironments = array_map(
            static fn (mixed $environment): string => trim((string) $environment),
            (array) config('riasec_result_page_v2.allowed_environments', [])
        );
        if (! in_array(app()->environment(), $allowedEnvironments, true)) {
            return $this->resultPageV2GateDenied('none', 'runtime_environment_denied', $context);
        }

        if ((bool) ($ctx['riasec_result_page_v2_staging'] ?? false) && (bool) config('riasec_result_page_v2.staging_runtime_enabled', false)) {
            return $this->resultPageV2GateAllowed('staging', 'staging_runtime_allowed', null, $context);
        }

        if (! (bool) ($ctx['riasec_result_page_v2_pilot'] ?? false) || ! (bool) config('riasec_result_page_v2.pilot_runtime_enabled', false)) {
            return $this->resultPageV2GateDenied('none', 'runtime_context_denied', $context);
        }

        return $this->resultPageV2PilotAllowlistDecision($context);
    }

    /**
     * @param  array<string,string>  $context
     * @return array{allowed: bool, mode: string, reason: string, matched_rule: string|null, context: array<string,string>}
     */
    private function resultPageV2PilotAllowlistDecision(array $context): array
    {
        if ((bool) config('riasec_result_page_v2.pilot_kill_switch_enabled', false)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_kill_switch_enabled', $context);
        }

        if (! in_array($context['environment'], $this->resultPageV2ConfiguredList('pilot_allowed_environments'), true)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_environment_denied', $context);
        }

        if ($context['environment'] === 'production' && ! (bool) config('riasec_result_page_v2.pilot_production_allowlist_enabled', false)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_production_denied', $context);
        }

        $allowedFormCodes = $this->resultPageV2ConfiguredList('pilot_allowed_form_codes');
        if ($allowedFormCodes !== [] && ! in_array($context['form_code'], $allowedFormCodes, true)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_form_denied', $context);
        }

        $allowedLocales = $this->resultPageV2ConfiguredList('pilot_allowed_locales');
        if ($allowedLocales !== [] && ! in_array($context['locale'], $allowedLocales, true)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_locale_denied', $context);
        }

        $allowlists = [
            'attempt_id' => $this->resultPageV2ConfiguredList('pilot_access_allowed_attempt_ids'),
            'user_id' => $this->resultPageV2ConfiguredList('pilot_access_allowed_user_ids'),
            'anon_id' => $this->resultPageV2ConfiguredList('pilot_access_allowed_anon_ids'),
            'org_id' => $this->resultPageV2ConfiguredList('pilot_access_allowed_org_ids'),
        ];

        if (! $this->resultPageV2HasConfiguredAllowlist($allowlists)) {
            return $this->resultPageV2GateDenied('pilot', 'pilot_allowlist_empty', $context);
        }

        foreach ($allowlists as $field => $allowedValues) {
            $candidate = $context[$field] ?? '';
            if ($candidate !== '' && in_array($candidate, $allowedValues, true)) {
                return $this->resultPageV2GateAllowed('pilot', 'pilot_allowlist_allowed', $field, $context);
            }
        }

        return $this->resultPageV2GateDenied('pilot', 'pilot_allowlist_denied', $context);
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,string>
     */
    private function resultPageV2PilotContext(Attempt $attempt, array $projectionV2): array
    {
        return [
            'attempt_id' => trim((string) ($attempt->attempt_id ?? $attempt->id ?? '')),
            'user_id' => trim((string) ($attempt->user_id ?? '')),
            'anon_id' => trim((string) ($attempt->anon_id ?? '')),
            'org_id' => trim((string) ($attempt->org_id ?? '')),
            'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            'environment' => (string) app()->environment(),
            'form_code' => trim((string) (data_get($projectionV2, 'form.form_code') ?? data_get($attempt->answers_summary_json, 'meta.form_code', ''))),
            'locale' => trim((string) ($attempt->locale ?? data_get($projectionV2, 'locale', ''))),
        ];
    }

    /**
     * @param  array<string,string>  $context
     * @return array{allowed: bool, mode: string, reason: string, matched_rule: string|null, context: array<string,string>}
     */
    private function resultPageV2GateAllowed(string $mode, string $reason, ?string $matchedRule, array $context): array
    {
        return [
            'allowed' => true,
            'mode' => $mode,
            'reason' => $reason,
            'matched_rule' => $matchedRule,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string,string>  $context
     * @return array{allowed: bool, mode: string, reason: string, matched_rule: string|null, context: array<string,string>}
     */
    private function resultPageV2GateDenied(string $mode, string $reason, array $context): array
    {
        return [
            'allowed' => false,
            'mode' => $mode,
            'reason' => $reason,
            'matched_rule' => null,
            'context' => $context,
        ];
    }

    /**
     * @return list<string>
     */
    private function resultPageV2ConfiguredList(string $key): array
    {
        $configured = config('riasec_result_page_v2.'.$key, []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $configured,
        )));
    }

    /**
     * @param  array<string,list<string>>  $allowlists
     */
    private function resultPageV2HasConfiguredAllowlist(array $allowlists): bool
    {
        foreach ($allowlists as $allowedValues) {
            if ($allowedValues !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{allowed: bool, mode: string, reason: string, matched_rule: string|null, context: array<string,string>}  $decision
     */
    private function recordResultPageV2PilotGateDecision(array $decision): void
    {
        $context = $decision['context'];

        Log::info('RIASEC_RESULT_PAGE_V2_PILOT_GATE', [
            'decision' => $decision['allowed'] ? 'allow' : 'deny',
            'mode' => $decision['mode'],
            'reason' => $decision['reason'],
            'matched_rule' => $decision['matched_rule'],
            'environment' => $context['environment'] ?? '',
            'scale_code' => $context['scale_code'] ?? '',
            'form_code' => $context['form_code'] ?? '',
            'locale' => $context['locale'] ?? '',
            'attempt_hash' => $this->resultPageV2HashIdentifier($context['attempt_id'] ?? ''),
            'user_hash' => $this->resultPageV2HashIdentifier($context['user_id'] ?? ''),
            'anon_hash' => $this->resultPageV2HashIdentifier($context['anon_id'] ?? ''),
            'org_hash' => $this->resultPageV2HashIdentifier($context['org_id'] ?? ''),
            'raw_identifier_exported' => false,
            'production_rollout_enabled' => false,
        ]);
    }

    private function resultPageV2HashIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }

        return hash('sha256', $identifier);
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
