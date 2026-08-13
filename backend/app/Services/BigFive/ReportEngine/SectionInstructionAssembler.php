<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\FacetAnomalyMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
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
     * @param  array<string,mixed>  $actionMatrix
     * @param  array<string,mixed>  $registry
     * @return list<ResolvedSection>
     */
    public function assemble(ReportContext $context, array $blocksBySection, array $synergies, array $facetAnomalies, array $actionMatrix, array $registry): array
    {
        $blocksBySection['action_plan'] = $this->actionPlanBlocks($actionMatrix, $registry);

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

        $blocksBySection['facet_details'] = $this->facetDetailsBlocks($context, $facetAnomalies, $registry);

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

    /**
     * @param  array<string,mixed>  $actionMatrix
     * @return list<ResolvedBlock>
     */
    private function actionPlanBlocks(array $actionMatrix, array $registry): array
    {
        $copy = is_array($registry['shared']['runtime_copy'] ?? null) ? $registry['shared']['runtime_copy'] : [];
        $scenarios = is_array($actionMatrix['scenarios'] ?? null) ? $actionMatrix['scenarios'] : [];
        $topScenario = (string) ($actionMatrix['top_priority_scenario'] ?? '');
        $topScenarioPayload = null;
        foreach ($scenarios as $scenario) {
            if (! is_array($scenario) || (string) ($scenario['scenario_key'] ?? '') !== $topScenario) {
                continue;
            }
            $topScenarioPayload = $scenario;
            break;
        }

        $blocks = [
            new ResolvedBlock(
                blockUid: 'action_plan.matrix_intro',
                kind: 'paragraph',
                component: 'BigFiveActionMatrixIntro',
                blockId: 'action_matrix_intro_v1',
                resolvedCopy: [
                    'title' => (string) data_get($copy, 'action_matrix.intro.title', ''),
                    'body' => (string) data_get($copy, 'action_matrix.intro.body', ''),
                ],
                provenance: $this->provenanceRecorder->record(actionRefs: ['action_rules/*']),
                analytics: ['slot' => 'action_matrix_intro'],
            ),
        ];

        if (is_array($topScenarioPayload)) {
            $blocks[] = new ResolvedBlock(
                blockUid: "action_plan.matrix_top_priority.{$topScenario}",
                kind: 'callout',
                component: 'BigFiveActionMatrixTopPriority',
                blockId: "action_matrix_top_priority_{$topScenario}",
                resolvedCopy: [
                    'title' => (string) data_get($copy, 'action_matrix.priority_prefix', '').(string) ($topScenarioPayload['title'] ?? $topScenario),
                    'body' => (string) data_get($copy, 'action_matrix.priority_body', ''),
                    'scenario_key' => $topScenario,
                ],
                provenance: $this->provenanceRecorder->record(actionRefs: ["action_rules/{$topScenario}.json"]),
                analytics: ['top_priority_scenario' => $topScenario],
            );
        }

        foreach ($scenarios as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }
            $scenarioKey = (string) ($scenario['scenario_key'] ?? '');
            $selectedRules = is_array($scenario['selected_rules'] ?? null) ? $scenario['selected_rules'] : [];
            $items = [];
            foreach (['continue', 'start', 'stop', 'observe'] as $bucket) {
                $rule = $selectedRules[$bucket] ?? null;
                if ($rule instanceof ActionRuleMatch) {
                    $rule = $rule->toArray();
                }
                if (! is_array($rule)) {
                    continue;
                }
                $items[] = [
                    'bucket' => $bucket,
                    'label' => (string) data_get($copy, "action_matrix.bucket_labels.{$bucket}", $bucket),
                    'rule_id' => (string) ($rule['rule_id'] ?? ''),
                    'title' => (string) ($rule['title'] ?? ''),
                    'body' => (string) ($rule['body'] ?? ''),
                    'difficulty_level' => (string) ($rule['difficulty_level'] ?? ''),
                    'time_horizon' => (string) ($rule['time_horizon'] ?? ''),
                ];
            }
            if ($items === []) {
                continue;
            }

            $title = (string) ($scenario['title'] ?? $scenarioKey);
            $blocks[] = new ResolvedBlock(
                blockUid: "action_plan.matrix_scenario.{$scenarioKey}",
                kind: 'bullets',
                component: 'BigFiveActionMatrixScenarioBullets',
                blockId: "action_matrix_scenario_{$scenarioKey}",
                resolvedCopy: [
                    'title' => $title.(string) data_get($copy, 'action_matrix.next_steps_suffix', ''),
                    'scenario_key' => $scenarioKey,
                    'items' => $items,
                ],
                provenance: $this->provenanceRecorder->record(actionRefs: ["action_rules/{$scenarioKey}.json"]),
                analytics: [
                    'scenario_key' => $scenarioKey,
                    'selected_rule_count' => count($items),
                    'buckets' => array_map(static fn (array $item): string => (string) $item['bucket'], $items),
                ],
            );
        }

        return $blocks;
    }

    /**
     * @param  list<FacetAnomalyMatch>  $facetAnomalies
     * @param  array<string,mixed>  $registry
     * @return list<ResolvedBlock>
     */
    private function facetDetailsBlocks(ReportContext $context, array $facetAnomalies, array $registry): array
    {
        $copy = is_array($registry['shared']['runtime_copy'] ?? null) ? $registry['shared']['runtime_copy'] : [];
        $blocks = [
            new ResolvedBlock(
                blockUid: 'facet_details.precision_intro',
                kind: 'paragraph',
                component: 'BigFiveFacetPrecisionIntro',
                blockId: 'facet_precision_intro_v1',
                resolvedCopy: [
                    'title' => (string) data_get($copy, 'facet_details.intro.title', ''),
                    'body' => (string) data_get($copy, 'facet_details.intro.body', ''),
                ],
                provenance: $this->provenanceRecorder->record(facetRefs: ['facet_glossary/*']),
                analytics: ['slot' => 'facet_precision_intro'],
            ),
        ];

        foreach (array_slice($facetAnomalies, 0, 3) as $index => $anomaly) {
            $blocks[] = new ResolvedBlock(
                blockUid: "facet_details.anomaly.{$anomaly->ruleId}",
                kind: 'metric_card',
                component: 'BigFiveFacetAnomalyCard',
                blockId: "facet_anomaly_{$anomaly->ruleId}",
                resolvedCopy: array_merge($anomaly->copy, [
                    'domain_code' => $anomaly->domainCode,
                    'facet_code' => $anomaly->facetCode,
                    'facet_codes' => $anomaly->facetCodes,
                    'domain_percentile' => $anomaly->domainPercentile,
                    'facet_percentile' => $anomaly->facetPercentile,
                    'delta_abs' => $anomaly->deltaAbs,
                ]),
                provenance: $this->provenanceRecorder->record(facetRefs: ["facet_precision/{$anomaly->domainCode}.json#rules.{$anomaly->ruleId}"]),
                analytics: [
                    'facet_code' => $anomaly->facetCode,
                    'facet_codes' => $anomaly->facetCodes,
                    'domain_code' => $anomaly->domainCode,
                    'delta_abs' => $anomaly->deltaAbs,
                    'rank' => $index + 1,
                    'is_compound' => $anomaly->isCompound,
                ],
            );
        }

        if (count($facetAnomalies) > 3) {
            $overflow = array_map(static fn (FacetAnomalyMatch $anomaly): array => [
                'rule_id' => $anomaly->ruleId,
                'domain_code' => $anomaly->domainCode,
                'facet_codes' => $anomaly->facetCodes,
                'title' => (string) ($anomaly->copy['title'] ?? ''),
            ], array_slice($facetAnomalies, 3));

            $blocks[] = new ResolvedBlock(
                blockUid: 'facet_details.anomaly_overflow',
                kind: 'callout',
                component: 'BigFiveFacetAnomalyOverflowCallout',
                blockId: 'facet_anomaly_overflow_v1',
                resolvedCopy: [
                    'title' => (string) data_get($copy, 'facet_details.overflow.title', ''),
                    'body' => (string) data_get($copy, 'facet_details.overflow.body', ''),
                    'items' => $overflow,
                ],
                provenance: $this->provenanceRecorder->record(facetRefs: array_map(
                    static fn (FacetAnomalyMatch $anomaly): string => "facet_precision/{$anomaly->domainCode}.json#rules.{$anomaly->ruleId}",
                    array_slice($facetAnomalies, 3),
                )),
                analytics: ['overflow_count' => count($facetAnomalies) - 3],
            );
        }

        foreach ($this->facetGlossaryRows($context, $registry) as $row) {
            $blocks[] = $row;
        }

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return list<ResolvedBlock>
     */
    private function facetGlossaryRows(ReportContext $context, array $registry): array
    {
        $rows = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $traitCode) {
            $facets = $registry['facet_glossary'][$traitCode]['facets'] ?? [];
            if (! is_array($facets)) {
                continue;
            }
            foreach ($facets as $facet) {
                if (! is_array($facet)) {
                    continue;
                }
                $facetCode = (string) ($facet['facet_code'] ?? '');
                $hasPercentile = $context->hasFacetPercentile($facetCode);
                $percentile = $hasPercentile ? $context->facetPercentile($facetCode) : null;
                $band = $percentile === null ? 'not_available' : $this->bandFor($percentile);
                $rows[] = new ResolvedBlock(
                    blockUid: "facet_details.glossary.{$facetCode}",
                    kind: 'table_row',
                    component: 'BigFiveFacetGlossaryRow',
                    blockId: "facet_glossary_{$facetCode}",
                    resolvedCopy: [
                        'trait_code' => $traitCode,
                        'facet_code' => $facetCode,
                        'label_zh' => (string) ($facet['label_zh'] ?? ''),
                        'percentile' => $percentile,
                        'band' => $band,
                        'gloss' => (string) ($facet['gloss'] ?? ''),
                        'daily_meaning' => (string) ($facet['daily_meaning'] ?? ''),
                        'why_it_matters' => (string) ($facet['why_it_matters'] ?? ''),
                    ],
                    provenance: $this->provenanceRecorder->record(facetRefs: ["facet_glossary/{$traitCode}.json#facets.{$facetCode}"]),
                    analytics: [
                        'trait_code' => $traitCode,
                        'facet_code' => $facetCode,
                        'percentile' => $percentile,
                        'band' => $band,
                        'has_percentile' => $hasPercentile,
                    ],
                );
            }
        }

        return $rows;
    }

    private function bandFor(int $percentile): string
    {
        return match (true) {
            $percentile <= 25 => 'low',
            $percentile <= 39 => 'low_mid',
            $percentile <= 59 => 'mid',
            $percentile <= 79 => 'high_mid',
            default => 'high',
        };
    }
}
