<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Eq60PackLoader;
use App\Services\Eq\EqCrossAssessmentContextGuard;

final class Eq60ReportComposer
{
    public function __construct(
        private readonly Eq60PackLoader $packLoader,
        private readonly EqCrossAssessmentContextGuard $crossAssessmentContextGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $version = trim((string) ($attempt->dir_version ?? Eq60PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Eq60PackLoader::PACK_VERSION;
        }

        $locale = $this->packLoader->normalizeLocale(
            (string) ($ctx['locale'] ?? ($attempt->locale ?? 'zh-CN'))
        );
        $reportCompiled = $this->packLoader->readCompiledJson('report.compiled.json', $version);
        if (! is_array($reportCompiled)) {
            return [
                'ok' => false,
                'error' => 'REPORT_LAYOUT_MISSING',
                'message' => 'EQ_60 report compiled data missing.',
                'status' => 500,
            ];
        }
        $reportAssets = $this->packLoader->loadReportAssets($version);

        $score = $this->extractScoreResult($result);
        if (! is_array($score)) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'EQ_60 score result missing.',
                'status' => 500,
            ];
        }

        $modulesAllowed = ReportAccess::normalizeModules(is_array($ctx['modules_allowed'] ?? null) ? $ctx['modules_allowed'] : []);
        if ($modulesAllowed === [] && $variant === ReportAccess::VARIANT_FULL) {
            $modulesAllowed = ReportAccess::eq60AllRuntimeModules();
        } elseif ($modulesAllowed === []) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked(ReportAccess::SCALE_EQ_60);
        }

        $layoutSections = is_array(data_get($reportCompiled, 'layout.sections'))
            ? array_values(array_filter((array) data_get($reportCompiled, 'layout.sections'), 'is_array'))
            : [];
        if ($layoutSections === []) {
            $layoutSections = $this->defaultSections();
        }

        $layoutByKey = [];
        foreach ($layoutSections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $layoutByKey[$key] = $section;
        }

        $sections = [];
        foreach ($layoutSections as $sectionConfig) {
            if (! is_array($sectionConfig)) {
                continue;
            }

            $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $requiredVariants = $this->normalizeVariants((array) ($sectionConfig['required_in_variant'] ?? ['free', 'full']));
            if ($requiredVariants !== [] && ! in_array($variant, $requiredVariants, true)) {
                continue;
            }

            $source = strtolower(trim((string) ($sectionConfig['source'] ?? 'blocks')));
            $accessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
            $outputAccessLevel = $variant === ReportAccess::VARIANT_FULL ? 'free' : $accessLevel;
            $moduleCode = $this->normalizeModuleCode((string) ($sectionConfig['module_code'] ?? ReportAccess::MODULE_EQ_CORE));
            $maxBlocks = max(1, (int) ($sectionConfig['max_blocks'] ?? 1));

            if ($accessLevel === 'paid') {
                if ($variant !== ReportAccess::VARIANT_FULL) {
                    continue;
                }
                if (! in_array($moduleCode, $modulesAllowed, true)) {
                    continue;
                }
            }

            if ($source === 'copy') {
                $copySection = $this->composeCopySection($sectionKey, $locale, $outputAccessLevel, $moduleCode);
                if (is_array($copySection)) {
                    $sections[] = $copySection;
                }

                continue;
            }

            $sectionBlocks = $this->resolveSectionBlocks($reportCompiled, $locale, $sectionConfig, $score);
            if ($sectionBlocks === []) {
                continue;
            }

            if (count($sectionBlocks) > $maxBlocks) {
                $sectionBlocks = array_slice($sectionBlocks, 0, $maxBlocks);
            }

            $sections[] = [
                'key' => $sectionKey,
                'title' => $this->resolveSectionTitle($sectionKey, $locale, $layoutByKey),
                'access_level' => $outputAccessLevel,
                'module_code' => $moduleCode,
                'blocks' => $sectionBlocks,
            ];
        }

        [$compatFree, $compatPaid] = $this->buildCompatBlocks($sections);
        $v5Scores = $this->buildV5Scores($score, $locale);
        $dimensionSummary = array_values((array) ($v5Scores['dimension_summary'] ?? []));
        unset($v5Scores['dimension_summary']);
        $quality = $this->buildV5Quality($score);
        $routeMatrix = is_array(data_get($reportAssets, 'assets.personalization_routes'))
            ? (array) data_get($reportAssets, 'assets.personalization_routes')
            : [];
        $interpretation = $this->buildV5Interpretation($v5Scores, $quality, $routeMatrix);
        $crossAssessmentContext = $this->crossAssessmentContextGuard->build($attempt, $result);
        $assetRefs = $this->buildV5AssetRefs($interpretation, $quality, $crossAssessmentContext);
        $resolvedAssets = $this->resolveV5Assets($reportAssets, $locale, $interpretation, $quality, $crossAssessmentContext);
        $legacyCompat = $this->buildLegacyCompat($sections, $compatFree, $compatPaid, $score);

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'eq_60.report.v2',
                'scale_code' => 'EQ_60',
                'eq_report_mode' => 'self_report',
                'measurement_type' => 'self_report_trait_mixed_ei',
                'variant' => $variant,
                'locale' => $locale,
                'access' => [
                    'all_results_free' => true,
                    'locked' => false,
                    'blur' => false,
                    'paywall' => false,
                ],
                'display_contract' => [
                    'schema_version' => 'eq_60.display_contract.v1',
                    'authority' => 'v5_resolved_assets',
                    'user_visible_roots' => ['scores', 'dimension_summary', 'quality', 'interpretation', 'asset_refs', 'assets', 'next_module', 'methodology'],
                    'legacy_compat_user_visible' => false,
                ],
                'quality' => $quality,
                'scores' => $v5Scores,
                'dimension_summary' => $dimensionSummary,
                'interpretation' => $interpretation,
                'cross_assessment_context' => $crossAssessmentContext,
                'asset_refs' => $assetRefs,
                'assets' => $resolvedAssets,
                'legacy_compat' => $legacyCompat,
                'next_module' => [
                    'available' => false,
                    'module_code' => 'EQ_SJT_16',
                    'status' => 'planned',
                    'cta_asset_id' => 'eq.sjt_bridge.planned',
                ],
                'journey_state_contract' => [
                    'version' => 'eq_journey_state.v1',
                    'available' => true,
                    'endpoint' => '/api/v0.3/attempts/{attempt_id}/eq/journey',
                    'persistence' => 'consent_required',
                    'signals' => ['read_depth', 'result_resonance', 'action_completion', 'retest_intent'],
                    'affects_scores' => false,
                    'formal_report_mutation_allowed' => false,
                    'raw_feedback_public_exposure_allowed' => false,
                    'profile_memory_write' => false,
                ],
                'methodology' => [
                    'norm_status' => strtolower(trim((string) data_get($score, 'norms.status', 'provisional'))) ?: 'provisional',
                    'scoring_version' => (string) data_get($score, 'version_snapshot.engine_version', 'v1.0_normed_validity'),
                    'report_version' => 'eq_report_v5_assets_commercial_ready_v1_9',
                    'content_version' => Eq60PackLoader::PACK_ID.'/'.$version,
                ],
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @param  list<array<string,mixed>>  $compatFree
     * @param  list<array<string,mixed>>  $compatPaid
     * @param  array<string,mixed>  $score
     * @return array<string,mixed>
     */
    private function buildLegacyCompat(array $sections, array $compatFree, array $compatPaid, array $score): array
    {
        return [
            'schema_version' => 'eq_60.legacy_compat.v1',
            'user_visible' => false,
            'purpose' => 'legacy_renderer_compatibility_only',
            'sections' => $sections,
            'compat' => [
                'free_blocks' => $compatFree,
                'paid_blocks' => $compatPaid,
            ],
            'legacy_scores' => is_array($score['scores'] ?? null) ? $score['scores'] : [],
            'scorer_report' => is_array($score['report'] ?? null) ? $score['report'] : [],
            'report_tags' => array_values(array_filter(
                array_map('strval', (array) ($score['report_tags'] ?? [])),
                static fn (string $tag): bool => $tag !== ''
            )),
        ];
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array<string,mixed>
     */
    private function buildV5Scores(array $score, string $locale): array
    {
        $global = $this->scoreNode((array) data_get($score, 'scores.global', []), $this->globalScoreLabel($locale));
        $dimensions = [];
        $summary = [];
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $label = $this->dimensionLabel($code, $locale);
            $node = $this->scoreNode((array) data_get($score, 'scores.'.$code, []), $label, $label);
            $dimensions[$code] = $node;
            $summary[] = [
                'code' => $code,
                'label' => $label,
                'standard_score' => $node['standard_score'],
                'percentile' => $node['percentile'],
                'band' => $node['band'],
            ];
        }

        return [
            'global' => $global,
            'dimensions' => $dimensions,
            'dimension_summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function scoreNode(array $node, string $label, ?string $shortLabel = null): array
    {
        $level = strtolower(trim((string) ($node['level'] ?? $node['band'] ?? '')));
        $band = $this->displayBand($level);

        $payload = [
            'raw_score' => $this->nullableNumber($node['raw_sum'] ?? $node['raw_score'] ?? null),
            'standard_score' => $this->nullableNumber($node['std_score'] ?? $node['standard_score'] ?? null),
            'percentile' => $this->nullableNumber($node['percentile'] ?? null),
            'band' => $band,
            'label' => $label,
        ];
        if ($shortLabel !== null) {
            $payload['short_label'] = $shortLabel;
        }
        if ($level !== '' && $level !== $band) {
            $payload['source_band'] = $level;
        }

        return $payload;
    }

    private function nullableNumber(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return abs($float - round($float)) < 0.00001 ? (int) round($float) : $float;
    }

    private function displayBand(string $sourceBand): string
    {
        return match ($sourceBand) {
            'baseline' => 'foundational',
            'competent' => 'stable',
            'exceptional' => 'integrated',
            'developing', 'proficient', 'foundational', 'stable', 'integrated' => $sourceBand,
            default => 'stable',
        };
    }

    private function globalScoreLabel(string $locale): string
    {
        return $locale === 'zh-CN'
            ? '情绪与关系综合指数'
            : 'Emotional & Relational Functioning Index';
    }

    private function dimensionLabel(string $code, string $locale): string
    {
        $zh = [
            'SA' => '自我觉察',
            'ER' => '情绪调节',
            'EM' => '共情理解',
            'RM' => '关系管理',
        ];
        $en = [
            'SA' => 'Self-Awareness',
            'ER' => 'Emotion Regulation',
            'EM' => 'Empathy',
            'RM' => 'Relationship Management',
        ];
        $map = $locale === 'zh-CN' ? $zh : $en;

        return $map[$code] ?? $code;
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array<string,mixed>
     */
    private function buildV5Quality(array $score): array
    {
        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $level = strtoupper(trim((string) ($quality['level'] ?? '')));
        if ($level === '') {
            $level = 'A';
        }
        $quality['level'] = $level;
        $quality['flags'] = array_values(array_filter(array_map(
            static fn ($flag): string => strtoupper(trim((string) $flag)),
            (array) ($quality['flags'] ?? [])
        )));
        $quality['confidence_label'] = match ($level) {
            'A' => 'high',
            'B' => 'medium',
            default => 'low',
        };
        $quality['explanation_asset_id'] = 'eq.quality.level.'.$level;

        return $quality;
    }

    /**
     * @param  array<string,mixed>  $scores
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function buildV5Interpretation(array $scores, array $quality, array $routeMatrix = []): array
    {
        $dimensionScores = is_array($scores['dimensions'] ?? null) ? $scores['dimensions'] : [];
        $strongest = $this->rankDimension($dimensionScores, true);
        $developmentLever = $this->rankDimension($dimensionScores, false);
        $qualityLevel = strtoupper(trim((string) ($quality['level'] ?? '')));
        $formulationId = $this->selectCoreFormulation($dimensionScores, $qualityLevel);
        $route = $this->selectPersonalizationRoute($routeMatrix, $formulationId, $dimensionScores, $quality);
        $selectedAssetIds = $this->selectedAssetIds($route, $formulationId, $dimensionScores, $developmentLever);
        if ((array) ($selectedAssetIds['scene_variant_ids'] ?? []) === []) {
            $selectedAssetIds['scene_variant_ids'] = $this->selectSceneVariantIds($formulationId, $selectedAssetIds['scene_ids']);
        }
        $routeId = trim((string) ($route['route_id'] ?? $formulationId)) ?: $formulationId;
        $signalSignature = $this->buildSignalSignature(
            $routeId,
            $formulationId,
            $dimensionScores,
            $quality,
            $strongest,
            $developmentLever,
            $route
        );

        return [
            'route_id' => $routeId,
            'signal_signature' => $signalSignature,
            'core_formulation_id' => $formulationId,
            'strongest_dimension' => $strongest,
            'development_lever' => $developmentLever,
            'primary_mechanism_ids' => $selectedAssetIds['mechanism_ids'],
            'primary_scene_ids' => $selectedAssetIds['scene_ids'],
            'primary_scene_variant_ids' => $selectedAssetIds['scene_variant_ids'],
            'career_environment_ids' => $selectedAssetIds['career_environment_ids'],
            'action_prescription_id' => $selectedAssetIds['action_prescription_id'],
            'selected_asset_ids' => $selectedAssetIds,
            'personalization_route' => $this->publicPersonalizationRoute($route),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selectPersonalizationRoute(array $routeMatrix, string $formulationId, array $dimensionScores, array $quality): array
    {
        $routes = is_array($routeMatrix['routes'] ?? null) ? $routeMatrix['routes'] : [];
        if ($this->isList($routes)) {
            $qualityLevel = strtoupper(trim((string) ($quality['level'] ?? '')));
            $candidates = [];
            foreach ($routes as $route) {
                if (! is_array($route) || ! $this->matchesV2Route($route, $formulationId, $dimensionScores, $qualityLevel)) {
                    continue;
                }
                $candidates[] = $route;
            }
            if ($candidates !== []) {
                usort($candidates, static function (array $a, array $b): int {
                    $priority = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return strcmp((string) ($a['route_id'] ?? ''), (string) ($b['route_id'] ?? ''));
                });

                return $candidates[0];
            }

            $fallback = array_values(array_filter($routes, static fn ($route): bool => is_array($route) && (string) ($route['formulation_id'] ?? '') === $formulationId));

            return is_array($fallback[0] ?? null) ? $fallback[0] : [];
        }

        $route = $routes[$formulationId] ?? null;

        return is_array($route) ? $route : [];
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function matchesV2Route(array $route, string $formulationId, array $dimensionScores, string $qualityLevel): bool
    {
        $match = is_array($route['match'] ?? null) ? $route['match'] : [];
        $qualityLevels = array_values(array_map(
            static fn ($level): string => strtoupper(trim((string) $level)),
            (array) ($match['quality_levels'] ?? [])
        ));
        if ($qualityLevels !== [] && ! in_array($qualityLevel, $qualityLevels, true)) {
            return false;
        }

        $confidence = strtolower(trim((string) ($match['confidence'] ?? '')));
        if ($confidence === 'low' && ! in_array($qualityLevel, ['C', 'D'], true)) {
            return false;
        }
        if ($confidence === 'normal' && in_array($qualityLevel, ['C', 'D'], true)) {
            return false;
        }

        $pattern = is_array($match['dimension_pattern'] ?? null) ? $match['dimension_pattern'] : [];
        foreach ($pattern as $code => $expected) {
            if (! $this->matchesDimensionPattern($dimensionScores, strtoupper((string) $code), (string) $expected)) {
                return false;
            }
        }

        $gapPattern = is_array($match['score_gap_pattern'] ?? null) ? $match['score_gap_pattern'] : [];
        foreach ($gapPattern as $gapKey => $expected) {
            if (! $this->matchesScoreGapPattern($dimensionScores, (string) $gapKey, (string) $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function matchesDimensionPattern(array $dimensionScores, string $code, string $expected): bool
    {
        $bucket = $this->dimensionBucket($dimensionScores, $code);

        return match (strtolower(trim($expected))) {
            'high' => $bucket === 'high',
            'mid_high' => $bucket === 'mid_high',
            'mid' => in_array($bucket, ['mid_low', 'mid_high'], true),
            'mid_low' => $bucket === 'mid_low',
            'low' => $bucket === 'low',
            'low_or_mid_low' => in_array($bucket, ['low', 'mid_low'], true),
            'low_or_mid' => in_array($bucket, ['low', 'mid_low', 'mid_high'], true),
            'mid_or_high' => in_array($bucket, ['mid_low', 'mid_high', 'high'], true),
            default => false,
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function matchesScoreGapPattern(array $dimensionScores, string $gapKey, string $expected): bool
    {
        if ($expected !== '>=1_band' || ! str_contains($gapKey, '_minus_')) {
            return true;
        }
        [$left, $right] = array_map('strtoupper', explode('_minus_', $gapKey, 2));

        return $this->dimensionBucketRank($dimensionScores, $left) - $this->dimensionBucketRank($dimensionScores, $right) >= 1;
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function dimensionBucket(array $dimensionScores, string $code): string
    {
        $node = is_array($dimensionScores[$code] ?? null) ? $dimensionScores[$code] : [];
        $percentile = $this->nullableNumber($node['percentile'] ?? null);
        if (is_numeric($percentile)) {
            $value = (float) $percentile;
            if ($value >= 65.0) {
                return 'high';
            }
            if ($value >= 50.0) {
                return 'mid_high';
            }
            if ($value > 25.0) {
                return 'mid_low';
            }

            return 'low';
        }

        return match (strtolower(trim((string) ($node['band'] ?? '')))) {
            'integrated', 'proficient' => 'high',
            'stable' => 'mid_high',
            'developing' => 'mid_low',
            default => 'low',
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function dimensionBucketRank(array $dimensionScores, string $code): int
    {
        return match ($this->dimensionBucket($dimensionScores, $code)) {
            'high' => 3,
            'mid_high' => 2,
            'mid_low' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,array<string,mixed>>  $dimensionScores
     * @return array{core_formulation_id:string,mechanism_ids:list<string>,scene_ids:list<string>,career_environment_ids:list<string>,action_prescription_id:string}
     */
    private function selectedAssetIds(array $route, string $formulationId, array $dimensionScores, ?string $developmentLever): array
    {
        $selected = is_array($route['selected_asset_ids'] ?? null) ? $route['selected_asset_ids'] : [];
        $selectedCore = trim((string) ($selected['core_formulation_id'] ?? $this->stripAssetPrefix((string) ($selected['core_formulation'] ?? ''), 'eq.formulation.')));
        $selectedMechanisms = $selected['mechanism_ids'] ?? $selected['mechanisms'] ?? null;
        $selectedScenes = $selected['scene_ids'] ?? $selected['scenes'] ?? null;
        $selectedCareer = $selected['career_environment_ids'] ?? $selected['career_environment'] ?? null;
        $selectedAction = trim((string) ($selected['action_prescription_id'] ?? $this->stripAssetPrefix((string) ($selected['action_prescription'] ?? ''), 'eq.action.')));

        if (! in_array($selectedCore, $this->coreFormulationIds(), true)) {
            $selectedCore = $formulationId;
        }

        if (! in_array($selectedAction, $this->actionPrescriptionIds(), true)) {
            $selectedAction = $this->selectActionPrescriptionId($formulationId, $developmentLever);
        }

        $sceneSelection = $this->normalizeSceneSelection(is_array($selectedScenes) ? $selectedScenes : []);

        return [
            'core_formulation_id' => $selectedCore ?: $formulationId,
            'mechanism_ids' => $this->normalizeMechanismIds(is_array($selectedMechanisms) ? $selectedMechanisms : $this->selectMechanismIds($formulationId, $dimensionScores)),
            'scene_ids' => $sceneSelection['scene_ids'] !== [] ? $sceneSelection['scene_ids'] : $this->selectSceneIds($formulationId),
            'scene_variant_ids' => $sceneSelection['scene_variant_ids'],
            'career_environment_ids' => $this->normalizeCareerEnvironmentIds(is_array($selectedCareer) ? $selectedCareer : $this->selectCareerEnvironmentIds($formulationId)),
            'action_prescription_id' => $selectedAction ?: $this->selectActionPrescriptionId($formulationId, $developmentLever),
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return array{scene_ids:list<string>,scene_variant_ids:list<string>}
     */
    private function normalizeSceneSelection(array $values): array
    {
        $families = [];
        $variants = [];
        foreach ($values as $value) {
            $id = trim((string) $value);
            if ($id === '') {
                continue;
            }
            if (str_starts_with($id, 'eq.scene.')) {
                $variants[] = $id;
                $parts = explode('.', $id);
                if (isset($parts[2]) && trim($parts[2]) !== '') {
                    $families[] = trim($parts[2]);
                }

                continue;
            }
            $families[] = $id;
        }

        return [
            'scene_ids' => $this->nonEmptyStringList($families),
            'scene_variant_ids' => $this->nonEmptyStringList($variants),
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeMechanismIds(array $values): array
    {
        return $this->nonEmptyStringList(array_map(function (mixed $value): string {
            $id = trim((string) $value);
            if (str_starts_with($id, 'eq.mechanism.')) {
                $id = substr($id, strlen('eq.mechanism.'));
                $parts = explode('.', $id);
                if (count($parts) === 2) {
                    return $parts[0].'_'.$parts[1];
                }
            }

            return $id;
        }, $values));
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeCareerEnvironmentIds(array $values): array
    {
        return $this->nonEmptyStringList(array_map(function (mixed $value): string {
            $id = trim((string) $value);
            if (str_starts_with($id, 'eq.career_environment.')) {
                $id = substr($id, strlen('eq.career_environment.'));
                $parts = explode('.', $id);
                if (count($parts) === 2) {
                    return $parts[0].'_'.$parts[1];
                }
            }

            return $id;
        }, $values));
    }

    private function stripAssetPrefix(string $id, string $prefix): string
    {
        $id = trim($id);
        if (str_starts_with($id, $prefix)) {
            return substr($id, strlen($prefix));
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private function coreFormulationIds(): array
    {
        return [
            'balanced_integrated',
            'high_empathy_low_recovery',
            'aware_but_unregulated',
            'calm_but_distant',
            'relationship_first_self_later',
            'self_clear_repair_weak',
            'steady_collaborator',
            'sensitive_absorber',
            'developing_foundation',
            'low_confidence_result',
        ];
    }

    /**
     * @return list<string>
     */
    private function actionPrescriptionIds(): array
    {
        return [
            'emotion_labeling',
            'pause_recovery',
            'feedback_decompression',
            'empathy_boundary',
            'repair_after_conflict',
            'express_without_escalation',
            'support_without_rescuing',
            'cold_to_warm_response',
            'relationship_energy_management',
            'conflict_deescalation',
            'self_connection',
            'retest_reflection',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function buildSignalSignature(
        string $routeId,
        string $formulationId,
        array $dimensionScores,
        array $quality,
        ?string $strongest,
        ?string $developmentLever,
        array $route
    ): array {
        $dimensionStates = [];
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $dimensionStates[$code] = $this->dimensionState($dimensionScores, $code);
        }

        $matchPattern = $this->routeMatchPatternLabel(data_get($route, 'match.dimension_pattern', ''));
        if ($matchPattern === '' && $formulationId === 'low_confidence_result') {
            $matchPattern = 'quality_low_overrides_dimension_pattern';
        }

        return [
            'schema' => 'eq60.signal_signature.v1',
            'route_id' => $routeId,
            'formulation_id' => $formulationId,
            'quality_level' => strtoupper(trim((string) ($quality['level'] ?? ''))),
            'confidence_label' => (string) ($quality['confidence_label'] ?? ''),
            'dimension_states' => $dimensionStates,
            'strongest_dimension' => $strongest,
            'development_lever' => $developmentLever,
            'match_pattern' => $matchPattern,
            'route_priority' => (int) ($route['priority'] ?? 0),
            'route_claim_risk' => (string) ($route['claim_risk'] ?? ''),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function dimensionState(array $dimensionScores, string $code): string
    {
        if ($this->isDimensionHigh($dimensionScores, $code)) {
            return 'high';
        }
        if ($this->isDimensionLow($dimensionScores, $code)) {
            return 'low';
        }

        return 'middle';
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function selectCoreFormulation(array $dimensionScores, string $qualityLevel): string
    {
        if (in_array($qualityLevel, ['C', 'D'], true)) {
            return 'low_confidence_result';
        }

        $high = [];
        $low = [];
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $high[$code] = $this->isDimensionHigh($dimensionScores, $code);
            $low[$code] = $this->isDimensionLow($dimensionScores, $code);
        }

        $lowCount = count(array_filter($low));
        if ($lowCount >= 3) {
            return 'developing_foundation';
        }
        if (count(array_filter($high)) >= 4) {
            return 'balanced_integrated';
        }

        if ($high['EM'] && $low['ER']) {
            return 'high_empathy_low_recovery';
        }
        if ($high['SA'] && $low['ER']) {
            return 'aware_but_unregulated';
        }
        if ($high['SA'] && $high['EM'] && ! $high['ER']) {
            return 'sensitive_absorber';
        }
        if ($high['ER'] && $low['EM']) {
            return 'calm_but_distant';
        }
        if ($high['RM'] && $low['SA']) {
            return 'relationship_first_self_later';
        }
        if ($high['SA'] && $low['RM']) {
            return 'self_clear_repair_weak';
        }
        if ($high['ER'] && $high['RM']) {
            return 'steady_collaborator';
        }

        return 'balanced_integrated';
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     * @return list<string>
     */
    private function selectMechanismIds(string $formulationId, array $dimensionScores): array
    {
        return match ($formulationId) {
            'high_empathy_low_recovery' => ['EM_ER_high_low'],
            'aware_but_unregulated' => ['SA_ER_high_low'],
            'calm_but_distant' => ['EM_ER_low_high'],
            'relationship_first_self_later' => ['SA_RM_low_high'],
            'self_clear_repair_weak' => ['SA_RM_high_low'],
            'steady_collaborator' => ['ER_RM_high_high'],
            'sensitive_absorber' => ['EM_ER_high_low', 'SA_ER_high_low'],
            'developing_foundation' => ['ER_RM_low_low'],
            'low_confidence_result' => [],
            default => [
                $this->mechanismId('SA_ER', 'SA', 'ER', $dimensionScores),
                $this->mechanismId('EM_RM', 'EM', 'RM', $dimensionScores),
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function selectSceneIds(string $formulationId): array
    {
        return match ($formulationId) {
            'high_empathy_low_recovery', 'sensitive_absorber' => ['feedback', 'conflict', 'relationship_boundary'],
            'aware_but_unregulated' => ['feedback', 'pressure_recovery', 'conflict'],
            'calm_but_distant' => ['feedback', 'team_collaboration', 'conflict'],
            'relationship_first_self_later' => ['relationship_boundary', 'team_collaboration', 'career_environment'],
            'self_clear_repair_weak' => ['conflict', 'feedback', 'relationship_boundary'],
            'steady_collaborator' => ['team_collaboration', 'conflict', 'career_environment'],
            'developing_foundation', 'low_confidence_result' => ['pressure_recovery', 'feedback', 'career_environment'],
            default => ['feedback', 'team_collaboration', 'career_environment'],
        };
    }

    /**
     * @param  list<string>  $sceneIds
     * @return list<string>
     */
    private function selectSceneVariantIds(string $formulationId, array $sceneIds): array
    {
        $safeFormulationId = trim($formulationId) !== '' ? trim($formulationId) : 'balanced_integrated';

        return array_values(array_map(
            static fn (string $sceneId): string => 'eq.scene.'.trim($sceneId).'.'.$safeFormulationId.'.primary',
            array_values(array_filter($sceneIds, static fn (string $sceneId): bool => trim($sceneId) !== ''))
        ));
    }

    /**
     * @return list<string>
     */
    private function selectCareerEnvironmentIds(string $formulationId): array
    {
        return match ($formulationId) {
            'high_empathy_low_recovery', 'sensitive_absorber' => ['emotional_labor_high', 'autonomy_recovery_medium', 'interpersonal_density_medium'],
            'aware_but_unregulated' => ['feedback_intensity_medium', 'autonomy_recovery_high', 'conflict_frequency_low'],
            'calm_but_distant' => ['emotional_labor_low', 'conflict_frequency_medium', 'collaboration_complexity_medium'],
            'relationship_first_self_later' => ['interpersonal_density_high', 'emotional_labor_medium', 'autonomy_recovery_medium'],
            'self_clear_repair_weak' => ['conflict_frequency_medium', 'feedback_intensity_medium', 'collaboration_complexity_low'],
            'steady_collaborator' => ['collaboration_complexity_high', 'conflict_frequency_medium', 'interpersonal_density_high'],
            'developing_foundation', 'low_confidence_result' => ['feedback_intensity_low', 'conflict_frequency_low', 'autonomy_recovery_high'],
            default => ['interpersonal_density_medium', 'feedback_intensity_medium', 'autonomy_recovery_medium'],
        };
    }

    private function selectActionPrescriptionId(string $formulationId, ?string $developmentLever): string
    {
        if ($formulationId === 'low_confidence_result') {
            return 'retest_reflection';
        }

        return match ($formulationId) {
            'high_empathy_low_recovery', 'sensitive_absorber' => 'empathy_boundary',
            'aware_but_unregulated' => 'pause_recovery',
            'calm_but_distant' => 'cold_to_warm_response',
            'relationship_first_self_later' => 'self_connection',
            'self_clear_repair_weak' => 'express_without_escalation',
            'steady_collaborator' => 'repair_after_conflict',
            'developing_foundation' => 'emotion_labeling',
            default => match ($developmentLever) {
                'ER' => 'pause_recovery',
                'EM' => 'cold_to_warm_response',
                'RM' => 'repair_after_conflict',
                'SA' => 'emotion_labeling',
                default => 'feedback_decompression',
            },
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function mechanismId(string $pair, string $left, string $right, array $dimensionScores): string
    {
        $leftState = $this->isDimensionHigh($dimensionScores, $left) ? 'high' : 'low';
        $rightState = $this->isDimensionHigh($dimensionScores, $right) ? 'high' : 'low';

        return $pair.'_'.$leftState.'_'.$rightState;
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function isDimensionHigh(array $dimensionScores, string $code): bool
    {
        $node = is_array($dimensionScores[$code] ?? null) ? $dimensionScores[$code] : [];
        $band = strtolower(trim((string) ($node['band'] ?? '')));
        if (in_array($band, ['proficient', 'integrated'], true)) {
            return true;
        }

        $percentile = $this->nullableNumber($node['percentile'] ?? null);

        return is_numeric($percentile) && (float) $percentile >= 65.0;
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function isDimensionLow(array $dimensionScores, string $code): bool
    {
        $node = is_array($dimensionScores[$code] ?? null) ? $dimensionScores[$code] : [];
        $band = strtolower(trim((string) ($node['band'] ?? '')));
        if (in_array($band, ['foundational', 'developing'], true)) {
            return true;
        }

        $percentile = $this->nullableNumber($node['percentile'] ?? null);

        return is_numeric($percentile) && (float) $percentile <= 25.0;
    }

    /**
     * @param  array<string,mixed>  $interpretation
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function buildV5AssetRefs(array $interpretation, array $quality, array $crossAssessmentContext): array
    {
        return [
            'personalization_route_id' => (string) ($interpretation['route_id'] ?? ''),
            'signal_signature' => is_array($interpretation['signal_signature'] ?? null) ? $interpretation['signal_signature'] : [],
            'selected_asset_ids' => is_array($interpretation['selected_asset_ids'] ?? null) ? $interpretation['selected_asset_ids'] : [],
            'scientific_contract_id' => 'eq.scientific_contract.default',
            'score_system_id' => 'eq.score_system.default',
            'core_formulation_id' => (string) ($interpretation['core_formulation_id'] ?? ''),
            'quality_explanation_asset_id' => (string) ($quality['explanation_asset_id'] ?? ''),
            'mechanism_ids' => array_values(array_map('strval', (array) ($interpretation['primary_mechanism_ids'] ?? []))),
            'scene_ids' => array_values(array_map('strval', (array) ($interpretation['primary_scene_ids'] ?? []))),
            'scene_variant_ids' => array_values(array_map('strval', (array) ($interpretation['primary_scene_variant_ids'] ?? []))),
            'career_environment_ids' => array_values(array_map('strval', (array) ($interpretation['career_environment_ids'] ?? []))),
            'action_prescription_id' => (string) ($interpretation['action_prescription_id'] ?? ''),
            'cross_assessment_context_ids' => array_values(array_map('strval', (array) ($crossAssessmentContext['context_asset_ids'] ?? []))),
            'cross_assessment_boundary_id' => (string) ($crossAssessmentContext['boundary_asset_id'] ?? ''),
            'sjt_bridge_id' => 'eq.sjt_bridge.planned',
            'result_snapshot_id' => 'eq.snapshot.'.(string) ($interpretation['core_formulation_id'] ?? ''),
            'commercial_conversion_ids' => [
                'eq.conversion.save_report',
                'eq.conversion.email_revisit',
                'eq.conversion.pdf_export',
                'eq.conversion.share_card',
                'eq.conversion.retest_reminder',
                'eq.conversion.related_tests',
                'eq.conversion.agent_entry',
            ],
            'quality_confidence_id' => (string) ($quality['explanation_asset_id'] ?? ''),
            'psychometric_evidence_ids' => [
                'eq.evidence.norm_status',
                'eq.evidence.content_validity',
                'eq.evidence.internal_consistency',
                'eq.evidence.factor_structure',
                'eq.evidence.test_retest',
                'eq.evidence.measurement_invariance',
                'eq.evidence.criterion_validity',
            ],
            'result_page_depth_module_ids' => [
                'eq.depth.how_to_read.default',
                'eq.depth.evidence_stack.default',
                'eq.depth.reality_check.default',
            ],
            'agent_playbook_ids' => [
                'eq.agent.playbook.understand_result',
                'eq.agent.playbook.scene_advice',
                'eq.agent.playbook.career_environment',
                'eq.agent.playbook.low_confidence',
                'eq.agent.playbook.unsupported_hiring',
            ],
            'backend_integration_contract_ids' => [
                'eq.backend_contract.schema_mapping',
                'eq.backend_contract.frontend_consumption',
                'eq.backend_contract.canonical_fixtures',
                'eq.backend_contract.contract_tests',
                'eq.backend_contract.agent_readiness',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $assets
     * @param  array<string,mixed>  $interpretation
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function resolveV5Assets(
        array $assets,
        string $locale,
        array $interpretation,
        array $quality,
        array $crossAssessmentContext
    ): array {
        $docs = is_array($assets['assets'] ?? null) ? $assets['assets'] : [];
        $scientificAssets = (array) data_get($docs, 'scientific_contract.assets', []);
        $sjtAssets = (array) data_get($docs, 'sjt_bridge.assets', []);
        $crossContextAssets = (array) data_get($docs, 'cross_assessment_context.assets', []);
        $snapshotAssets = (array) data_get($docs, 'result_snapshot.assets', []);
        $conversionAssets = (array) data_get($docs, 'commercial_conversion_assets.assets', []);
        $qualityConfidenceAssets = (array) data_get($docs, 'quality_confidence.assets', []);
        $evidenceAssets = (array) data_get($docs, 'psychometric_evidence_status.assets', []);
        $depthAssets = (array) data_get($docs, 'result_page_depth_modules.assets', []);
        $sceneVariantAssets = (array) data_get($docs, 'reality_scene_variants.assets', []);
        $agentAssets = (array) data_get($docs, 'agent_dialogue_playbooks.assets', []);
        $backendContractAssets = (array) data_get($docs, 'backend_integration_contract.assets', []);
        $formulationId = (string) ($interpretation['core_formulation_id'] ?? '');
        $actionId = (string) ($interpretation['action_prescription_id'] ?? '');
        $snapshotId = 'eq.snapshot.'.$formulationId;
        if (! isset($snapshotAssets[$snapshotId])) {
            $snapshotId = 'eq.snapshot.low_confidence_result';
        }
        $resultSnapshot = array_merge(
            ['id' => $snapshotId],
            $this->localizedAsset((array) ($snapshotAssets[$snapshotId] ?? []), $locale)
        );

        $mechanisms = [];
        foreach ((array) ($interpretation['primary_mechanism_ids'] ?? []) as $idRaw) {
            $id = trim((string) $idRaw);
            $asset = $this->resolveMechanismAsset((array) data_get($docs, 'mechanism_map.pairs', []), $id, $locale);
            if ($asset !== null) {
                $mechanisms[] = $asset;
            }
        }

        $scenes = [];
        $sceneIds = array_values(array_map('strval', (array) ($interpretation['primary_scene_ids'] ?? [])));
        $sceneVariantIds = array_values(array_map('strval', (array) ($interpretation['primary_scene_variant_ids'] ?? [])));
        foreach ($sceneIds as $idx => $familyRaw) {
            $family = trim((string) $familyRaw);
            if ($family === '') {
                continue;
            }
            $variantId = trim((string) ($sceneVariantIds[$idx] ?? ''));
            $variantNode = $variantId !== '' ? ($sceneVariantAssets[$variantId] ?? null) : null;
            if (is_array($variantNode)) {
                $scenes[] = array_merge(
                    [
                        'id' => $variantId,
                        'scene_family' => (string) ($variantNode['scene_family'] ?? $family),
                        'variant' => (string) ($variantNode['variant'] ?? 'primary'),
                        'fallback_scene_id' => $family,
                    ],
                    $this->localizedAsset($variantNode, $locale)
                );

                continue;
            }

            $node = data_get($docs, 'reality_translation.scenes.'.$family);
            if (is_array($node)) {
                $scenes[] = array_merge(['id' => $family, 'scene_family' => $family, 'variant' => 'generic'], $this->localizedAsset($node, $locale));
            }
        }

        $career = [];
        foreach ((array) ($interpretation['career_environment_ids'] ?? []) as $idRaw) {
            $id = trim((string) $idRaw);
            $asset = $this->resolveCareerAsset((array) data_get($docs, 'career_environment.variables', []), $id, $locale);
            if ($asset !== null) {
                $career[] = $asset;
            }
        }
        $conversionActions = [];
        foreach ([
            'eq.conversion.save_report',
            'eq.conversion.email_revisit',
            'eq.conversion.pdf_export',
            'eq.conversion.share_card',
            'eq.conversion.retest_reminder',
            'eq.conversion.related_tests',
            'eq.conversion.agent_entry',
        ] as $id) {
            $asset = $this->localizedAsset((array) ($conversionAssets[$id] ?? []), $locale);
            if ($asset !== []) {
                $conversionActions[] = array_merge(['id' => $id], $asset);
            }
        }

        $psychometricEvidence = [];
        foreach ([
            'eq.evidence.norm_status',
            'eq.evidence.content_validity',
            'eq.evidence.internal_consistency',
            'eq.evidence.factor_structure',
            'eq.evidence.test_retest',
            'eq.evidence.measurement_invariance',
            'eq.evidence.criterion_validity',
        ] as $id) {
            $asset = $this->localizedAsset((array) ($evidenceAssets[$id] ?? []), $locale);
            if ($asset !== []) {
                $psychometricEvidence[] = array_merge(['id' => $id], $asset);
            }
        }

        $resultPageDepthModules = [];
        foreach ([
            'eq.depth.how_to_read.default',
            'eq.depth.evidence_stack.default',
            'eq.depth.reality_check.default',
        ] as $id) {
            $asset = $this->localizedAsset((array) ($depthAssets[$id] ?? []), $locale);
            if ($asset !== []) {
                $resultPageDepthModules[] = array_merge(
                    [
                        'id' => $id,
                        'placement' => (string) data_get($depthAssets, $id.'.meta.placement', ''),
                    ],
                    $asset
                );
            }
        }

        $agentPlaybooks = [];
        foreach ([
            'eq.agent.playbook.understand_result',
            'eq.agent.playbook.scene_advice',
            'eq.agent.playbook.career_environment',
            'eq.agent.playbook.low_confidence',
            'eq.agent.playbook.unsupported_hiring',
        ] as $id) {
            $asset = $this->localizedAsset((array) ($agentAssets[$id] ?? []), $locale);
            if ($asset !== []) {
                $agentPlaybooks[] = array_merge(['id' => $id], $asset);
            }
        }

        $backendIntegrationContract = [];
        foreach ([
            'eq.backend_contract.schema_mapping',
            'eq.backend_contract.frontend_consumption',
            'eq.backend_contract.canonical_fixtures',
            'eq.backend_contract.contract_tests',
            'eq.backend_contract.agent_readiness',
        ] as $id) {
            $asset = $this->localizedAsset((array) ($backendContractAssets[$id] ?? []), $locale);
            if ($asset !== []) {
                $backendIntegrationContract[] = array_merge(['id' => $id], $asset);
            }
        }

        return [
            'result_snapshot' => $resultSnapshot,
            'commercial_conversion_actions' => $conversionActions,
            'scientific_contract' => $this->localizedAsset((array) ($scientificAssets['eq.scientific_contract.default'] ?? []), $locale),
            'score_system' => $this->localizedScoreSystem((array) ($docs['score_system'] ?? []), $locale),
            'core_formulation' => array_merge(
                ['id' => $formulationId],
                $this->localizedAsset((array) data_get($docs, 'core_formulations.formulations.'.$formulationId, []), $locale)
            ),
            'mechanisms' => $mechanisms,
            'reality_scenes' => $scenes,
            'career_environment' => $career,
            'action_prescription' => array_merge(
                ['id' => $actionId],
                $this->localizedAsset((array) data_get($docs, 'action_prescriptions.prescriptions.'.$actionId, []), $locale)
            ),
            'sjt_bridge' => array_merge(
                ['id' => 'eq.sjt_bridge.planned', 'available' => false],
                $this->localizedAsset((array) ($sjtAssets['eq.sjt_bridge.planned'] ?? []), $locale)
            ),
            'cross_assessment_context' => $this->resolveCrossAssessmentAssets($crossContextAssets, $locale, $crossAssessmentContext),
            'quality' => [
                'explanation_asset_id' => (string) ($quality['explanation_asset_id'] ?? ''),
                'confidence_label' => (string) ($quality['confidence_label'] ?? ''),
            ],
            'quality_confidence' => array_merge(
                ['id' => (string) ($quality['explanation_asset_id'] ?? '')],
                $this->localizedAsset((array) ($qualityConfidenceAssets[(string) ($quality['explanation_asset_id'] ?? '')] ?? []), $locale)
            ),
            'psychometric_evidence_status' => $psychometricEvidence,
            'result_page_depth_modules' => $resultPageDepthModules,
            'agent_dialogue_playbooks' => $agentPlaybooks,
            'backend_integration_contract' => $backendIntegrationContract,
            'personalization_route' => [
                'id' => (string) ($interpretation['route_id'] ?? ''),
                'signal_signature' => is_array($interpretation['signal_signature'] ?? null) ? $interpretation['signal_signature'] : [],
                'selected_asset_ids' => is_array($interpretation['selected_asset_ids'] ?? null) ? $interpretation['selected_asset_ids'] : [],
                ...$this->localizedPersonalizationRoute((array) ($interpretation['personalization_route'] ?? []), $locale),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $assets
     * @param  array<string,mixed>  $crossAssessmentContext
     * @return array<string,mixed>
     */
    private function resolveCrossAssessmentAssets(array $assets, string $locale, array $crossAssessmentContext): array
    {
        $cards = [];
        foreach ((array) ($crossAssessmentContext['context_asset_ids'] ?? []) as $idRaw) {
            $id = trim((string) $idRaw);
            if ($id === '') {
                continue;
            }

            $asset = $this->localizedAsset((array) ($assets[$id] ?? []), $locale);
            if ($asset !== []) {
                $cards[] = array_merge(['id' => $id], $asset);
            }
        }

        $boundaryId = trim((string) ($crossAssessmentContext['boundary_asset_id'] ?? 'eq.cross_context.boundary.default'));
        if ($boundaryId === '') {
            $boundaryId = 'eq.cross_context.boundary.default';
        }

        return [
            'schema' => 'eq_cross_assessment_context.assets.v1',
            'status' => (string) ($crossAssessmentContext['status'] ?? 'no_source_assessments'),
            'source_count' => (int) ($crossAssessmentContext['source_count'] ?? 0),
            'boundary' => array_merge(
                ['id' => $boundaryId],
                $this->localizedAsset((array) ($assets[$boundaryId] ?? []), $locale)
            ),
            'cards' => $cards,
        ];
    }

    /**
     * @return list<string>
     */
    private function nonEmptyStringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<string,mixed>  $pairs
     * @return array<string,mixed>|null
     */
    private function resolveMechanismAsset(array $pairs, string $id, string $locale): ?array
    {
        foreach (['high_high', 'high_low', 'low_high', 'low_low'] as $state) {
            $suffix = '_'.$state;
            if (! str_ends_with($id, $suffix)) {
                continue;
            }
            $pair = substr($id, 0, -strlen($suffix));
            $node = $pairs[$pair][$state] ?? null;
            if (! is_array($node)) {
                return null;
            }

            return array_merge(['id' => $id, 'pair' => $pair, 'state' => $state], $this->localizedAsset($node, $locale));
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>|null
     */
    private function resolveCareerAsset(array $variables, string $id, string $locale): ?array
    {
        foreach (['low', 'medium', 'high'] as $level) {
            $suffix = '_'.$level;
            if (! str_ends_with($id, $suffix)) {
                continue;
            }
            $variable = substr($id, 0, -strlen($suffix));
            $node = $variables[$variable][$level] ?? null;
            if (! is_array($node)) {
                return null;
            }

            return array_merge(['id' => $id, 'variable' => $variable, 'level' => $level], $this->localizedAsset($node, $locale));
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function publicPersonalizationRoute(array $route): array
    {
        return [
            'route_id' => (string) ($route['route_id'] ?? ''),
            'formulation_id' => (string) ($route['formulation_id'] ?? ''),
            'priority' => (int) ($route['priority'] ?? 0),
            'claim_risk' => (string) ($route['claim_risk'] ?? ''),
            'why_this_route_exists' => is_array($route['why_this_route_exists'] ?? null) ? $route['why_this_route_exists'] : [],
            'do_not_overread' => is_array($route['do_not_overread'] ?? null) ? $route['do_not_overread'] : [],
            'commercial_depth' => is_array($route['commercial_depth'] ?? null) ? $route['commercial_depth'] : [],
            'locales' => is_array($route['locales'] ?? null) ? $route['locales'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function localizedPersonalizationRoute(array $route, string $locale): array
    {
        $primary = $this->packLoader->normalizeLocale($locale);
        $localized = data_get($route, 'locales.'.$primary);
        if (! is_array($localized)) {
            $localized = data_get($route, 'locales.'.($primary === 'zh-CN' ? 'en' : 'zh-CN'), []);
        }

        return [
            'formulation_id' => (string) ($route['formulation_id'] ?? ''),
            'priority' => (int) ($route['priority'] ?? 0),
            'claim_risk' => (string) ($route['claim_risk'] ?? ''),
            'why_this_route_exists' => (string) data_get($route, 'why_this_route_exists.'.$primary, ''),
            'do_not_overread' => (string) data_get($route, 'do_not_overread.'.$primary, ''),
            'commercial_depth' => is_array($route['commercial_depth'] ?? null) ? $route['commercial_depth'] : [],
            ...(is_array($localized) ? $localized : []),
        ];
    }

    private function routeMatchPatternLabel(mixed $pattern): string
    {
        if (is_string($pattern)) {
            return $pattern;
        }
        if (! is_array($pattern)) {
            return '';
        }
        if ($pattern === []) {
            return '';
        }

        ksort($pattern);

        return implode('_', array_map(
            static fn (string $code, mixed $state): string => strtoupper($code).'_'.strtolower(trim((string) $state)),
            array_keys($pattern),
            array_values($pattern)
        ));
    }

    private function isList(array $values): bool
    {
        return array_is_list($values);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function localizedAsset(array $asset, string $locale): array
    {
        $primary = $this->packLoader->normalizeLocale($locale);
        $node = $asset[$primary] ?? ($asset[$primary === 'zh-CN' ? 'en' : 'zh-CN'] ?? []);

        return is_array($node) ? $node : [];
    }

    /**
     * @param  array<string,mixed>  $scoreSystem
     * @return array<string,mixed>
     */
    private function localizedScoreSystem(array $scoreSystem, string $locale): array
    {
        $out = [
            'global_index' => $this->localizedAsset((array) ($scoreSystem['global_index'] ?? []), $locale),
            'score_notes' => $this->localizedAsset((array) ($scoreSystem['score_notes'] ?? []), $locale),
            'bands' => [],
            'band_details' => [],
            'dimensions' => [],
        ];

        foreach ((array) ($scoreSystem['bands'] ?? []) as $band => $node) {
            if (! is_array($node)) {
                continue;
            }
            $localized = $this->localizedScalar($node, $locale);
            if ($localized !== '') {
                $out['bands'][(string) $band] = $localized;
            }
        }
        foreach ((array) ($scoreSystem['band_details'] ?? []) as $band => $node) {
            if (is_array($node)) {
                $out['band_details'][(string) $band] = $this->localizedAsset($node, $locale);
            }
        }

        foreach ((array) ($scoreSystem['dimensions'] ?? []) as $code => $node) {
            if (is_array($node)) {
                $out['dimensions'][(string) $code] = $this->localizedAsset($node, $locale);
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function localizedScalar(array $node, string $locale): string
    {
        $primary = $this->packLoader->normalizeLocale($locale);

        return trim((string) ($node[$primary] ?? ($node[$primary === 'zh-CN' ? 'en' : 'zh-CN'] ?? '')));
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensionScores
     */
    private function rankDimension(array $dimensionScores, bool $highest): ?string
    {
        $ranked = [];
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $node = is_array($dimensionScores[$code] ?? null) ? $dimensionScores[$code] : [];
            $ranked[$code] = $this->nullableNumber($node['percentile'] ?? null)
                ?? $this->nullableNumber($node['standard_score'] ?? null)
                ?? $this->nullableNumber($node['raw_score'] ?? null)
                ?? 0;
        }

        $highest ? arsort($ranked) : asort($ranked);
        $code = array_key_first($ranked);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractScoreResult(Result $result): ?array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            if (strtoupper((string) ($candidate['scale_code'] ?? '')) !== 'EQ_60') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function composeCopySection(string $sectionKey, string $locale, string $accessLevel, string $moduleCode): ?array
    {
        if ($sectionKey !== 'disclaimer_top') {
            return null;
        }

        return [
            'key' => 'disclaimer_top',
            'title' => $this->resolveSectionTitle('disclaimer_top', $locale),
            'access_level' => $accessLevel,
            'module_code' => $moduleCode,
            'blocks' => [[
                'id' => 'eq_disclaimer_top',
                'type' => 'markdown',
                'title' => '',
                'content' => $locale === 'zh-CN'
                    ? '本测评仅供自我探索参考，不构成医疗诊断或治疗建议。'
                    : 'This assessment is for self-reflection only and is not medical diagnosis or treatment advice.',
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $sectionConfig
     * @param  array<string,mixed>  $score
     * @return list<array<string,mixed>>
     */
    private function resolveSectionBlocks(
        array $compiled,
        string $locale,
        array $sectionConfig,
        array $score
    ): array {
        $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
        if ($sectionKey === '') {
            return [];
        }

        $sectionAccessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
        $maxBlocks = max(1, (int) ($sectionConfig['max_blocks'] ?? 1));

        $allBlocks = array_values(array_filter((array) ($compiled['blocks'] ?? []), 'is_array'));
        if ($allBlocks === []) {
            return [];
        }

        $selectionTags = $this->buildSelectionTagsForSection($sectionConfig, $score);
        $selected = [];

        foreach ($this->localeFallbackOrder($locale) as $candidateLocale) {
            $localeCandidates = [];
            foreach ($allBlocks as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blockSection = trim((string) ($block['section'] ?? $block['section_key'] ?? ''));
                if ($blockSection !== $sectionKey) {
                    continue;
                }

                $blockLocale = $this->packLoader->normalizeLocale((string) ($block['locale'] ?? 'zh-CN'));
                if ($blockLocale !== $candidateLocale) {
                    continue;
                }

                $blockAccessLevel = strtolower(trim((string) ($block['access_level'] ?? 'free')));
                if ($blockAccessLevel !== $sectionAccessLevel) {
                    continue;
                }

                if (! $this->matchBlock($block, $selectionTags)) {
                    continue;
                }

                $localeCandidates[] = $block;
            }

            if ($localeCandidates !== []) {
                $selected = $localeCandidates;
                break;
            }
        }

        if ($selected === []) {
            return [];
        }

        usort($selected, [$this, 'comparePriority']);
        $selected = $this->enforceExclusiveGroup($selected);
        if (count($selected) > $maxBlocks) {
            $selected = array_slice($selected, 0, $maxBlocks);
        }

        $renderCtx = [
            'quality' => is_array($score['quality'] ?? null) ? $score['quality'] : [],
            'scores' => is_array($score['scores'] ?? null) ? $score['scores'] : [],
            'report' => is_array($score['report'] ?? null) ? $score['report'] : [],
            'report_tags' => array_values(array_filter(
                array_map('strval', (array) ($score['report_tags'] ?? [])),
                static fn (string $tag): bool => $tag !== ''
            )),
        ];
        $renderCtx['report_tags_csv'] = implode(', ', (array) ($renderCtx['report_tags'] ?? []));

        $blocks = [];
        foreach ($selected as $row) {
            if (! is_array($row)) {
                continue;
            }

            $blocks[] = [
                'id' => (string) ($row['block_id'] ?? ''),
                'type' => 'markdown',
                'title' => (string) ($row['title'] ?? ''),
                'content' => $this->renderTemplate((string) ($row['body'] ?? ($row['body_md'] ?? '')), $renderCtx),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $sectionConfig
     * @param  array<string,mixed>  $score
     * @return list<string>
     */
    private function buildSelectionTagsForSection(array $sectionConfig, array $score): array
    {
        $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
        $tags = [];
        if ($sectionKey !== '') {
            $tags[] = 'section:'.$sectionKey;
        }

        $qualityLevel = strtoupper(trim((string) data_get($score, 'quality.level', '')));
        if ($qualityLevel !== '') {
            $tags[] = 'quality_level:'.$qualityLevel;
        }

        foreach ((array) data_get($score, 'quality.flags', []) as $flag) {
            $normalizedFlag = strtoupper(trim((string) $flag));
            if ($normalizedFlag !== '') {
                $tags[] = 'quality_flag:'.$normalizedFlag;
            }
        }

        $sectionDim = $this->sectionDimensionMap($sectionKey);
        if ($sectionDim !== null) {
            $level = strtolower(trim((string) data_get($score, 'scores.'.$sectionDim.'.level', '')));
            if ($level !== '') {
                $tags[] = 'bucket:'.$level;
            }
        } elseif ($sectionKey === 'global_overview') {
            $globalLevel = strtolower(trim((string) data_get($score, 'scores.global.level', '')));
            if ($globalLevel !== '') {
                $tags[] = 'bucket:'.$globalLevel;
            }
        }

        foreach ((array) ($score['report_tags'] ?? []) as $tag) {
            $normalizedTag = trim((string) $tag);
            if ($normalizedTag !== '') {
                $tags[] = $normalizedTag;
            }
        }

        $primaryProfile = trim((string) data_get($score, 'report.primary_profile', ''));
        if ($primaryProfile !== '') {
            $tags[] = $primaryProfile;
        }

        foreach ((array) ($sectionConfig['fallback_tags'] ?? []) as $fallbackTag) {
            $normalizedTag = trim((string) $fallbackTag);
            if ($normalizedTag !== '') {
                $tags[] = $normalizedTag;
            }
        }

        $tags = array_values(array_unique(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            $tags
        ))));

        return $tags;
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  list<string>  $selectionTags
     */
    private function matchBlock(array $block, array $selectionTags): bool
    {
        $selectionSet = array_fill_keys($selectionTags, true);

        $tagsAll = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) ($block['tags_all'] ?? [])
        )));

        foreach ($tagsAll as $tag) {
            if (! isset($selectionSet[$tag])) {
                return false;
            }
        }

        $tagsAny = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) ($block['tags_any'] ?? [])
        )));

        if ($tagsAny === []) {
            return true;
        }

        foreach ($tagsAny as $tag) {
            if (isset($selectionSet[$tag])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    private function enforceExclusiveGroup(array $blocks): array
    {
        $out = [];
        $seen = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $group = trim((string) ($block['exclusive_group'] ?? ''));
            if ($group === '') {
                $out[] = $block;

                continue;
            }

            if (isset($seen[$group])) {
                continue;
            }

            $seen[$group] = true;
            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function comparePriority(array $a, array $b): int
    {
        $priorityCompare = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }

        return strcmp((string) ($a['block_id'] ?? ''), (string) ($b['block_id'] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function localeFallbackOrder(string $locale): array
    {
        $normalized = $this->packLoader->normalizeLocale($locale);

        return $normalized === 'zh-CN'
            ? ['zh-CN', 'en']
            : ['en', 'zh-CN'];
    }

    private function sectionDimensionMap(string $sectionKey): ?string
    {
        return match ($sectionKey) {
            'self_awareness' => 'SA',
            'emotion_regulation' => 'ER',
            'empathy' => 'EM',
            'relationship_management' => 'RM',
            default => null,
        };
    }

    private function normalizeModuleCode(string $moduleCode): string
    {
        $value = strtolower(trim($moduleCode));
        if ($value === '') {
            return ReportAccess::MODULE_EQ_CORE;
        }

        return match ($value) {
            'eq60.meta', 'eq60.summary', 'eq60.quadrants', 'eq60.core', 'eq_core', ReportAccess::MODULE_EQ_CORE => ReportAccess::MODULE_EQ_CORE,
            'eq60.insights', 'eq_cross_insights', ReportAccess::MODULE_EQ_CROSS_INSIGHTS => ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
            'eq60.action_plan', 'eq_growth_plan', ReportAccess::MODULE_EQ_GROWTH_PLAN => ReportAccess::MODULE_EQ_GROWTH_PLAN,
            'eq60.full', 'eq_full', ReportAccess::MODULE_EQ_FULL => ReportAccess::MODULE_EQ_FULL,
            default => $value,
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderTemplate(string $template, array $data): string
    {
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', static function (array $matches) use ($data): string {
            $path = (string) ($matches[1] ?? '');
            if ($path === '') {
                return '';
            }

            $value = data_get($data, $path);
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_scalar($value)) {
                return trim((string) $value);
            }

            return '';
        }, $template);
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function buildCompatBlocks(array $sections): array
    {
        $free = [];
        $paid = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $sectionKey = (string) ($section['key'] ?? '');
            $accessLevel = strtolower((string) ($section['access_level'] ?? 'free'));
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $payload = [
                    'section_key' => $sectionKey,
                    'id' => (string) ($block['id'] ?? ''),
                    'title' => (string) ($block['title'] ?? ''),
                    'content' => (string) ($block['content'] ?? ''),
                ];
                if ($accessLevel === 'paid') {
                    $paid[] = $payload;
                } else {
                    $free[] = $payload;
                }
            }
        }

        return [$free, $paid];
    }

    /**
     * @param  array<int,mixed>  $variants
     * @return list<string>
     */
    private function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $v = ReportAccess::normalizeVariant((string) $variant);
            $out[$v] = true;
        }

        return array_keys($out);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultSections(): array
    {
        return [
            [
                'key' => 'disclaimer_top',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'quality_notice',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 0,
                'max_blocks' => 2,
            ],
            [
                'key' => 'global_overview',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'self_awareness',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'emotion_regulation',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'empathy',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'relationship_management',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'cross_quadrant_insight',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'action_plan_14d',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_GROWTH_PLAN,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'methodology',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'disclaimer_bottom',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $layoutByKey
     */
    private function resolveSectionTitle(string $sectionKey, string $locale, array $layoutByKey = []): string
    {
        $layoutTitle = '';
        $layoutNode = $layoutByKey[$sectionKey] ?? null;
        if (is_array($layoutNode)) {
            $layoutTitle = trim((string) ($locale === 'zh-CN' ? ($layoutNode['title_zh'] ?? '') : ($layoutNode['title_en'] ?? '')));
            if ($layoutTitle === '') {
                $layoutTitle = trim((string) ($layoutNode['title_zh'] ?? $layoutNode['title_en'] ?? ''));
            }
        }

        if ($layoutTitle !== '') {
            return $layoutTitle;
        }

        $titlesZh = [
            'disclaimer_top' => '重要声明',
            'quality_notice' => '作答质量提示',
            'global_overview' => '综合概览',
            'self_awareness' => '自我情绪认知',
            'emotion_regulation' => '情绪调节与控制',
            'empathy' => '同理心与社会感知',
            'relationship_management' => '社交技能与人际管理',
            'cross_quadrant_insight' => '交叉洞察长文',
            'action_plan_14d' => '14 天游程',
            'methodology' => '方法说明',
            'disclaimer_bottom' => '结尾提示',
        ];
        $titlesEn = [
            'disclaimer_top' => 'Important Notice',
            'quality_notice' => 'Response Quality',
            'global_overview' => 'Overview',
            'self_awareness' => 'Self-Awareness',
            'emotion_regulation' => 'Emotion Regulation',
            'empathy' => 'Social Awareness & Empathy',
            'relationship_management' => 'Relationship Management',
            'cross_quadrant_insight' => 'Cross-Quadrant Insight',
            'action_plan_14d' => '14-Day Plan',
            'methodology' => 'Methodology',
            'disclaimer_bottom' => 'Closing Notes',
        ];

        $map = $locale === 'zh-CN' ? $titlesZh : $titlesEn;

        return (string) ($map[$sectionKey] ?? $sectionKey);
    }
}
