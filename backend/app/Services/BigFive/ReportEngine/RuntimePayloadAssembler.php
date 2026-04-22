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
     * @param  array<string,list<ActionRuleMatch>>  $actionMatrix
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
                'selected_synergies' => array_map(static fn (SynergyMatch $match): array => $match->toArray(), $synergies),
                'facet_anomalies' => array_map(static fn (FacetAnomalyMatch $match): array => $match->toArray(), $facetAnomalies),
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
                'registry_scope' => 'all_traits_atomic_modifier_pr2',
                'limited_rollouts' => [
                    'synergy_traits' => ['N'],
                    'facet_precision_traits' => ['N'],
                    'action_rule_scope' => 'N scenario rules only',
                ],
            ],
        ];
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
     * @param  array<string,list<ActionRuleMatch>>  $actionMatrix
     * @return array<string,list<array<string,mixed>>>
     */
    private function actionMatrixToArray(array $actionMatrix): array
    {
        $out = [];
        foreach ($actionMatrix as $scenario => $matches) {
            $out[$scenario] = array_map(static fn (ActionRuleMatch $match): array => $match->toArray(), $matches);
        }

        return $out;
    }
}
