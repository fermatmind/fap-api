<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\FacetAnomalyMatch;
use App\Services\BigFive\ReportEngine\Contracts\ResolvedBlock;
use App\Services\BigFive\ReportEngine\Contracts\ResolvedSection;
use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;
use App\Services\BigFive\ReportEngine\Resolver\ProvenanceRecorder;

final class SectionInstructionAssembler
{
    private const SECTION_KEYS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
    ];

    public function __construct(
        private readonly ProvenanceRecorder $provenanceRecorder = new ProvenanceRecorder,
    ) {}

    /**
     * @param  array<string,list<ResolvedBlock>>  $blocksBySection
     * @param  list<SynergyMatch>  $synergies
     * @param  list<FacetAnomalyMatch>  $facetAnomalies
     * @param  array<string,mixed>  $registry
     * @return list<ResolvedSection>
     */
    public function assemble(array $blocksBySection, array $synergies, array $facetAnomalies, array $registry): array
    {
        foreach (array_slice($synergies, 0, 2) as $index => $synergy) {
            $sectionKey = $index === 0 ? 'core_portrait' : 'action_plan';
            $slot = $index === 0 ? 'synergy_primary' : 'synergy_action';
            $blocksBySection[$sectionKey][] = new ResolvedBlock(
                blockUid: "{$sectionKey}.synergy.{$synergy->synergyId}.{$slot}",
                kind: $index === 0 ? 'callout' : 'paragraph',
                component: 'BigFiveSynergyCallout',
                blockId: "synergy_{$synergy->synergyId}_{$slot}",
                resolvedCopy: $synergy->copy,
                provenance: $this->provenanceRecorder->record(synergyRefs: ["synergies/{$synergy->synergyId}.json"]),
                analytics: [
                    'synergy_id' => $synergy->synergyId,
                    'synergy_rank' => $index === 0 ? 'primary' : 'secondary',
                    'slot' => $slot,
                    'priority_weight' => $synergy->priorityWeight,
                ],
            );
        }

        foreach ($facetAnomalies as $anomaly) {
            foreach ($anomaly->sectionTargets as $sectionKey) {
                $blocksBySection[$sectionKey][] = new ResolvedBlock(
                    blockUid: "{$sectionKey}.facet.{$anomaly->ruleId}",
                    kind: 'facet_anomaly',
                    component: 'BigFiveFacetPrecisionBlock',
                    blockId: "facet_{$anomaly->ruleId}",
                    resolvedCopy: $anomaly->copy,
                    provenance: $this->provenanceRecorder->record(facetRefs: ["facet_precision/N.json#rules.{$anomaly->ruleId}"]),
                    analytics: [
                        'facet_code' => $anomaly->facetCode,
                        'delta_abs' => $anomaly->deltaAbs,
                    ],
                );
            }
        }

        $methodology = is_array($registry['shared']['methodology'] ?? null) ? $registry['shared']['methodology'] : [];
        if ($methodology !== []) {
            $blocksBySection['methodology_and_access'][] = new ResolvedBlock(
                blockUid: 'methodology_and_access.shared.methodology',
                kind: 'methodology',
                component: 'BigFiveMethodologyBlock',
                blockId: 'shared_methodology_v2',
                resolvedCopy: $methodology,
                provenance: $this->provenanceRecorder->record(['shared/methodology.json']),
                analytics: ['source' => 'shared'],
            );
        }

        $sections = [];
        foreach (self::SECTION_KEYS as $sectionKey) {
            $blocks = $blocksBySection[$sectionKey] ?? [];
            $sections[] = new ResolvedSection(
                sectionKey: $sectionKey,
                status: $blocks === [] ? 'not_populated_in_pr1' : 'populated',
                blocks: $blocks,
            );
        }

        return $sections;
    }
}
