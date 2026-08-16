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
        array $qualityPolicy,
        array $normEvidence,
    ): array {
        $compositeInsights = $this->compositeInsights($context, $synergies, $qualityPolicy);
        $facetDeviations = array_map(fn (FacetAnomalyMatch $match): array => array_merge($match->toArray(), [
            'direction' => $match->facetPercentile >= $match->domainPercentile ? 'above_domain' : 'below_domain',
            'confidence' => (string) ($qualityPolicy['confidence_mode'] ?? 'low'),
            'copy' => $match->copy,
        ]), array_slice($facetAnomalies, 0, 3));

        return [
            'schema_version' => 'fap.big5.report.v1',
            'report_id' => $this->reportId($context),
            'locale' => $context->locale,
            'scale_code' => $context->scaleCode,
            'form_code' => $context->formCode,
            'meta' => $this->publicMeta($context),
            'report_snapshot_identity' => [
                'attempt_id' => (string) ($context->meta['attempt_id'] ?? ''),
                'result_id' => (string) ($context->meta['result_id'] ?? ''),
            ],
            'score_vector' => $context->scoreVector(),
            'quality' => $qualityPolicy,
            'norm_evidence' => $normEvidence,
            'composite_insights' => $compositeInsights,
            'facet_deviations' => $facetDeviations,
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
                'registry_scope' => 'canonical_private_result_v2',
                'limited_rollouts' => [
                    'composite_insight_cap' => 3,
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
        foreach (array_slice($synergies, 0, 3) as $index => $match) {
            $sectionKey = 'core_portrait';
            $slot = 'composite_'.($index + 1);
            $kind = 'callout';

            $payload = $match->toArray();
            $payload['render_rank'] = $index + 1;
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

    /** @param list<SynergyMatch> $synergies @return list<array<string,mixed>> */
    private function compositeInsights(ReportContext $context, array $synergies, array $qualityPolicy): array
    {
        return array_values(array_map(function (SynergyMatch $match) use ($context, $qualityPolicy): array {
            $copy = $match->copy;
            $evidence = [];
            foreach ($match->components as $component) {
                $evidence[] = preg_match('/^[OCEAN]$/', $component) === 1
                    ? ['type' => 'domain', 'code' => $component, 'percentile' => $context->domainPercentile($component), 'band' => $context->domainBand($component)]
                    : ['type' => 'facet', 'code' => $component, 'percentile' => $context->facetPercentile($component)];
            }

            return [
                'rule_id' => $match->synergyId,
                'headline' => (string) ($copy['headline'] ?? $match->title),
                'combination' => $match->components,
                'evidence' => $evidence,
                'mechanism' => (string) ($copy['mechanism'] ?? $copy['body'] ?? ''),
                'strengths' => (string) ($copy['strengths'] ?? $copy['strength_sentence'] ?? ''),
                'tradeoffs' => (string) ($copy['tradeoffs'] ?? $copy['risk_sentence'] ?? ''),
                'context_boundary' => (string) ($copy['context_boundary'] ?? ''),
                'action_bridge' => (string) ($copy['action_bridge'] ?? $copy['action_hook'] ?? ''),
                'confidence' => (string) ($qualityPolicy['confidence_mode'] ?? 'low'),
            ];
        }, array_slice($synergies, 0, 3)));
    }

    private function reportId(ReportContext $context): string
    {
        $fixtureId = (string) ($context->meta['fixture_id'] ?? '');
        if ($fixtureId !== '') {
            return 'big5-report-engine-'.$fixtureId;
        }

        return 'big5-report-engine-'.sha1(json_encode($context->scoreVector()) ?: '');
    }

    /** @return array<string,mixed> */
    private function publicMeta(ReportContext $context): array
    {
        $meta = $context->meta;
        unset($meta['norms']);

        return $meta;
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
