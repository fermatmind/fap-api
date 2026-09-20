<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Enneagram\EnneagramPublicProjectionService;
use App\Services\Enneagram\Registry\RegistryValidator;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class EnneagramReportComposer
{
    private const REPORT_V2_SCHEMA = 'enneagram.report.v2';

    private const REPORT_V2_ENGINE_VERSION = 'enneagram_report_engine.v2';

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
        if (array_key_exists('modules_allowed', $ctx)) {
            $modulesAllowed = ReportAccess::normalizeModules(
                is_array($ctx['modules_allowed'] ?? null) ? $ctx['modules_allowed'] : []
            );
            if ($variant !== ReportAccess::VARIANT_FREE
                && ! in_array(ReportAccess::MODULE_ENNEAGRAM_FULL, $modulesAllowed, true)
            ) {
                $variant = ReportAccess::VARIANT_FREE;
            }
        }
        $locked = $variant === ReportAccess::VARIANT_FREE;
        $locale = trim((string) ($attempt->locale ?? $ctx['locale'] ?? config('content_packs.default_locale', 'zh-CN')));
        if ($locale === '') {
            $locale = 'zh-CN';
        }
        $language = $this->normalizeLanguage($locale);

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
                'locale' => $language,
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
                    'enneagram_private_result_authority' => data_get($projectionV2, 'private_result_authority'),
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
            'canonical_authority_identity' => data_get($projectionV2, 'private_result_authority.authority_id'),
            'canonical_release_id' => data_get($projectionV2, 'private_result_authority.release_id'),
            'canonical_source_hash' => data_get($projectionV2, 'private_result_authority.source_hash'),
            'canonical_compiled_hash' => data_get($projectionV2, 'private_result_authority.compiled_hash'),
            'canonical_locale' => data_get($projectionV2, 'private_result_authority.locale'),
            'canonical_runtime_contract' => data_get($projectionV2, 'private_result_authority.runtime_contract'),
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
            $pack = $this->loadValidatedRegistryPack($language);
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
                'locale' => $language,
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
                    'active_release_id' => data_get($pack, 'authority.release_id'),
                    'source_hash' => data_get($pack, 'authority.source_hash'),
                    'compiled_hash' => data_get($pack, 'authority.compiled_hash'),
                ],
                'classification' => [
                    'interpretation_scope' => data_get($projectionV2, 'classification.interpretation_scope'),
                    'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                    'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
                ],
                'distribution' => array_values((array) data_get($projectionV2, 'scores.all9_profile', [])),
                'candidates' => $this->buildCanonicalCandidates($projectionV2, $indexes),
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
                    'canonical_authority_id' => data_get($pack, 'authority.authority_id'),
                    'canonical_release_id' => data_get($pack, 'authority.release_id'),
                    'canonical_source_hash' => data_get($pack, 'authority.source_hash'),
                    'canonical_compiled_hash' => data_get($pack, 'authority.compiled_hash'),
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
            Log::warning('ENNEAGRAM_REPORT_V2_REGISTRY_UNAVAILABLE', [
                'exception_class' => $error::class,
                'exception' => SensitiveDiagnosticRedactor::redactString($error->getMessage()),
            ]);
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_INVALID', previous: $error);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadValidatedRegistryPack(string $language): array
    {
        $pack = $this->packLoader->loadRegistryPack(null, $language);
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
            'registry_meta' => array_map(fn (array $registry): array => [
                'registry_key' => (string) ($registry['registry_key'] ?? ''),
                'content_maturity' => (string) ($registry['content_maturity'] ?? 'scaffold'),
                'evidence_level' => (string) ($registry['evidence_level'] ?? 'descriptive'),
                'fallback_policy' => (string) ($registry['fallback_policy'] ?? 'fallback_to_generic'),
            ], $registries),
            'type_entries' => $this->keyListBy($registries['enneagram_type_registry']['entries'] ?? [], 'type_id'),
            'chapter_entries' => $this->keyListBy($registries['enneagram_chapter_registry']['entries'] ?? [], 'type_id'),
            'pair_entries' => $this->keyListBy($registries['enneagram_pair_registry']['entries'] ?? [], 'pair_key'),
            'observation_entries' => $this->keyListBy($registries['enneagram_observation_registry']['entries'] ?? [], 'day'),
            'method_entries' => $this->keyListBy($registries['enneagram_method_registry']['entries'] ?? [], 'method_key'),
            'ui_entries' => is_array($registries['enneagram_ui_copy_registry']['entries'] ?? null) ? $registries['enneagram_ui_copy_registry']['entries'] : [],
            'surface_entries' => is_array($registries['enneagram_surface_registry']['entries'] ?? null) ? $registries['enneagram_surface_registry']['entries'] : [],
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
        $pageSpecs = is_array($indexes['surface_entries']['page_specs'] ?? null) ? $indexes['surface_entries']['page_specs'] : [];
        foreach ($pageSpecs as $pageKey => $pageSpec) {
            $modules = [];
            foreach ((array) ($pageSpec['modules'] ?? []) as $moduleKey) {
                $modules[] = $this->buildModule($moduleKey, $projectionV2, $indexes, $language);
            }

            $pages[] = [
                'page_key' => $pageKey,
                'number' => (string) ($pageSpec['number'] ?? ''),
                'locale' => $language,
                'title' => (string) ($pageSpec['title'] ?? ''),
                'purpose' => (string) ($pageSpec['purpose'] ?? ''),
                'section_ids' => array_values(array_map('strval', (array) ($pageSpec['section_ids'] ?? []))),
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
        $module = match ($moduleKey) {
            'result_overview' => $this->buildResultOverviewModule($projectionV2, $indexes, $language),
            'candidate_pair_comparison' => $this->buildCandidatePairComparisonModule($projectionV2, $indexes),
            'candidate_chapter' => $this->buildCandidateChapterModule($projectionV2, $indexes),
            'growth_actions' => $this->buildGrowthActionsModule($projectionV2, $indexes),
            'seven_day_observation' => $this->buildObservationModule($projectionV2, $indexes),
            default => throw new RuntimeException('ENNEAGRAM_REPORT_UNKNOWN_MODULE:'.$moduleKey),
        };

        $content = is_array($module['content'] ?? null) ? $module['content'] : [];
        $content['locale'] = $language;
        $module['content'] = $content;

        return $module;
    }

    /**
     * @param  array<string,mixed>  $projectionV2
     * @param  array<string,mixed>  $indexes
     * @return list<array<string,mixed>>
     */
    private function buildCanonicalCandidates(array $projectionV2, array $indexes): array
    {
        $candidates = [];
        foreach (array_slice((array) data_get($projectionV2, 'scores.top_types', []), 0, 3) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('ENNEAGRAM_TOP3_CANDIDATE_INVALID');
            }
            $typeId = trim((string) ($row['type'] ?? ''));
            $chapterEntry = is_array($indexes['chapter_entries'][$typeId] ?? null) ? $indexes['chapter_entries'][$typeId] : [];
            $typeEntry = $this->typeEntry($indexes, $typeId);
            $sections = is_array($chapterEntry['sections'] ?? null) ? $chapterEntry['sections'] : [];
            $actions = is_array($chapterEntry['growth_actions'] ?? null) ? $chapterEntry['growth_actions'] : [];
            if ($typeId === '' || $typeEntry === [] || count($sections) !== 20 || count($actions) < 3) {
                throw new RuntimeException('ENNEAGRAM_CANDIDATE_CONTENT_INCOMPLETE:'.$typeId);
            }

            $candidates[] = [
                'type_id' => $typeId,
                'rank' => (int) ($row['rank'] ?? 0),
                'candidate_role' => (string) ($row['candidate_role'] ?? ''),
                'type_name' => (string) ($typeEntry['type_name_'.($this->normalizeLanguage((string) data_get($projectionV2, 'locale', 'zh')) === 'en' ? 'en' : 'cn')] ?? ''),
                'type_name_cn' => (string) ($typeEntry['type_name_cn'] ?? ''),
                'type_name_en' => (string) ($typeEntry['type_name_en'] ?? ''),
                'short_title' => (string) ($chapterEntry['short_title'] ?? $typeEntry['short_title'] ?? ''),
                'score_norm' => $row['score_norm'] ?? null,
                'score_display' => $row['score_display'] ?? null,
                'score_source' => $row['score_source'] ?? null,
                'sections' => array_values($sections),
                'growth_actions' => array_values($actions),
                'registry_ref' => 'enneagram_chapter_registry:'.$typeId,
            ];
        }

        if (count($candidates) !== 3) {
            throw new RuntimeException('ENNEAGRAM_TOP3_CANDIDATE_COVERAGE_INVALID');
        }

        return $candidates;
    }

    /** @return array<string,mixed> */
    private function buildResultOverviewModule(array $projectionV2, array $indexes, string $language): array
    {
        $state = $this->state($projectionV2);
        $uiKey = 'result_overview.'.$state;
        $ui = is_array($indexes['ui_entries'][$uiKey] ?? null) ? $indexes['ui_entries'][$uiKey] : [];
        $formVariant = $this->formVariant($projectionV2);
        $formBadgeKey = $formVariant === 'fc144' ? 'form_badge.fc144' : 'form_badge.e105';
        $formBadge = is_array($indexes['ui_entries'][$formBadgeKey] ?? null) ? $indexes['ui_entries'][$formBadgeKey] : [];
        $methodKey = $formVariant === 'fc144' ? 'fc144_forced_choice_methodology' : 'e105_standard_methodology';
        $method = is_array($indexes['method_entries'][$methodKey] ?? null) ? $indexes['method_entries'][$methodKey] : [];
        $sameModel = is_array($indexes['method_entries']['same_model_not_same_score_space'] ?? null) ? $indexes['method_entries']['same_model_not_same_score_space'] : [];

        return $this->module(
            'result_overview',
            'result_overview',
            'visible',
            $formVariant,
            [
                'title' => (string) ($ui['title_template'] ?? ''),
                'body' => (string) ($ui['body_template'] ?? ''),
                'interpretation_scope' => $state,
                'interpretation_reason' => data_get($projectionV2, 'classification.interpretation_reason'),
                'confidence_level' => data_get($projectionV2, 'classification.confidence_level'),
                'quality_level' => data_get($projectionV2, 'classification.quality_level'),
                'form' => data_get($projectionV2, 'form', []),
                'form_badge' => ['label' => $formBadge['label'] ?? null, 'body' => $formBadge['body_template'] ?? null],
                'methodology_copy' => $method['copy'] ?? null,
                'score_space_boundary' => $sameModel['copy'] ?? null,
                'distribution' => array_values((array) data_get($projectionV2, 'scores.all9_profile', [])),
                'top_candidates' => array_map(static fn (array $candidate): array => [
                    'type_id' => $candidate['type_id'],
                    'rank' => $candidate['rank'],
                    'candidate_role' => $candidate['candidate_role'],
                    'type_name' => $candidate['type_name'],
                    'short_title' => $candidate['short_title'],
                    'score_display' => $candidate['score_display'],
                ], $this->buildCanonicalCandidates($projectionV2, $indexes)),
                'locale' => $language,
            ],
            ['form', 'scores.all9_profile', 'scores.top_types', 'classification'],
            ['enneagram_ui_copy_registry:'.$uiKey, 'enneagram_ui_copy_registry:'.$formBadgeKey, 'enneagram_method_registry:'.$methodKey],
            ['algorithmic_meta.confidence_policy_version', 'algorithmic_meta.quality_policy_version'],
            $this->mergeEntryMeta([$ui, $formBadge, $method], $this->registryMeta($indexes, 'enneagram_chapter_registry'))
        );
    }

    /** @return array<string,mixed> */
    private function buildCandidateChapterModule(array $projectionV2, array $indexes): array
    {
        return $this->module(
            'candidate_chapter',
            'candidate_chapter',
            'visible',
            'all',
            [
                'candidate_type_ids' => array_column($this->buildCanonicalCandidates($projectionV2, $indexes), 'type_id'),
                'selection_behavior' => 'reading_perspective_only',
                'system_result_mutation_allowed' => false,
            ],
            ['scores.top_types'],
            ['enneagram_chapter_registry'],
            ['classification.interpretation_scope'],
            $this->registryMeta($indexes, 'enneagram_chapter_registry')
        );
    }

    /** @return array<string,mixed> */
    private function buildCandidatePairComparisonModule(array $projectionV2, array $indexes): array
    {
        $state = $this->state($projectionV2);
        if ($state !== 'close_call') {
            return $this->module(
                'candidate_pair_comparison',
                'candidate_pair_comparison',
                'unavailable',
                'all',
                ['interpretation_scope' => 'unavailable', 'available' => false],
                ['classification.interpretation_scope'],
                ['enneagram_pair_registry'],
                ['classification.interpretation_scope'],
                $this->registryMeta($indexes, 'enneagram_pair_registry')
            );
        }

        $candidates = $this->buildCanonicalCandidates($projectionV2, $indexes);
        $first = $candidates[0] ?? [];
        $second = $candidates[1] ?? [];
        $firstType = trim((string) ($first['type_id'] ?? ''));
        $secondType = trim((string) ($second['type_id'] ?? ''));
        if (! preg_match('/^[1-9]$/', $firstType) || ! preg_match('/^[1-9]$/', $secondType) || $firstType === $secondType) {
            throw new RuntimeException('ENNEAGRAM_CLOSE_CALL_CANDIDATES_INVALID');
        }

        $orderedTypes = [$firstType, $secondType];
        sort($orderedTypes, SORT_NUMERIC);
        $pairKey = implode('_', $orderedTypes);
        $pair = is_array($indexes['pair_entries'][$pairKey] ?? null) ? $indexes['pair_entries'][$pairKey] : [];
        if ($pair === []
            || (string) ($pair['type_a'] ?? '') !== $orderedTypes[0]
            || (string) ($pair['type_b'] ?? '') !== $orderedTypes[1]
            || (string) ($pair['fallback_policy'] ?? '') !== 'none'
        ) {
            throw new RuntimeException('ENNEAGRAM_CLOSE_CALL_PAIR_INVALID:'.$pairKey);
        }

        $dimensions = [];
        foreach ([
            'core_motivation_difference' => 'core_motivation',
            'fear_difference' => 'core_concern',
            'stress_reaction_difference' => 'stress_reaction',
            'relationship_difference' => 'relationship_pattern',
            'work_difference' => 'work_pattern',
        ] as $sourceField => $dimensionKey) {
            $source = is_array($pair[$sourceField] ?? null) ? $pair[$sourceField] : [];
            $firstCopy = trim((string) ($source[$firstType] ?? ''));
            $secondCopy = trim((string) ($source[$secondType] ?? ''));
            if ($firstCopy === '' || $secondCopy === '') {
                throw new RuntimeException('ENNEAGRAM_CLOSE_CALL_PAIR_FIELD_MISSING:'.$pairKey.':'.$sourceField);
            }
            $dimensions[] = [
                'dimension_key' => $dimensionKey,
                'sides' => [
                    $this->pairSide($first, $firstCopy),
                    $this->pairSide($second, $secondCopy),
                ],
            ];
        }

        foreach (['shared_surface_similarity', 'seven_day_observation_question', 'resonance_feedback_prompt', 'short_compare_copy'] as $field) {
            if (trim((string) ($pair[$field] ?? '')) === '') {
                throw new RuntimeException('ENNEAGRAM_CLOSE_CALL_PAIR_FIELD_MISSING:'.$pairKey.':'.$field);
            }
        }

        return $this->module(
            'candidate_pair_comparison',
            'candidate_pair_comparison',
            'visible',
            'all',
            [
                'interpretation_scope' => 'close_call',
                'available' => true,
                'pair_key' => $pairKey,
                'candidate_order' => [
                    $this->pairSide($first),
                    $this->pairSide($second),
                ],
                'shared_surface_similarity' => $pair['shared_surface_similarity'],
                'dimensions' => $dimensions,
                'seven_day_observation_question' => $pair['seven_day_observation_question'],
                'resonance_feedback_prompt' => $pair['resonance_feedback_prompt'],
                'short_compare_copy' => $pair['short_compare_copy'],
            ],
            ['scores.top_types', 'classification.interpretation_scope'],
            ['enneagram_pair_registry:'.$pairKey],
            ['classification.interpretation_scope', 'algorithmic_meta.close_call_rule_version'],
            $this->entryMeta($pair, $this->registryMeta($indexes, 'enneagram_pair_registry'))
        );
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function pairSide(array $candidate, ?string $copy = null): array
    {
        $side = [
            'type_id' => (string) ($candidate['type_id'] ?? ''),
            'rank' => (int) ($candidate['rank'] ?? 0),
            'candidate_role' => (string) ($candidate['candidate_role'] ?? ''),
            'type_name' => (string) ($candidate['type_name'] ?? ''),
            'short_title' => (string) ($candidate['short_title'] ?? ''),
        ];
        if ($copy !== null) {
            $side['copy'] = $copy;
        }

        return $side;
    }

    /** @return array<string,mixed> */
    private function buildGrowthActionsModule(array $projectionV2, array $indexes): array
    {
        return $this->module(
            'growth_actions',
            'growth_actions',
            'visible',
            'all',
            [
                'candidates' => array_map(static fn (array $candidate): array => [
                    'type_id' => $candidate['type_id'],
                    'candidate_role' => $candidate['candidate_role'],
                    'actions' => $candidate['growth_actions'],
                ], $this->buildCanonicalCandidates($projectionV2, $indexes)),
                'assignment_mode' => 'observation_evidence_only',
                'system_result_mutation_allowed' => false,
                'feedback_days' => [3, 7],
            ],
            ['scores.top_types'],
            ['enneagram_chapter_registry', 'enneagram_observation_registry'],
            ['calibration_data.user_confirmed_type'],
            $this->registryMeta($indexes, 'enneagram_chapter_registry')
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
        $content = $this->compactDisplayContent($moduleKey, $content);
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
     * Keep the short scientific/use boundary in the result overview. Other
     * modules may retain only limits that are specific to the claim they make.
     *
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function compactDisplayContent(string $moduleKey, array $content): array
    {
        if ($moduleKey === 'result_overview') {
            return $content;
        }

        $walk = function (mixed $value) use (&$walk): mixed {
            if (is_array($value)) {
                $compacted = [];
                foreach ($value as $key => $item) {
                    if ($key === 'disclaimer') {
                        continue;
                    }
                    $compacted[$key] = $walk($item);
                }

                return $compacted;
            }

            if (! is_string($value) || trim($value) === '') {
                return $value;
            }

            $sentences = preg_split('/(?<=[。！？.!?])\s*/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $sentences = array_values(array_filter($sentences, static function (string $sentence): bool {
                return preg_match(
                    '/不是(?:诊断|医学|心理健康)|不用于(?:诊断|招聘|人员)|不能单独推出(?:职业|岗位|绩效|人员)|not (?:a )?diagnosis|not used for (?:diagnosis|hiring)|does not establish (?:career|competence|performance)|cannot replace evidence of (?:ability|experience)/iu',
                    $sentence
                ) !== 1;
            }));

            $separator = preg_match('/[\x{3400}-\x{9fff}]/u', $value) === 1 ? '' : ' ';

            return trim(implode($separator, $sentences));
        };

        return $walk($content);
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
        if (in_array($state, ['clear', 'close_call', 'diffuse', 'low_quality', 'unavailable'], true)) {
            return $state;
        }

        $derived = trim((string) ($content['interpretation_scope'] ?? ''));

        return in_array($derived, ['clear', 'close_call', 'diffuse', 'low_quality', 'unavailable'], true) ? $derived : 'clear';
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

    private function normalizeLanguage(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'en') ? 'en' : 'zh';
    }
}
