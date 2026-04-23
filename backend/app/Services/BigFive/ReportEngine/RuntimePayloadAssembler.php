<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\FacetAnomalyMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Contracts\ResolvedSection;
use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;

final class RuntimePayloadAssembler
{
    /**
     * @param  list<ResolvedSection>  $sections
     * @param  list<SynergyMatch>  $synergies
     * @param  list<FacetAnomalyMatch>  $facetAnomalies
     * @param  array<string,mixed>  $actionMatrix
     * @return array<string,mixed>
     */
    public function assemble(
        ReportContext $context,
        array $sections,
        array $synergies,
        array $facetAnomalies,
        array $actionMatrix,
    ): array {
        return [
            'schema_version' => 'fap.big5.report.v1',
            'report_id' => $this->reportId($context),
            'locale' => $context->locale,
            'scale_code' => $context->scaleCode,
            'form_code' => $context->formCode,
            'meta' => $context->meta,
            'score_vector' => $context->scoreVector(),
            'engine_decisions' => [
                'dominant_traits' => $this->dominantTraits($context),
                'selected_synergies' => $this->selectedSynergiesToArray($synergies),
                'facet_anomalies' => array_map(static fn (FacetAnomalyMatch $match): array => $match->toArray(), $facetAnomalies),
                'standout_anomalies' => array_map(static fn (FacetAnomalyMatch $match): array => $match->toArray(), array_slice($facetAnomalies, 0, 3)),
            ],
            'sections' => array_map(static fn (ResolvedSection $section): array => $section->toArray(), $sections),
            'action_matrix' => $this->actionMatrixToArray($actionMatrix),
            'render_hints' => [
                'section_skeleton' => [
                    'hero_summary',
                    'domains_overview',
                    'domain_deep_dive',
                    'facet_details',
                    'core_portrait',
                    'norms_comparison',
                    'action_plan',
                    'methodology_and_access',
                ],
                'registry_scope' => 'facet_precision_rollout_pr3b',
                'limited_rollouts' => [
                    'synergies' => [
                        'n_high_x_e_low',
                        'o_high_x_c_low',
                        'o_high_x_n_high',
                        'c_high_x_n_high',
                        'e_high_x_a_low',
                    ],
                    'facet_glossary_entries' => 30,
                    'facet_precision_traits' => ['O', 'C', 'E', 'A', 'N'],
                    'facet_precision_rules' => 22,
                    'facet_precision_caps' => [
                        'per_domain' => 2,
                        'per_report' => 6,
                        'standout_render_cards' => 3,
                    ],
                    'action_rule_scope' => 'scenario_bound_action_matrix_pr3c',
                    'action_rule_scenarios' => ['workplace', 'relationships', 'stress_recovery', 'personal_growth'],
                    'action_rule_count' => 28,
                    'action_matrix_caps' => [
                        'per_scenario_per_bucket' => 1,
                        'per_scenario' => 4,
                        'per_report' => 12,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<SynergyMatch>  $synergies
     * @return list<array<string,mixed>>
     */
    private function selectedSynergiesToArray(array $synergies): array
    {
        $out = [];
        foreach (array_slice($synergies, 0, 2) as $index => $match) {
            $isPrimary = $index === 0;
            $sectionKey = $isPrimary ? 'core_portrait' : 'action_plan';
            $slot = $isPrimary ? 'synergy_primary' : 'synergy_action';
            $kind = $isPrimary ? 'callout' : 'paragraph';

            $payload = $match->toArray();
            $payload['render_rank'] = $isPrimary ? 'primary' : 'secondary';
            $payload['render_section'] = $sectionKey;
            $payload['render_slot'] = $slot;
            $payload['section_targets'] = [[
                'section_key' => $sectionKey,
                'slot' => $slot,
                'kind' => $kind,
            ]];
            $out[] = $payload;
        }

        return $out;
    }

    private function reportId(ReportContext $context): string
    {
        $fixtureId = (string) ($context->meta['fixture_id'] ?? '');
        if ($fixtureId !== '') {
            return 'big5-report-engine-'.$fixtureId;
        }

        return 'big5-report-engine-'.sha1(json_encode($context->scoreVector()) ?: '');
    }

    /**
     * @return list<string>
     */
    private function dominantTraits(ReportContext $context): array
    {
        $scores = [];
        foreach ($context->domains as $traitCode => $domain) {
            $scores[(string) $traitCode] = (int) (is_array($domain) ? ($domain['percentile'] ?? 0) : 0);
        }
        arsort($scores);

        return array_slice(array_keys($scores), 0, 2);
    }

    /**
     * @param  array<string,mixed>  $actionMatrix
     * @return array<string,mixed>
     */
    private function actionMatrixToArray(array $actionMatrix): array
    {
        $out = $actionMatrix;
        $scenarios = [];
        foreach (is_array($actionMatrix['scenarios'] ?? null) ? $actionMatrix['scenarios'] : [] as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }
            $selectedRules = [];
            foreach (is_array($scenario['selected_rules'] ?? null) ? $scenario['selected_rules'] : [] as $bucket => $rule) {
                $selectedRules[(string) $bucket] = $rule instanceof ActionRuleMatch
                    ? $rule->toArray()
                    : null;
            }
            $scenario['selected_rules'] = $selectedRules;
            $scenarios[] = $scenario;
        }
        $out['scenarios'] = $scenarios;

        return $out;
    }
}
