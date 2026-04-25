<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Enneagram\EnneagramPublicProjectionService;
use App\Services\Enneagram\Registry\RegistryValidator;
use RuntimeException;

final class EnneagramReportComposer
{
    private const REPORT_V2_SCHEMA = 'enneagram.report.v2';

    private const REPORT_V2_ENGINE_VERSION = 'enneagram_report_engine.v2';

    /**
     * @var array<string,array{title:array{zh:string,en:string},purpose:array{zh:string,en:string},modules:list<string>}>
     */
    private const PAGE_SPECS = [
        'page_1_result_overview' => [
            'title' => ['zh' => '结果总览', 'en' => 'Result Overview'],
            'purpose' => ['zh' => '输出当前结果结构、辨析边界和主候选阅读方式。', 'en' => 'Expose the current result structure, interpretation boundary, and candidate reading mode.'],
            'modules' => [
                'instant_summary',
                'top3_cards',
                'all9_profile',
                'confidence_band_card',
                'dominance_gap_card',
                'close_call_card',
                'blind_spot_card',
                'center_summary',
                'stance_summary',
                'harmonic_summary',
                'wing_hint_visual',
                'methodology_boundary_card',
                'diffuse_boundary',
                'low_quality_boundary',
            ],
        ],
        'page_2_work_reality' => [
            'title' => ['zh' => '工作现实', 'en' => 'Work Reality'],
            'purpose' => ['zh' => '输出工作风格、协作优势、摩擦点和 workplace 占位位。', 'en' => 'Expose work style, collaboration strengths, friction points, and workplace placeholders.'],
            'modules' => [
                'work_style_summary',
                'collaboration_strengths',
                'collaboration_friction',
                'leadership_pattern',
                'managed_by_others',
                'workplace_trigger_points',
                'context_mode_placeholder',
            ],
        ],
        'page_3_growth_spectrum' => [
            'title' => ['zh' => '成长光谱', 'en' => 'Growth Spectrum'],
            'purpose' => ['zh' => '输出成长轴、代价表达、恢复动作和 theory placeholders。', 'en' => 'Expose growth axis, cost expression, recovery action, and theory placeholders.'],
            'modules' => [
                'growth_axis',
                'strength_expression',
                'cost_expression',
                'stress_trigger',
                'recovery_action',
                'state_spectrum',
                'arrow_growth_reference_placeholder',
            ],
        ],
        'page_4_relationship_conflict' => [
            'title' => ['zh' => '关系与冲突', 'en' => 'Relationships and Conflict'],
            'purpose' => ['zh' => '输出关系需要、冲突脚本和沟通说明书。', 'en' => 'Expose relationship needs, conflict scripts, and communication guidance.'],
            'modules' => [
                'relationship_need',
                'relationship_strengths',
                'misread_by_others',
                'conflict_script',
                'communication_manual',
                'blind_spot_in_relationship',
            ],
        ],
        'page_5_method_observation_next' => [
            'title' => ['zh' => '方法、观察与下一步', 'en' => 'Method, Observation, and Next Steps'],
            'purpose' => ['zh' => '输出方法边界、七天观察、样例和 Technical Note 入口。', 'en' => 'Expose method boundaries, seven-day observation, sample report, and the Technical Note entry point.'],
            'modules' => [
                'method_boundary',
                'seven_day_observation',
                'resonance_feedback_placeholder',
                'history_share_retake_placeholder',
                'form_recommendation',
                'sample_report_link',
                'technical_note_link',
            ],
        ],
    ];

    public function __construct(
        private readonly EnneagramPublicProjectionService $projectionService,
        private readonly EnneagramPackLoader $packLoader,
        private readonly RegistryValidator $registryValidator,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $locked = $variant === ReportAccess::VARIANT_FREE;
        $locale = trim((string) ($attempt->locale ?? $ctx['locale'] ?? config('content_packs.default_locale', 'zh-CN')));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $scoreResult = $this->extractScoreResult($result);
        if ($scoreResult === []) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'ENNEAGRAM score result missing.',
                'status' => 500,
            ];
        }

        $projection = $this->projectionService->build($scoreResult, $locale, $variant, $locked);
        $projectionV2 = $this->projectionService->buildV2($scoreResult, $locale, $variant, $locked);
        $reportV2 = $this->buildReportV2($projectionV2, $locale);
        $sections = is_array($projection['sections'] ?? null) ? $projection['sections'] : [];

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'enneagram.report.v1',
                'scale_code' => 'ENNEAGRAM',
                'variant' => $variant,
                'primary_type' => (string) ($projection['primary_type'] ?? ''),
                'primary_label' => (string) ($projection['primary_label'] ?? ''),
                'scores' => is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [],
                'ranked_types' => is_array($projection['ranked_types'] ?? null) ? $projection['ranked_types'] : [],
                'scoring' => is_array($projection['scoring'] ?? null) ? $projection['scoring'] : [],
                'analysis' => is_array($projection['analysis'] ?? null) ? $projection['analysis'] : [],
                'display' => is_array($projection['display'] ?? null) ? $projection['display'] : [],
                'confidence' => is_array($projection['confidence'] ?? null) ? $projection['confidence'] : [],
                'quality' => is_array($projection['quality'] ?? null) ? $projection['quality'] : [],
                'sections' => $sections,
                '_meta' => [
                    'enneagram_public_projection_v1' => $projection,
                    'enneagram_public_projection_v2' => $projectionV2,
                    'enneagram_report_v2' => $reportV2,
                    'snapshot_binding_v1' => $this->buildSnapshotBinding($projectionV2),
                ],
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload,
            data_get($payload, 'breakdown_json.score_result'),
            data_get($payload, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && strtoupper(trim((string) ($candidate['scale_code'] ?? ''))) === 'ENNEAGRAM') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildSnapshotBinding(array $projectionV2): array
    {
        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => data_get($projectionV2, 'form.form_code'),
            'form_kind' => data_get($projectionV2, 'form.form_kind'),
            'score_method' => data_get($projectionV2, 'form.score_method'),
            'scoring_spec_version' => data_get($projectionV2, 'form.scoring_spec_version'),
            'score_space_version' => data_get($projectionV2, 'form.score_space_version'),
            'projection_version' => data_get($projectionV2, 'algorithmic_meta.projection_version'),
            'report_schema_version' => data_get($projectionV2, 'algorithmic_meta.report_schema_version'),
            'report_engine_version' => data_get($projectionV2, 'algorithmic_meta.report_engine_version'),
            'close_call_rule_version' => data_get($projectionV2, 'algorithmic_meta.close_call_rule_version'),
            'confidence_policy_version' => data_get($projectionV2, 'algorithmic_meta.confidence_policy_version'),
            'quality_policy_version' => data_get($projectionV2, 'algorithmic_meta.quality_policy_version'),
            'technical_note_version' => data_get($projectionV2, 'algorithmic_meta.technical_note_version'),
            'interpretation_context_id' => data_get($projectionV2, 'content_binding.interpretation_context_id'),
            'content_release_hash' => data_get($projectionV2, 'content_binding.content_release_hash'),
            'content_release_hash_status' => data_get($projectionV2, 'content_binding.content_release_hash_status'),
            'content_snapshot_id' => data_get($projectionV2, 'content_binding.content_snapshot_id'),
            'content_snapshot_hash' => data_get($projectionV2, 'content_binding.content_snapshot_hash'),
            'content_snapshot_status' => data_get($projectionV2, 'content_binding.content_snapshot_status'),
            'compare_compatibility_group' => data_get($projectionV2, 'methodology.compare_compatibility_group'),
            'cross_form_comparable' => data_get($projectionV2, 'methodology.cross_form_comparable'),
        ];
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildReportV2(array $projectionV2, string $locale): array
    {
        $language = $this->normalizeLanguage($locale);

        try {
            $pack = $this->loadValidatedRegistryPack();
            $indexes = $this->indexRegistryPack($pack);
            $pages = $this->buildPages($projectionV2, $indexes, $language);
            $modules = [];
            foreach ($pages as $page) {
                foreach ((array) ($page['modules'] ?? []) as $module) {
                    if (is_array($module)) {
                        $modules[] = $module;
                    }
                }
            }

            return [
                'schema_version' => self::REPORT_V2_SCHEMA,
                'scale_code' => 'ENNEAGRAM',
                'form' => [
                    'form_code' => data_get($projectionV2, 'form.form_code'),
                    'form_kind' => data_get($projectionV2, 'form.form_kind'),
                    'methodology_variant' => data_get($projectionV2, 'form.methodology_variant'),
                ],
                'registry' => [
                    'registry_version' => data_get($pack, 'manifest.registry_version'),
                    'registry_release_hash' => data_get($pack, 'release_hash'),
                    'content_maturity' => $this->registryPackContentMaturity($indexes),
                    'release_id' => data_get($pack, 'manifest.release_id'),
                ],
                'classification' => [
                    'interpretation_scope' => data_get($projectionV2, 'classification.interpretation_scope'),
                    'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                    'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
                ],
                'pages' => $pages,
                'modules' => $modules,
                'provenance' => [
                    'projection_version' => data_get($projectionV2, 'algorithmic_meta.projection_version'),
                    'report_schema_version' => self::REPORT_V2_SCHEMA,
                    'report_engine_version' => self::REPORT_V2_ENGINE_VERSION,
                    'interpretation_context_id' => data_get($projectionV2, 'content_binding.interpretation_context_id'),
                    'content_release_hash' => data_get($projectionV2, 'content_binding.content_release_hash'),
                    'content_snapshot_status' => data_get($projectionV2, 'content_binding.content_snapshot_status'),
                    'registry_release_hash' => data_get($pack, 'release_hash'),
                    'close_call_rule_version' => data_get($projectionV2, 'algorithmic_meta.close_call_rule_version'),
                    'confidence_policy_version' => data_get($projectionV2, 'algorithmic_meta.confidence_policy_version'),
                    'quality_policy_version' => data_get($projectionV2, 'algorithmic_meta.quality_policy_version'),
                    'policy_refs' => [
                        'classification.interpretation_scope',
                        'classification.confidence_level',
                        'classification.interpretation_reason',
                        'algorithmic_meta.close_call_rule_version',
                        'algorithmic_meta.confidence_policy_version',
                        'algorithmic_meta.quality_policy_version',
                    ],
                ],
            ];
        } catch (\Throwable $error) {
            return $this->buildUnavailableReportV2($projectionV2, $language, $error);
        }
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildUnavailableReportV2(array $projectionV2, string $language, \Throwable $error): array
    {
        $pages = [];
        foreach (array_keys(self::PAGE_SPECS) as $pageKey) {
            $pages[] = [
                'page_key' => $pageKey,
                'title' => $this->pageSpec($pageKey, 'title', $language),
                'purpose' => $this->pageSpec($pageKey, 'purpose', $language),
                'modules' => [],
                'visibility' => 'unavailable',
                'source_registry_refs' => [],
            ];
        }

        return [
            'schema_version' => self::REPORT_V2_SCHEMA,
            'scale_code' => 'ENNEAGRAM',
            'form' => [
                'form_code' => data_get($projectionV2, 'form.form_code'),
                'form_kind' => data_get($projectionV2, 'form.form_kind'),
                'methodology_variant' => data_get($projectionV2, 'form.methodology_variant'),
            ],
            'registry' => [
                'registry_version' => 'unavailable',
                'registry_release_hash' => null,
                'content_maturity' => 'unavailable',
                'release_id' => null,
            ],
            'classification' => [
                'interpretation_scope' => data_get($projectionV2, 'classification.interpretation_scope'),
                'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
            ],
            'pages' => $pages,
            'modules' => [],
            'provenance' => [
                'projection_version' => data_get($projectionV2, 'algorithmic_meta.projection_version'),
                'report_schema_version' => self::REPORT_V2_SCHEMA,
                'report_engine_version' => self::REPORT_V2_ENGINE_VERSION,
                'interpretation_context_id' => data_get($projectionV2, 'content_binding.interpretation_context_id'),
                'content_release_hash' => data_get($projectionV2, 'content_binding.content_release_hash'),
                'content_snapshot_status' => data_get($projectionV2, 'content_binding.content_snapshot_status'),
                'registry_release_hash' => null,
                'close_call_rule_version' => data_get($projectionV2, 'algorithmic_meta.close_call_rule_version'),
                'confidence_policy_version' => data_get($projectionV2, 'algorithmic_meta.confidence_policy_version'),
                'quality_policy_version' => data_get($projectionV2, 'algorithmic_meta.quality_policy_version'),
                'build_status' => 'registry_unavailable',
                'build_error' => $error->getMessage(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadValidatedRegistryPack(): array
    {
        $pack = $this->packLoader->loadRegistryPack();
        $errors = $this->registryValidator->validate($pack);
        if ($errors !== []) {
            throw new RuntimeException('ENNEAGRAM registry pack invalid: '.implode(' | ', $errors));
        }

        return $pack;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function indexRegistryPack(array $pack): array
    {
        $registries = is_array($pack['registries'] ?? null) ? $pack['registries'] : [];

        return [
            'pack' => $pack,
            'manifest' => is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [],
            'registry_meta' => array_map(fn (array $registry): array => [
                'registry_key' => (string) ($registry['registry_key'] ?? ''),
                'content_maturity' => (string) ($registry['content_maturity'] ?? 'scaffold'),
                'evidence_level' => (string) ($registry['evidence_level'] ?? 'descriptive'),
                'fallback_policy' => (string) ($registry['fallback_policy'] ?? 'fallback_to_generic'),
            ], $registries),
            'type_entries' => $this->keyListBy($registries['enneagram_type_registry']['entries'] ?? [], 'type_id'),
            'pair_entries' => $this->keyListBy($registries['enneagram_pair_registry']['entries'] ?? [], 'pair_key'),
            'group_entries' => $this->keyGroupEntries($registries['enneagram_group_registry']['entries'] ?? []),
            'scenario_entries' => $this->keyListBy($registries['enneagram_scenario_registry']['entries'] ?? [], 'module_key'),
            'state_entry' => is_array(data_get($registries, 'enneagram_state_registry.entries.0')) ? data_get($registries, 'enneagram_state_registry.entries.0') : [],
            'theory_entries' => $this->keyListBy($registries['enneagram_theory_hint_registry']['entries'] ?? [], 'theory_key'),
            'observation_entries' => $this->keyListBy($registries['enneagram_observation_registry']['entries'] ?? [], 'day'),
            'method_entries' => $this->keyListBy($registries['enneagram_method_registry']['entries'] ?? [], 'method_key'),
            'ui_entries' => is_array($registries['enneagram_ui_copy_registry']['entries'] ?? null) ? $registries['enneagram_ui_copy_registry']['entries'] : [],
            'sample_entries' => is_array($registries['enneagram_sample_report_registry']['entries'] ?? null) ? $registries['enneagram_sample_report_registry']['entries'] : [],
            'technical_entries' => $this->keyListBy($registries['enneagram_technical_note_registry']['entries'] ?? [], 'section_key'),
        ];
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return list<array<string,mixed>>
     */
    private function buildPages(array $projectionV2, array $indexes, string $language): array
    {
        $pages = [];
        foreach (array_keys(self::PAGE_SPECS) as $pageKey) {
            $modules = [];
            foreach ((array) (self::PAGE_SPECS[$pageKey]['modules'] ?? []) as $moduleKey) {
                $modules[] = $this->buildModule($moduleKey, $projectionV2, $indexes, $language);
            }

            $pages[] = [
                'page_key' => $pageKey,
                'title' => $this->pageSpec($pageKey, 'title', $language),
                'purpose' => $this->pageSpec($pageKey, 'purpose', $language),
                'modules' => $modules,
                'visibility' => 'visible',
                'source_registry_refs' => $this->pageRegistryRefs($modules),
            ];
        }

        return $pages;
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildModule(string $moduleKey, array $projectionV2, array $indexes, string $language): array
    {
        return match ($moduleKey) {
            'instant_summary' => $this->buildInstantSummaryModule($projectionV2, $indexes, $language),
            'top3_cards' => $this->buildTop3CardsModule($projectionV2, $indexes),
            'all9_profile' => $this->buildAll9ProfileModule($projectionV2),
            'confidence_band_card' => $this->buildConfidenceBandModule($projectionV2, $indexes),
            'dominance_gap_card' => $this->buildDominanceGapModule($projectionV2, $indexes),
            'close_call_card' => $this->buildCloseCallModule($projectionV2, $indexes),
            'blind_spot_card' => $this->buildBlindSpotModule($projectionV2, $indexes, 'blind_spot_card'),
            'center_summary' => $this->buildUnavailableShapeModule($projectionV2, $indexes, 'center_summary', 'summary_card', 'dynamics.center_scores'),
            'stance_summary' => $this->buildUnavailableShapeModule($projectionV2, $indexes, 'stance_summary', 'summary_card', 'dynamics.stance_scores'),
            'harmonic_summary' => $this->buildUnavailableShapeModule($projectionV2, $indexes, 'harmonic_summary', 'summary_card', 'dynamics.harmonic_scores'),
            'wing_hint_visual' => $this->buildWingHintModule($projectionV2, $indexes),
            'methodology_boundary_card' => $this->buildMethodologyBoundaryModule($projectionV2, $indexes, 'methodology_boundary_card'),
            'diffuse_boundary' => $this->buildDiffuseBoundaryModule($projectionV2, $indexes),
            'low_quality_boundary' => $this->buildLowQualityBoundaryModule($projectionV2, $indexes),
            'work_style_summary' => $this->buildScenarioModule($projectionV2, $indexes, 'work_style_summary', 'scenario_card', ['content.summary_ref' => 'work_summary']),
            'collaboration_strengths' => $this->buildScenarioModule($projectionV2, $indexes, 'collaboration_strengths', 'scenario_card', ['content.summary_ref' => 'work_summary']),
            'collaboration_friction' => $this->buildScenarioModule($projectionV2, $indexes, 'collaboration_friction', 'scenario_card', ['content.summary_ref' => 'internal_tension']),
            'leadership_pattern' => $this->buildScenarioModule($projectionV2, $indexes, 'leadership_pattern', 'scenario_card', ['content.summary_ref' => 'work_summary']),
            'managed_by_others' => $this->buildScenarioModule($projectionV2, $indexes, 'managed_by_others', 'scenario_card', ['content.summary_ref' => 'relationship_summary']),
            'workplace_trigger_points' => $this->buildPlaceholderModule($projectionV2, $indexes, 'workplace_trigger_points', 'placeholder_card', 'registry_entry_not_shipped_for_workplace'),
            'context_mode_placeholder' => $this->buildPlaceholderModule($projectionV2, $indexes, 'context_mode_placeholder', 'placeholder_card', 'workplace_context_mode_not_enabled'),
            'growth_axis' => $this->buildTypeDrivenModule($projectionV2, $indexes, 'growth_axis', 'summary_card', 'growth_summary'),
            'strength_expression' => $this->buildGroupOverlayModule($projectionV2, $indexes, 'strength_expression', 'strength_expression'),
            'cost_expression' => $this->buildGroupOverlayModule($projectionV2, $indexes, 'cost_expression', 'cost_expression'),
            'stress_trigger' => $this->buildTypeDrivenModule($projectionV2, $indexes, 'stress_trigger', 'summary_card', 'internal_tension'),
            'recovery_action' => $this->buildStateModule($projectionV2, $indexes, 'recovery_action'),
            'state_spectrum' => $this->buildStateModule($projectionV2, $indexes, 'state_spectrum'),
            'arrow_growth_reference_placeholder' => $this->buildTheoryModule($projectionV2, $indexes, 'arrow_growth_reference_placeholder', 'placeholder_card'),
            'relationship_need' => $this->buildScenarioModule($projectionV2, $indexes, 'relationship_need', 'scenario_card', ['content.summary_ref' => 'relationship_summary']),
            'relationship_strengths' => $this->buildTypeDrivenModule($projectionV2, $indexes, 'relationship_strengths', 'summary_card', 'relationship_summary'),
            'misread_by_others' => $this->buildTypeDrivenModule($projectionV2, $indexes, 'misread_by_others', 'summary_card', 'surface_impression'),
            'conflict_script' => $this->buildScenarioModule($projectionV2, $indexes, 'conflict_script', 'scenario_card', ['content.summary_ref' => 'internal_tension']),
            'communication_manual' => $this->buildScenarioModule($projectionV2, $indexes, 'communication_manual', 'scenario_card', ['content.summary_ref' => 'surface_impression']),
            'blind_spot_in_relationship' => $this->buildBlindSpotModule($projectionV2, $indexes, 'blind_spot_in_relationship'),
            'method_boundary' => $this->buildMethodologyBoundaryModule($projectionV2, $indexes, 'method_boundary'),
            'seven_day_observation' => $this->buildObservationModule($projectionV2, $indexes),
            'resonance_feedback_placeholder' => $this->buildPlaceholderModule($projectionV2, $indexes, 'resonance_feedback_placeholder', 'placeholder_card', 'observation_api_not_shipped'),
            'history_share_retake_placeholder' => $this->buildPlaceholderModule($projectionV2, $indexes, 'history_share_retake_placeholder', 'placeholder_card', 'history_share_surface_not_shipped'),
            'form_recommendation' => $this->buildFormRecommendationModule($projectionV2, $indexes),
            'sample_report_link' => $this->buildSampleReportModule($projectionV2, $indexes),
            'technical_note_link' => $this->buildTechnicalNoteModule($projectionV2, $indexes),
            default => $this->buildPlaceholderModule($projectionV2, $indexes, $moduleKey, 'placeholder_card', 'module_builder_not_implemented'),
        };
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildInstantSummaryModule(array $projectionV2, array $indexes, string $language): array
    {
        $scope = $this->state($projectionV2);
        $uiKey = 'instant_summary.'.$scope;
        $ui = is_array($indexes['ui_entries'][$uiKey] ?? null) ? $indexes['ui_entries'][$uiKey] : [];
        $formBadgeKey = $this->formVariant($projectionV2) === 'e105' ? 'form_badge.e105' : 'form_badge.fc144';
        $formBadge = is_array($indexes['ui_entries'][$formBadgeKey] ?? null) ? $indexes['ui_entries'][$formBadgeKey] : [];
        $topTypes = array_slice((array) data_get($projectionV2, 'scores.top_types', []), 0, 3);

        return $this->module(
            'instant_summary',
            'summary_card',
            'visible',
            'all',
            [
                'title' => (string) ($ui['title_template'] ?? ''),
                'body' => (string) ($ui['body_template'] ?? ''),
                'primary_candidate' => data_get($projectionV2, 'scores.primary_candidate'),
                'secondary_candidate' => data_get($projectionV2, 'scores.second_candidate'),
                'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                'interpretation_scope' => $scope,
                'hard_primary_language' => in_array($scope, ['clear', 'close_call'], true),
                'form_badge' => [
                    'label' => (string) ($formBadge['label'] ?? ''),
                    'body' => (string) ($formBadge['body_template'] ?? ''),
                ],
                'top_candidates' => array_values(array_map(
                    fn (array $row): array => [
                        'type' => (string) ($row['type'] ?? ''),
                        'display_score' => $row['display_score'] ?? null,
                        'candidate_role' => (string) ($row['candidate_role'] ?? ''),
                    ],
                    $topTypes
                )),
                'locale' => $language,
            ],
            ['scores.primary_candidate', 'scores.second_candidate', 'classification.confidence_level', 'classification.interpretation_scope'],
            ['enneagram_ui_copy_registry:'.$uiKey, 'enneagram_ui_copy_registry:'.$formBadgeKey],
            ['classification.interpretation_scope', 'classification.confidence_level'],
            $this->mergeEntryMeta([$ui, $formBadge], $this->registryMeta($indexes, 'enneagram_ui_copy_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildTop3CardsModule(array $projectionV2, array $indexes): array
    {
        $cards = [];
        $registryRefs = [];
        foreach ((array) data_get($projectionV2, 'scores.top_types', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $entry = $this->typeEntry($indexes, $type);
            $registryRefs[] = 'enneagram_type_registry:'.$type;
            $cards[] = [
                'type' => $type,
                'candidate_role' => (string) ($row['candidate_role'] ?? ''),
                'display_score' => $row['display_score'] ?? null,
                'score_source' => $row['score_source'] ?? null,
                'type_name_cn' => $entry['type_name_cn'] ?? null,
                'type_name_en' => $entry['type_name_en'] ?? null,
                'core_logic' => $entry['core_logic'] ?? null,
                'surface_impression' => $entry['surface_impression'] ?? null,
                'validation_hook' => $entry['validation_hook'] ?? null,
                'work_summary' => $entry['work_summary'] ?? null,
                'growth_summary' => $entry['growth_summary'] ?? null,
                'relationship_summary' => $entry['relationship_summary'] ?? null,
            ];
        }

        return $this->module(
            'top3_cards',
            'cards_grid',
            'visible',
            'all',
            ['cards' => $cards],
            ['scores.top_types'],
            $registryRefs,
            [],
            $this->registryMeta($indexes, 'enneagram_type_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @return array<string,mixed>
     */
    private function buildAll9ProfileModule(array $projectionV2): array
    {
        return $this->module(
            'all9_profile',
            'profile_chart',
            'visible',
            'all',
            ['items' => array_values((array) data_get($projectionV2, 'scores.all9_profile', []))],
            ['scores.all9_profile'],
            [],
            [],
            ['content_maturity' => 'derived', 'evidence_level' => 'computed', 'fallback_policy' => 'required']
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildConfidenceBandModule(array $projectionV2, array $indexes): array
    {
        return $this->module(
            'confidence_band_card',
            'summary_card',
            'visible',
            'all',
            [
                'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                'confidence_label' => data_get($projectionV2, 'classification.confidence_label'),
                'interpretation_scope' => data_get($projectionV2, 'classification.interpretation_scope'),
                'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
                'quality_level' => data_get($projectionV2, 'classification.quality_level'),
                'low_quality_status' => data_get($projectionV2, 'classification.low_quality_status'),
                'policy_versions' => [
                    'close_call_rule_version' => data_get($projectionV2, 'algorithmic_meta.close_call_rule_version'),
                    'confidence_policy_version' => data_get($projectionV2, 'algorithmic_meta.confidence_policy_version'),
                    'quality_policy_version' => data_get($projectionV2, 'algorithmic_meta.quality_policy_version'),
                ],
            ],
            [
                'classification.confidence_level',
                'classification.interpretation_scope',
                'classification.interpretation_reason',
                'classification.quality_level',
                'classification.low_quality_status',
            ],
            [],
            [
                'algorithmic_meta.close_call_rule_version',
                'algorithmic_meta.confidence_policy_version',
                'algorithmic_meta.quality_policy_version',
            ],
            ['content_maturity' => 'derived', 'evidence_level' => 'computed', 'fallback_policy' => 'required']
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildDominanceGapModule(array $projectionV2, array $indexes): array
    {
        return $this->module(
            'dominance_gap_card',
            'metrics_card',
            'visible',
            'all',
            [
                'dominance_gap_abs' => data_get($projectionV2, 'classification.dominance_gap_abs'),
                'dominance_gap_pct' => data_get($projectionV2, 'classification.dominance_gap_pct'),
                'normalized_gap' => data_get($projectionV2, 'classification.dominance.normalized_gap'),
                'profile_entropy' => data_get($projectionV2, 'classification.dominance.profile_entropy'),
                'unavailable' => data_get($projectionV2, '_meta.unavailable.classification', []),
            ],
            [
                'classification.dominance_gap_abs',
                'classification.dominance_gap_pct',
                'classification.dominance.normalized_gap',
                'classification.dominance.profile_entropy',
            ],
            [],
            ['algorithmic_meta.confidence_policy_version'],
            ['content_maturity' => 'derived', 'evidence_level' => 'computed', 'fallback_policy' => 'required']
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildCloseCallModule(array $projectionV2, array $indexes): array
    {
        $pair = is_array(data_get($projectionV2, 'dynamics.close_call_pair')) ? data_get($projectionV2, 'dynamics.close_call_pair') : null;
        $pairKey = is_array($pair) ? (string) ($pair['pair_key'] ?? '') : '';
        $pairEntry = $pairKey !== '' && is_array($indexes['pair_entries'][$pairKey] ?? null) ? $indexes['pair_entries'][$pairKey] : [];
        $visibility = $pair !== null ? 'visible' : 'collapsed';

        return $this->module(
            'close_call_card',
            'comparison_card',
            $visibility,
            'all',
            [
                'pair' => $pair,
                'interpretation_scope' => $this->state($projectionV2),
                'pair_entry' => $pairEntry !== [] ? [
                    'shared_surface_similarity' => $pairEntry['shared_surface_similarity'] ?? null,
                    'core_motivation_difference' => $pairEntry['core_motivation_difference'] ?? null,
                    'fear_difference' => $pairEntry['fear_difference'] ?? null,
                    'stress_reaction_difference' => $pairEntry['stress_reaction_difference'] ?? null,
                    'relationship_difference' => $pairEntry['relationship_difference'] ?? null,
                    'work_difference' => $pairEntry['work_difference'] ?? null,
                    'seven_day_observation_question' => $pairEntry['seven_day_observation_question'] ?? null,
                    'resonance_feedback_prompt' => $pairEntry['resonance_feedback_prompt'] ?? null,
                ] : null,
            ],
            ['dynamics.close_call_pair', 'classification.interpretation_scope'],
            $pairEntry !== [] ? ['enneagram_pair_registry:'.$pairKey] : [],
            ['algorithmic_meta.close_call_rule_version'],
            $pairEntry !== [] ? $this->entryMeta($pairEntry, $this->registryMeta($indexes, 'enneagram_pair_registry')) : $this->registryMeta($indexes, 'enneagram_pair_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildBlindSpotModule(array $projectionV2, array $indexes, string $moduleKey): array
    {
        $primaryType = (string) data_get($projectionV2, 'scores.primary_candidate', '');
        $typeEntry = $this->typeEntry($indexes, $primaryType);
        $reason = data_get($projectionV2, '_meta.unavailable.dynamics.blind_spot_type.reason');

        return $this->module(
            $moduleKey,
            'placeholder_card',
            'unavailable',
            'all',
            [
                'status' => 'unavailable',
                'reason' => $reason,
                'blind_spot_link' => $typeEntry['blind_spot_link'] ?? null,
                'primary_candidate' => $primaryType !== '' ? $primaryType : null,
            ],
            ['dynamics.blind_spot_type'],
            $primaryType !== '' ? ['enneagram_type_registry:'.$primaryType] : [],
            [],
            $this->entryMeta($typeEntry, $this->registryMeta($indexes, 'enneagram_type_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildUnavailableShapeModule(array $projectionV2, array $indexes, string $moduleKey, string $kind, string $unavailablePath): array
    {
        return $this->module(
            $moduleKey,
            $kind,
            'unavailable',
            'all',
            [
                'status' => 'unavailable',
                'reason' => data_get($projectionV2, '_meta.unavailable.'.$unavailablePath.'.reason'),
            ],
            [$unavailablePath],
            ['enneagram_group_registry'],
            [],
            $this->registryMeta($indexes, 'enneagram_group_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildWingHintModule(array $projectionV2, array $indexes): array
    {
        $visibility = (bool) data_get($projectionV2, 'render_hints.show_wing_hint') ? 'visible' : 'collapsed';
        $theory = is_array($indexes['theory_entries']['wing_hint'] ?? null) ? $indexes['theory_entries']['wing_hint'] : [];

        return $this->module(
            'wing_hint_visual',
            'summary_card',
            $visibility,
            'all',
            [
                'left' => data_get($projectionV2, 'dynamics.wing_hint_left'),
                'right' => data_get($projectionV2, 'dynamics.wing_hint_right'),
                'strength' => data_get($projectionV2, 'dynamics.wing_hint_strength'),
                'boundary_copy' => $theory['boundary_copy'] ?? null,
                'hard_judgement_allowed' => $theory['hard_judgement_allowed'] ?? false,
            ],
            ['dynamics.wing_hint_left', 'dynamics.wing_hint_right', 'dynamics.wing_hint_strength'],
            ['enneagram_theory_hint_registry:wing_hint'],
            [],
            $this->entryMeta($theory, $this->registryMeta($indexes, 'enneagram_theory_hint_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildMethodologyBoundaryModule(array $projectionV2, array $indexes, string $moduleKey): array
    {
        $methodKey = $this->formVariant($projectionV2) === 'fc144'
            ? 'fc144_forced_choice_methodology'
            : 'e105_standard_methodology';
        $method = is_array($indexes['method_entries'][$methodKey] ?? null) ? $indexes['method_entries'][$methodKey] : [];
        $sameModel = is_array($indexes['method_entries']['same_model_not_same_score_space'] ?? null) ? $indexes['method_entries']['same_model_not_same_score_space'] : [];
        $nonDiagnostic = is_array($indexes['method_entries']['non_diagnostic_boundary'] ?? null) ? $indexes['method_entries']['non_diagnostic_boundary'] : [];
        $formBadgeKey = $this->formVariant($projectionV2) === 'fc144' ? 'form_badge.fc144' : 'form_badge.e105';
        $badge = is_array($indexes['ui_entries'][$formBadgeKey] ?? null) ? $indexes['ui_entries'][$formBadgeKey] : [];

        return $this->module(
            $moduleKey,
            'boundary_card',
            'visible',
            $this->formVariant($projectionV2),
            [
                'form_badge' => [
                    'label' => $badge['label'] ?? null,
                    'body' => $badge['body_template'] ?? null,
                ],
                'methodology_copy' => $method['copy'] ?? null,
                'score_space_boundary' => $sameModel['copy'] ?? null,
                'non_diagnostic_boundary' => $nonDiagnostic['copy'] ?? null,
                'form_interpretation_boundary' => data_get($projectionV2, 'methodology.form_interpretation_boundary'),
                'method_boundary_copy_key' => data_get($projectionV2, 'methodology.method_boundary_copy_key'),
            ],
            ['form.form_code', 'form.methodology_variant', 'methodology.form_interpretation_boundary'],
            [
                'enneagram_method_registry:'.$methodKey,
                'enneagram_method_registry:same_model_not_same_score_space',
                'enneagram_method_registry:non_diagnostic_boundary',
                'enneagram_ui_copy_registry:'.$formBadgeKey,
            ],
            [
                'algorithmic_meta.confidence_policy_version',
                'algorithmic_meta.quality_policy_version',
            ],
            $this->mergeEntryMeta([$method, $sameModel, $nonDiagnostic, $badge], $this->registryMeta($indexes, 'enneagram_method_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildDiffuseBoundaryModule(array $projectionV2, array $indexes): array
    {
        $visible = $this->state($projectionV2) === 'diffuse';
        $ui = is_array($indexes['ui_entries']['diffuse_boundary.title'] ?? null) ? $indexes['ui_entries']['diffuse_boundary.title'] : [];

        return $this->module(
            'diffuse_boundary',
            'boundary_card',
            $visible ? 'visible' : 'collapsed',
            'all',
            [
                'title' => $ui['label'] ?? null,
                'interpretation_scope' => $this->state($projectionV2),
                'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
                'profile_entropy' => data_get($projectionV2, 'classification.dominance.profile_entropy'),
                'dominance_gap_pct' => data_get($projectionV2, 'classification.dominance_gap_pct'),
                'show_diffuse_warning' => data_get($projectionV2, 'render_hints.show_diffuse_warning'),
            ],
            [
                'classification.interpretation_scope',
                'classification.interpretation_reason',
                'classification.dominance.profile_entropy',
                'classification.dominance_gap_pct',
            ],
            ['enneagram_ui_copy_registry:diffuse_boundary.title'],
            ['algorithmic_meta.confidence_policy_version'],
            $this->entryMeta($ui, $this->registryMeta($indexes, 'enneagram_ui_copy_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildLowQualityBoundaryModule(array $projectionV2, array $indexes): array
    {
        $visible = $this->state($projectionV2) === 'low_quality';
        $ui = is_array($indexes['ui_entries']['low_quality_boundary.title'] ?? null) ? $indexes['ui_entries']['low_quality_boundary.title'] : [];

        return $this->module(
            'low_quality_boundary',
            'boundary_card',
            $visible ? 'visible' : 'collapsed',
            'all',
            [
                'title' => $ui['label'] ?? null,
                'interpretation_scope' => $this->state($projectionV2),
                'quality_level' => data_get($projectionV2, 'classification.quality_level'),
                'low_quality_status' => data_get($projectionV2, 'classification.low_quality_status'),
                'qc_flags' => data_get($projectionV2, 'classification.qc_flags', []),
                'signal_limitation' => data_get($projectionV2, '_meta.policy.signal_limitations.low_quality'),
                'show_low_quality_boundary' => data_get($projectionV2, 'render_hints.show_low_quality_boundary'),
            ],
            [
                'classification.interpretation_scope',
                'classification.quality_level',
                'classification.low_quality_status',
                'classification.qc_flags',
            ],
            ['enneagram_ui_copy_registry:low_quality_boundary.title'],
            ['algorithmic_meta.quality_policy_version'],
            $this->entryMeta($ui, $this->registryMeta($indexes, 'enneagram_ui_copy_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @param  array<string,string>  $extraContent
     * @return array<string,mixed>
     */
    private function buildScenarioModule(array $projectionV2, array $indexes, string $scenarioKey, string $kind, array $extraContent = []): array
    {
        $entry = is_array($indexes['scenario_entries'][$scenarioKey] ?? null) ? $indexes['scenario_entries'][$scenarioKey] : [];
        $primaryType = (string) data_get($projectionV2, 'scores.primary_candidate', '');
        $typeEntry = $this->typeEntry($indexes, $primaryType);
        $summaryRef = (string) ($extraContent['content.summary_ref'] ?? '');
        unset($extraContent['content.summary_ref']);
        $typeSummary = $summaryRef !== '' ? ($typeEntry[$summaryRef] ?? null) : null;

        return $this->module(
            $scenarioKey,
            $kind,
            'visible',
            'all',
            array_merge([
                'title' => $entry['title'] ?? null,
                'body' => $entry['body'] ?? null,
                'primary_candidate' => $primaryType !== '' ? $primaryType : null,
                'type_summary' => $typeSummary,
            ], $extraContent),
            ['scores.primary_candidate'],
            array_merge(
                ['enneagram_scenario_registry:'.$scenarioKey],
                $primaryType !== '' ? ['enneagram_type_registry:'.$primaryType] : []
            ),
            [],
            $this->mergeEntryMeta([$entry, $typeEntry], $this->registryMeta($indexes, 'enneagram_scenario_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildTypeDrivenModule(array $projectionV2, array $indexes, string $moduleKey, string $kind, string $typeField): array
    {
        $primaryType = (string) data_get($projectionV2, 'scores.primary_candidate', '');
        $typeEntry = $this->typeEntry($indexes, $primaryType);

        return $this->module(
            $moduleKey,
            $kind,
            'visible',
            'all',
            [
                'primary_candidate' => $primaryType !== '' ? $primaryType : null,
                'value' => $typeEntry[$typeField] ?? null,
                'type_name_cn' => $typeEntry['type_name_cn'] ?? null,
                'type_name_en' => $typeEntry['type_name_en'] ?? null,
            ],
            ['scores.primary_candidate'],
            $primaryType !== '' ? ['enneagram_type_registry:'.$primaryType] : [],
            [],
            $this->entryMeta($typeEntry, $this->registryMeta($indexes, 'enneagram_type_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildGroupOverlayModule(array $projectionV2, array $indexes, string $moduleKey, string $field): array
    {
        $primaryType = (string) data_get($projectionV2, 'scores.primary_candidate', '');
        $groupEntries = $this->groupEntriesForType($indexes, $primaryType);
        $items = [];
        $registryRefs = [];
        foreach ($groupEntries as $groupRef => $entry) {
            $registryRefs[] = 'enneagram_group_registry:'.$groupRef;
            $items[] = [
                'group_ref' => $groupRef,
                'group_type' => $entry['group_type'] ?? null,
                'group_key' => $entry['group_key'] ?? null,
                'description' => $entry['description'] ?? null,
                'value' => $entry[$field] ?? null,
            ];
        }

        return $this->module(
            $moduleKey,
            'summary_card',
            $items !== [] ? 'visible' : 'unavailable',
            'all',
            [
                'primary_candidate' => $primaryType !== '' ? $primaryType : null,
                'items' => $items,
                'status' => $items !== [] ? 'available' : 'unavailable',
            ],
            ['scores.primary_candidate'],
            $registryRefs,
            [],
            $this->registryMeta($indexes, 'enneagram_group_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildStateModule(array $projectionV2, array $indexes, string $moduleKey): array
    {
        $stateEntry = is_array($indexes['state_entry'] ?? null) ? $indexes['state_entry'] : [];
        $content = match ($moduleKey) {
            'recovery_action' => [
                'recovery_action' => $stateEntry['recovery_action'] ?? null,
                'disclaimer' => $stateEntry['disclaimer'] ?? null,
            ],
            default => [
                'stable_expression' => $stateEntry['stable_expression'] ?? null,
                'average_expression' => $stateEntry['average_expression'] ?? null,
                'strained_expression' => $stateEntry['strained_expression'] ?? null,
                'recovery_action' => $stateEntry['recovery_action'] ?? null,
                'disclaimer' => $stateEntry['disclaimer'] ?? null,
            ],
        };

        return $this->module(
            $moduleKey,
            $moduleKey === 'recovery_action' ? 'summary_card' : 'state_spectrum',
            'visible',
            'all',
            $content,
            [],
            ['enneagram_state_registry:base_state_scaffold'],
            [],
            $this->entryMeta($stateEntry, $this->registryMeta($indexes, 'enneagram_state_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildTheoryModule(array $projectionV2, array $indexes, string $theoryKey, string $kind): array
    {
        $entry = is_array($indexes['theory_entries'][$theoryKey] ?? null) ? $indexes['theory_entries'][$theoryKey] : [];

        return $this->module(
            $theoryKey,
            $kind,
            'placeholder',
            'all',
            [
                'boundary_copy' => $entry['boundary_copy'] ?? null,
                'visibility_scope' => $entry['visibility_scope'] ?? null,
                'hard_judgement_allowed' => $entry['hard_judgement_allowed'] ?? false,
            ],
            [],
            ['enneagram_theory_hint_registry:'.$theoryKey],
            [],
            $this->entryMeta($entry, $this->registryMeta($indexes, 'enneagram_theory_hint_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildObservationModule(array $projectionV2, array $indexes): array
    {
        $steps = [];
        $registryRefs = [];
        foreach ((array) ($indexes['observation_entries'] ?? []) as $day => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $registryRefs[] = 'enneagram_observation_registry:day_'.$day;
            $steps[] = [
                'day' => $entry['day'] ?? null,
                'phase' => $entry['phase'] ?? null,
                'prompt' => $entry['prompt'] ?? null,
                'user_input_schema' => $entry['user_input_schema'] ?? null,
                'analytics_event_key' => $entry['analytics_event_key'] ?? null,
                'suggested_next_action' => $entry['suggested_next_action'] ?? null,
            ];
        }

        return $this->module(
            'seven_day_observation',
            'observation_plan',
            'visible',
            'all',
            [
                'steps' => array_values($steps),
                'interpretation_scope' => $this->state($projectionV2),
            ],
            ['classification.interpretation_scope'],
            $registryRefs,
            [],
            $this->registryMeta($indexes, 'enneagram_observation_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildFormRecommendationModule(array $projectionV2, array $indexes): array
    {
        $scope = $this->state($projectionV2);
        $formVariant = $this->formVariant($projectionV2);
        $recommendation = match (true) {
            $scope === 'low_quality' => 'retake_same_form_after_quality_check',
            $scope === 'close_call' && $formVariant === 'e105' => 'consider_fc144_followup',
            $scope === 'diffuse' => 'observe_before_retake',
            default => 'stay_with_current_form',
        };

        return $this->module(
            'form_recommendation',
            'recommendation_card',
            'visible',
            $formVariant,
            [
                'recommendation_key' => $recommendation,
                'interpretation_scope' => $scope,
                'form_code' => data_get($projectionV2, 'form.form_code'),
                'methodology_variant' => data_get($projectionV2, 'form.methodology_variant'),
                'recommended_first_action' => data_get($projectionV2, 'render_hints.recommended_first_action'),
            ],
            ['classification.interpretation_scope', 'form.form_code', 'form.methodology_variant'],
            ['enneagram_method_registry'],
            [],
            $this->registryMeta($indexes, 'enneagram_method_registry')
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildSampleReportModule(array $projectionV2, array $indexes): array
    {
        $scope = $this->state($projectionV2);
        $sampleKey = match ($scope) {
            'close_call' => 'close_call_sample',
            'diffuse', 'low_quality' => 'diffuse_sample',
            default => 'clear_sample',
        };
        $entry = is_array($indexes['sample_entries'][$sampleKey] ?? null) ? $indexes['sample_entries'][$sampleKey] : [];

        return $this->module(
            'sample_report_link',
            'link_card',
            'visible',
            'all',
            [
                'sample_key' => $entry['sample_key'] ?? null,
                'sample_type' => $entry['sample_type'] ?? null,
                'form_code' => $entry['form_code'] ?? null,
                'interpretation_scope' => $entry['interpretation_scope'] ?? null,
                'projection_fixture_id' => $entry['projection_fixture_id'] ?? null,
                'report_snapshot_ref' => $entry['report_snapshot_ref'] ?? null,
                'public_url_slug' => $entry['public_url_slug'] ?? null,
                'top_types' => array_values(array_filter((array) ($entry['top_types'] ?? []), static fn ($value): bool => is_string($value) && trim($value) !== '')),
                'short_summary' => $entry['short_summary'] ?? null,
                'page_1_preview' => $entry['page_1_preview'] ?? null,
                'method_boundary' => $entry['method_boundary'] ?? null,
            ],
            ['classification.interpretation_scope'],
            ['enneagram_sample_report_registry:'.$sampleKey],
            [],
            $this->entryMeta($entry, $this->registryMeta($indexes, 'enneagram_sample_report_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildTechnicalNoteModule(array $projectionV2, array $indexes): array
    {
        $ui = is_array($indexes['ui_entries']['technical_note.link_label'] ?? null) ? $indexes['ui_entries']['technical_note.link_label'] : [];
        $sections = [];
        foreach ((array) ($indexes['technical_entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sections[] = [
                'section_key' => $entry['section_key'] ?? null,
                'title' => $entry['title'] ?? null,
            ];
        }

        return $this->module(
            'technical_note_link',
            'link_card',
            'visible',
            'all',
            [
                'label' => $ui['label'] ?? null,
                'technical_note_version' => data_get($projectionV2, 'algorithmic_meta.technical_note_version'),
                'sections' => $sections,
            ],
            ['algorithmic_meta.technical_note_version'],
            [
                'enneagram_ui_copy_registry:technical_note.link_label',
                'enneagram_technical_note_registry',
            ],
            ['algorithmic_meta.technical_note_version'],
            $this->mergeEntryMeta([$ui], $this->registryMeta($indexes, 'enneagram_technical_note_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function buildPlaceholderModule(array $projectionV2, array $indexes, string $moduleKey, string $kind, string $reason): array
    {
        return $this->module(
            $moduleKey,
            $kind,
            'placeholder',
            'all',
            [
                'status' => 'placeholder',
                'reason' => $reason,
                'interpretation_scope' => $this->state($projectionV2),
            ],
            ['classification.interpretation_scope'],
            [],
            [],
            ['content_maturity' => 'scaffold', 'evidence_level' => 'descriptive', 'fallback_policy' => 'fallback_to_generic']
        );
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  list<string>  $dataRefs
     * @param  list<string>  $registryRefs
     * @param  list<string>  $policyRefs
     * @param  array<string,string>  $meta
     * @return array<string,mixed>
     */
    private function module(
        string $moduleKey,
        string $kind,
        string $visibility,
        string $formVariant,
        array $content,
        array $dataRefs,
        array $registryRefs,
        array $policyRefs,
        array $meta
    ): array {
        $state = $this->normalizeModuleState((string) ($content['interpretation_scope'] ?? ''), $content);
        $projectionRefs = $dataRefs;

        return [
            'module_key' => $moduleKey,
            'kind' => $kind,
            'visibility' => $visibility,
            'state' => $state,
            'form_variant' => $formVariant,
            'content' => $content,
            'data_refs' => array_values(array_unique(array_filter($dataRefs, static fn (mixed $ref): bool => is_string($ref) && $ref !== ''))),
            'registry_refs' => array_values(array_unique(array_filter($registryRefs, static fn (mixed $ref): bool => is_string($ref) && $ref !== ''))),
            'provenance' => [
                'projection_refs' => array_values(array_unique(array_filter($projectionRefs, static fn (mixed $ref): bool => is_string($ref) && $ref !== ''))),
                'registry_refs' => array_values(array_unique(array_filter($registryRefs, static fn (mixed $ref): bool => is_string($ref) && $ref !== ''))),
                'policy_refs' => array_values(array_unique(array_filter($policyRefs, static fn (mixed $ref): bool => is_string($ref) && $ref !== ''))),
                'content_maturity' => $meta['content_maturity'] ?? 'scaffold',
                'evidence_level' => $meta['evidence_level'] ?? 'descriptive',
            ],
            'fallback_policy' => $meta['fallback_policy'] ?? 'fallback_to_generic',
        ];
    }

    /**
     * @param  array<string,mixed>  $indexes
     * @return array<string,mixed>
     */
    private function typeEntry(array $indexes, string $type): array
    {
        return is_array($indexes['type_entries'][$type] ?? null) ? $indexes['type_entries'][$type] : [];
    }

    /**
     * @param  array<string,mixed>  $indexes
     * @return list<array<string,mixed>>
     */
    private function groupEntriesForType(array $indexes, string $type): array
    {
        $typeId = trim($type);
        if ($typeId === '') {
            return [];
        }

        $entries = [];
        foreach ((array) ($indexes['group_entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $typeIds = is_array($entry['type_ids'] ?? null) ? $entry['type_ids'] : [];
            if (in_array($typeId, $typeIds, true)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $indexes
     * @return array<string,string>
     */
    private function registryMeta(array $indexes, string $registryKey): array
    {
        return is_array($indexes['registry_meta'][$registryKey] ?? null) ? $indexes['registry_meta'][$registryKey] : [
            'content_maturity' => 'scaffold',
            'evidence_level' => 'descriptive',
            'fallback_policy' => 'fallback_to_generic',
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,string>  $registryMeta
     * @return array<string,string>
     */
    private function entryMeta(array $entry, array $registryMeta): array
    {
        return [
            'content_maturity' => (string) ($entry['content_maturity'] ?? $registryMeta['content_maturity'] ?? 'scaffold'),
            'evidence_level' => (string) ($entry['evidence_level'] ?? $registryMeta['evidence_level'] ?? 'descriptive'),
            'fallback_policy' => (string) ($entry['fallback_policy'] ?? $registryMeta['fallback_policy'] ?? 'fallback_to_generic'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,string>  $registryMeta
     * @return array<string,string>
     */
    private function mergeEntryMeta(array $entries, array $registryMeta): array
    {
        $meta = $registryMeta;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $meta = $this->entryMeta($entry, $meta);
        }

        return $meta;
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     */
    private function state(array $projectionV2): string
    {
        $state = trim((string) data_get($projectionV2, 'classification.interpretation_scope', 'clear'));

        return in_array($state, ['clear', 'close_call', 'diffuse', 'low_quality'], true) ? $state : 'clear';
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     */
    private function formVariant(array $projectionV2): string
    {
        return data_get($projectionV2, 'form.form_code') === 'enneagram_forced_choice_144' ? 'fc144' : 'e105';
    }

    private function normalizeModuleState(string $state, array $content): string
    {
        if (in_array($state, ['clear', 'close_call', 'diffuse', 'low_quality'], true)) {
            return $state;
        }

        $derived = trim((string) ($content['interpretation_scope'] ?? ''));

        return in_array($derived, ['clear', 'close_call', 'diffuse', 'low_quality'], true) ? $derived : 'clear';
    }

    /**
     * @param  list<array<string,mixed>>  $modules
     * @return list<string>
     */
    private function pageRegistryRefs(array $modules): array
    {
        $refs = [];
        foreach ($modules as $module) {
            foreach ((array) ($module['registry_refs'] ?? []) as $ref) {
                $ref = trim((string) $ref);
                if ($ref === '') {
                    continue;
                }
                $refs[] = str_contains($ref, ':') ? strstr($ref, ':', true) : $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function keyListBy(mixed $entries, string $field): array
    {
        $map = [];
        if (! is_array($entries)) {
            return $map;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry[$field] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = $entry;
        }

        return $map;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function keyGroupEntries(mixed $entries): array
    {
        $map = [];
        if (! is_array($entries)) {
            return $map;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['group_type'] ?? '')).':'.trim((string) ($entry['group_key'] ?? ''));
            if ($key === ':') {
                continue;
            }
            $map[$key] = $entry;
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $indexes
     */
    private function registryPackContentMaturity(array $indexes): string
    {
        $values = [];
        foreach ((array) ($indexes['registry_meta'] ?? []) as $meta) {
            if (is_array($meta)) {
                $values[] = (string) ($meta['content_maturity'] ?? 'scaffold');
            }
        }

        if (in_array('scaffold', $values, true)) {
            return 'scaffold';
        }
        if (in_array('p0_placeholder', $values, true)) {
            return 'p0_placeholder';
        }
        if (in_array('p0_ready', $values, true)) {
            return 'p0_ready';
        }

        return $values[0] ?? 'scaffold';
    }

    private function pageSpec(string $pageKey, string $field, string $language): string
    {
        $spec = self::PAGE_SPECS[$pageKey] ?? null;
        if (! is_array($spec)) {
            return $pageKey;
        }

        $value = $spec[$field] ?? null;
        if (! is_array($value)) {
            return $pageKey;
        }

        return (string) ($value[$language] ?? $value['zh'] ?? $pageKey);
    }

    private function normalizeLanguage(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'en') ? 'en' : 'zh';
    }
}
