<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;

final class RiasecResultPageV2RuntimeWrapper
{
    public const SCHEMA_VERSION = 'fap.riasec.result_page_v2.runtime_wrapper.v0.1';

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>|null
     */
    public function build(Attempt $attempt, Result $result, string $variant, array $projectionV2, array $ctx = []): ?array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        if (! $this->gateAllowsRuntime($ctx)) {
            return null;
        }
        if ($variant !== ReportAccess::VARIANT_FULL) {
            return null;
        }
        if (! $this->projectionIsUsable($projectionV2)) {
            return null;
        }

        $attemptId = (string) ($attempt->attempt_id ?? '');
        $formCode = (string) data_get($projectionV2, 'form.form_code', '');
        $locale = trim((string) ($attempt->locale ?? data_get($projectionV2, 'locale', 'zh-CN')));

        return [
            'schema_version' => self::SCHEMA_VERSION,
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
                'form_code' => $formCode,
                'locale' => $locale === '' ? 'zh-CN' : $locale,
                'top_code' => (string) ($result->type_code ?? data_get($projectionV2, 'top_code', '')),
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
    private function gateAllowsRuntime(array $ctx): bool
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
    private function projectionIsUsable(array $projectionV2): bool
    {
        return (string) ($projectionV2['schema_version'] ?? '') === 'riasec.public_projection.v2'
            && is_array($projectionV2['module_visibility_policy'] ?? null)
            && is_array($projectionV2['deep_content_slots_v1'] ?? null)
            && (bool) data_get($projectionV2, 'deep_content_slots_v1.source_policy.frontend_fallback_allowed', true) === false;
    }
}
