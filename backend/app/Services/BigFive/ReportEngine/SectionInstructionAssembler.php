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
    public function assemble(ReportContext $context, array $blocksBySection, array $synergies, array $facetAnomalies, array $actionMatrix, array $qualityPolicy, array $normEvidence, array $registry): array
    {
        $blocksBySection['action_plan'] = $this->actionPlanBlocks($actionMatrix, $qualityPolicy, $registry);

        if (($qualityPolicy['prominent_notice'] ?? false) === true) {
            $notice = $this->qualityNoticeBlock($qualityPolicy, 'hero');
            array_unshift($blocksBySection['hero_summary'], $notice);
            array_unshift($blocksBySection['domain_deep_dive'], $this->qualityNoticeBlock($qualityPolicy, 'body_entry'));
        }

        foreach (array_slice($synergies, 0, 3) as $index => $synergy) {
            $sectionKey = 'core_portrait';
            $slot = 'composite_'.($index + 1);
            $blocksBySection[$sectionKey][] = new ResolvedBlock(
                blockUid: "{$sectionKey}.synergy.{$synergy->synergyId}.{$slot}",
                kind: 'callout',
                component: 'BigFiveSynergyCallout',
                blockId: "synergy_{$synergy->synergyId}_{$slot}",
                resolvedCopy: $this->compositeCopy($context, $synergy, $qualityPolicy),
                provenance: $this->provenanceRecorder->record(synergyRefs: ["synergies/{$synergy->synergyId}.json"]),
                analytics: [
                    'synergy_id' => $synergy->synergyId,
                    'synergy_rank' => $index + 1,
                    'slot' => $slot,
                    'priority_weight' => $synergy->priorityWeight,
                ],
            );
        }

        $blocksBySection['facet_details'] = $this->facetDetailsBlocks($context, $facetAnomalies, $registry);
        array_unshift($blocksBySection['norms_comparison'], $this->normEvidenceBlock($normEvidence));

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
            $blocks = array_map(
                fn (ResolvedBlock $block): ResolvedBlock => $this->withConfidence($block, $qualityPolicy),
                $blocksBySection[$sectionKey] ?? [],
            );
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
    private function actionPlanBlocks(array $actionMatrix, array $qualityPolicy, array $registry): array
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
                    'body' => (string) ($qualityPolicy['action_intro_body'] ?? data_get($copy, 'action_matrix.intro.body', '')),
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
                    'why_priority' => (string) ($actionMatrix['top_priority_reason'] ?? $qualityPolicy['action_priority_reason'] ?? ''),
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
                    'time_horizon_label' => (string) data_get($registry, 'shared.report_policy.action_labels.time_horizons.'.(string) ($rule['time_horizon'] ?? ''), ''),
                    'difficulty_label' => (string) data_get($registry, 'shared.report_policy.action_labels.difficulty_levels.'.(string) ($rule['difficulty_level'] ?? ''), ''),
                    'why_recommended' => (string) ($rule['why_recommended'] ?? ''),
                    'completion_signal' => (string) ($rule['completion_signal'] ?? ''),
                    'evidence' => (array) ($rule['evidence'] ?? []),
                    'related_insight_rule_ids' => (array) ($rule['related_insight_rule_ids'] ?? []),
                    'related_facet_rule_ids' => (array) ($rule['related_facet_rule_ids'] ?? []),
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

    /** @param array<string,mixed> $qualityPolicy */
    private function qualityNoticeBlock(array $qualityPolicy, string $slot): ResolvedBlock
    {
        return new ResolvedBlock(
            blockUid: "quality.notice.{$slot}",
            kind: 'callout',
            component: 'BigFiveQualityNotice',
            blockId: "quality_notice_{$slot}",
            resolvedCopy: [
                'title' => (string) ($qualityPolicy['notice_title'] ?? ''),
                'body' => (string) ($qualityPolicy['notice_body'] ?? ''),
                'why' => (string) ($qualityPolicy['notice_why'] ?? ''),
                'retest_label' => (string) ($qualityPolicy['retest_label'] ?? ''),
                'grade' => (string) ($qualityPolicy['grade'] ?? 'UNKNOWN'),
                'severity' => (string) ($qualityPolicy['notice_severity'] ?? 'warning'),
            ],
            provenance: $this->provenanceRecorder->record(['shared/report_policy.json']),
            analytics: ['slot' => $slot, 'quality_grade' => (string) ($qualityPolicy['grade'] ?? 'UNKNOWN')],
        );
    }

    /** @param array<string,mixed> $normEvidence */
    private function normEvidenceBlock(array $normEvidence): ResolvedBlock
    {
        return new ResolvedBlock(
            blockUid: 'norms_comparison.evidence',
            kind: 'metric_card',
            component: 'BigFiveNormEvidenceCard',
            blockId: 'norm_evidence_v1',
            resolvedCopy: $normEvidence,
            provenance: $this->provenanceRecorder->record(['shared/report_policy.json']),
            analytics: ['status' => (string) ($normEvidence['status'] ?? 'unavailable'), 'match_type' => (string) ($normEvidence['match_type'] ?? '')],
        );
    }

    /** @return array<string,mixed> */
    private function compositeCopy(ReportContext $context, SynergyMatch $synergy, array $qualityPolicy): array
    {
        $copy = $synergy->copy;
        $evidence = [];
        foreach ($synergy->components as $component) {
            if (preg_match('/^[OCEAN]$/', $component) === 1) {
                $evidence[] = ['type' => 'domain', 'code' => $component, 'percentile' => $context->domainPercentile($component), 'band' => $context->domainBand($component)];
            } elseif ($context->hasFacetPercentile($component)) {
                $evidence[] = ['type' => 'facet', 'code' => $component, 'percentile' => $context->facetPercentile($component)];
            }
        }

        return [
            'headline' => (string) ($copy['headline'] ?? $synergy->title),
            'combination' => $synergy->components,
            'evidence' => $evidence,
            'mechanism' => (string) ($copy['mechanism'] ?? $copy['body'] ?? ''),
            'strengths' => (string) ($copy['strengths'] ?? $copy['strength_sentence'] ?? ''),
            'tradeoffs' => (string) ($copy['tradeoffs'] ?? $copy['risk_sentence'] ?? ''),
            'context_boundary' => (string) ($copy['context_boundary'] ?? ''),
            'action_bridge' => (string) ($copy['action_bridge'] ?? $copy['action_hook'] ?? ''),
            'confidence' => (string) ($qualityPolicy['confidence_mode'] ?? 'low'),
            'rule_id' => $synergy->synergyId,
        ];
    }

    /** @param array<string,mixed> $qualityPolicy */
    private function withConfidence(ResolvedBlock $block, array $qualityPolicy): ResolvedBlock
    {
        return new ResolvedBlock(
            blockUid: $block->blockUid,
            kind: $block->kind,
            component: $block->component,
            blockId: $block->blockId,
            resolvedCopy: array_merge($block->resolvedCopy, [
                'confidence_mode' => (string) ($qualityPolicy['confidence_mode'] ?? 'low'),
                'tone_level' => (string) ($qualityPolicy['tone_level'] ?? 'cautious'),
                'interpretation_qualifier' => (string) ($qualityPolicy['interpretation_qualifier'] ?? ''),
            ]),
            provenance: $block->provenance,
            analytics: array_merge($block->analytics, ['quality_grade' => (string) ($qualityPolicy['grade'] ?? 'UNKNOWN')]),
        );
    }
}
