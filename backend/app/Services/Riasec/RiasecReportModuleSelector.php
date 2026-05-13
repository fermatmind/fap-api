<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecReportModuleSelector
{
    public const POLICY_ID = 'riasec_module_visibility_policy_v1';

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    public function build(array $projectionV2): array
    {
        $qualityState = (string) ($projectionV2['quality']['quality_state'] ?? 'normal');
        $profileShape = (string) ($projectionV2['interpretation_state']['profile_shape'] ?? 'low_clarity');
        $formCode = (string) ($projectionV2['form']['form_code'] ?? 'riasec_60');

        $modules = $this->standardModules($formCode);
        if ($qualityState === 'low_quality') {
            $modules = $this->applyLowQuality($modules);
        } elseif ($qualityState === 'caution') {
            $modules = $this->applyCaution($modules);
        }

        $modules = match ($profileShape) {
            'broad_profile' => $this->applyBroadProfile($modules),
            'near_tie' => $this->applyNearTie($modules),
            'low_clarity' => $this->applyLowClarity($modules),
            default => $modules,
        };

        if ($formCode !== 'riasec_140') {
            $modules['140q_context_cards'] = $this->module('140q_context_cards', 'hidden', 'requires_riasec_140_contextual_form');
        }

        return [
            'schema_version' => 'riasec.module_visibility_policy.v1',
            'policy_id' => self::POLICY_ID,
            'quality_state' => $qualityState,
            'profile_shape' => $profileShape,
            'form_code' => $formCode,
            'modules' => array_values($modules),
            'fallback_policy' => [
                'unknown_module' => 'hidden',
                'missing_backend_state' => 'hidden',
                'frontend_inference_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function standardModules(string $formCode): array
    {
        return [
            'hero_activity_chain' => $this->module('hero_activity_chain', 'visible', 'standard_reading_available'),
            'six_dimension_map' => $this->module('six_dimension_map', 'visible', 'dimension_overview_available'),
            'pair_blend' => $this->module('pair_blend', 'visible', 'combination_reading_available'),
            'activity_explorer' => $this->module('activity_explorer', 'visible', 'examples_only_activity_explorer_available'),
            'occupation_examples' => $this->module('occupation_examples', 'collapsed', 'examples_only_not_registry_match'),
            '140q_cta' => $this->module('140q_cta', $formCode === 'riasec_140' ? 'hidden' : 'visible', 'contextual_form_optional_not_more_accurate'),
            '140q_context_cards' => $this->module('140q_context_cards', $formCode === 'riasec_140' ? 'visible' : 'hidden', 'contextual_layer_available_for_140q_only'),
            'share_card' => $this->module('share_card', 'visible', 'safe_share_available'),
            'pdf' => $this->module('pdf', 'visible', 'snapshot_bound_pdf_available'),
            'history' => $this->module('history', 'visible', 'snapshot_bound_history_available'),
            'feedback_overlay' => $this->module('feedback_overlay', 'visible', 'feedback_does_not_mutate_measured_result'),
        ];
    }

    /**
     * @param  array<string,array<string,string>>  $modules
     * @return array<string,array<string,string>>
     */
    private function applyLowQuality(array $modules): array
    {
        foreach (['hero_activity_chain', 'pair_blend', 'activity_explorer', 'occupation_examples', '140q_cta', '140q_context_cards'] as $key) {
            $modules[$key] = $this->module($key, 'hidden', 'low_quality_hides_strong_modules');
        }
        $modules['six_dimension_map'] = $this->module('six_dimension_map', 'visible', 'low_quality_overview_only');
        $modules['share_card'] = $this->module('share_card', 'collapsed', 'low_quality_no_strong_public_share');
        $modules['pdf'] = $this->module('pdf', 'collapsed', 'low_quality_cautious_pdf_only');

        return $modules;
    }

    /**
     * @param  array<string,array<string,string>>  $modules
     * @return array<string,array<string,string>>
     */
    private function applyCaution(array $modules): array
    {
        foreach (['pair_blend', 'occupation_examples', '140q_cta'] as $key) {
            $modules[$key] = $this->module($key, 'collapsed', 'caution_softens_interpretation');
        }

        return $modules;
    }

    /**
     * @param  array<string,array<string,string>>  $modules
     * @return array<string,array<string,string>>
     */
    private function applyBroadProfile(array $modules): array
    {
        $modules['hero_activity_chain'] = $this->module('hero_activity_chain', 'hidden', 'broad_profile_activity_filter_first');
        $modules['occupation_examples'] = $this->module('occupation_examples', 'hidden', 'broad_profile_no_single_chain_examples');

        return $modules;
    }

    /**
     * @param  array<string,array<string,string>>  $modules
     * @return array<string,array<string,string>>
     */
    private function applyNearTie(array $modules): array
    {
        $modules['hero_activity_chain'] = $this->module('hero_activity_chain', 'collapsed', 'near_tie_candidate_chains_first');
        $modules['pair_blend'] = $this->module('pair_blend', 'visible', 'near_tie_pair_blend_required');

        return $modules;
    }

    /**
     * @param  array<string,array<string,string>>  $modules
     * @return array<string,array<string,string>>
     */
    private function applyLowClarity(array $modules): array
    {
        $modules['hero_activity_chain'] = $this->module('hero_activity_chain', 'collapsed', 'low_clarity_cautious_overview');
        $modules['occupation_examples'] = $this->module('occupation_examples', 'hidden', 'low_clarity_hides_strong_examples');

        return $modules;
    }

    /**
     * @return array{key:string,visibility:string,reason:string}
     */
    private function module(string $key, string $visibility, string $reason): array
    {
        return [
            'key' => $key,
            'visibility' => $visibility,
            'reason' => $reason,
        ];
    }
}
