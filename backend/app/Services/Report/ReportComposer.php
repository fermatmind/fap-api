<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Log;

use App\Models\Attempt;
use App\Models\Result;

use App\Services\Report\TagBuilder;
use App\Services\Report\SectionCardGenerator;
use App\Services\Overrides\HighlightsOverridesApplier;
use App\Services\Report\IdentityLayerBuilder;
use App\Services\Report\HighlightBuilder;
use App\Services\Overrides\ReportOverridesApplier;

use App\Services\Content\ContentPackResolver;

use App\Domain\Score\AxisScore;

class ReportComposer
{
    public function __construct(
        private TagBuilder $tagBuilder,
        private SectionCardGenerator $cardGen,
        private HighlightsOverridesApplier $overridesApplier,
        private IdentityLayerBuilder $identityLayerBuilder,
        private ReportOverridesApplier $reportOverridesApplier
    ) {}

    public function compose(string $attemptId, array $ctx): array
    {
        // 1) Load Attempt + Result
        $attempt = Attempt::where('id', $attemptId)->first();
        $result  = Result::where('attempt_id', $attemptId)->first();

        if (!$attempt) {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'Attempt not found for given attempt_id',
                'status' => 404,
            ];
        }

        if (!$result) {
            return [
                'ok' => false,
                'error' => 'RESULT_NOT_FOUND',
                'message' => 'Result not found for given attempt_id',
                'status' => 404,
            ];
        }

        // =========================
        // 版本信息：从 Result/配置/Resolver 统一收敛
        // =========================
        $profileVersion = $result->profile_version
            ?? ($ctx['defaultProfileVersion'] ?? 'mbti32-v2.5');

        $scaleCode = (string)($result->scale_code ?? 'MBTI');
        $region    = (string)($attempt->region ?? 'CN_MAINLAND');
        $locale    = (string)($attempt->locale ?? 'zh-CN');

        $requested = $result->content_package_version
            ?? (isset($ctx['currentContentPackageVersion']) && is_callable($ctx['currentContentPackageVersion'])
                ? ($ctx['currentContentPackageVersion'])()
                : null);

        $requestedVersion = null;
        if (is_string($requested) && $requested !== '') {
            if (str_starts_with($requested, 'MBTI-CN-')) {
                $requestedVersion = substr($requested, strlen('MBTI-CN-')); // v0.2.1-TEST
            } elseif (substr_count($requested, '.') >= 3) {
                $parts = explode('.', $requested);
                $requestedVersion = implode('.', array_slice($parts, 3));   // v0.2.1-TEST
            } else {
                $requestedVersion = $requested;
            }
        }

        $resolver = ContentPackResolver::make();
        $chain    = $resolver->resolveWithFallbackChain($scaleCode, $region, $locale, $requestedVersion);
        $pack     = $chain[0];

        // ✅ 新体系：真正的版本号
        $contentPackageVersion = (string)$pack->version; // e.g. v0.2.1-TEST

        // ✅ 旧 loader 兼容：旧目录名（你已做 symlink）
        $contentPackageDir = 'MBTI-CN-' . $contentPackageVersion; // e.g. MBTI-CN-v0.2.1-TEST

        $typeCode = (string)($result->type_code ?? '');

        // =========================
        // 2) Score（复用 results.scores_pct/axis_states）
        // =========================
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $warnings = [];

        $scoresPct  = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        $axisStates = is_array($result->axis_states ?? null) ? $result->axis_states : [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresPct)) {
                $warnings[] = "scores_pct_missing:$d";
                $scoresPct[$d] = 50;
            }
            if (!array_key_exists($d, $axisStates)) {
                $warnings[] = "axis_states_missing:$d";
                $axisStates[$d] = 'moderate';
            }
        }

        // =========================
        // 3) Load Profile / IdentityCard（通过 ctx 注入 loader）
        // =========================
        $loadTypeProfile = $ctx['loadTypeProfile'] ?? null;
        $loadAssetItems  = $ctx['loadReportAssetItems'] ?? null;

        $profile = is_callable($loadTypeProfile)
            ? (array)$loadTypeProfile($contentPackageDir, $typeCode)
            : [];

        $identityItems = is_callable($loadAssetItems)
            ? (array)$loadAssetItems($contentPackageDir, 'report_identity_cards.json', 'type_code')
            : [];

        $identityCard = is_array($identityItems[$typeCode] ?? null) ? $identityItems[$typeCode] : null;

        // scores（稳定结构：pct/state/side/delta）
        $scores = $this->buildScoresValueObject($scoresPct, $dims);

        // =========================
        // 4) role/strategy（必须先算出来）
        // =========================
        $roleCard = is_callable($ctx['buildRoleCard'] ?? null)
            ? (array)($ctx['buildRoleCard'])($contentPackageDir, $typeCode)
            : [];

        $strategyCard = is_callable($ctx['buildStrategyCard'] ?? null)
            ? (array)($ctx['buildStrategyCard'])($contentPackageDir, $typeCode)
            : [];

        // =========================
        // 5) Build Tags（依赖 role/strategy）
        // =========================
        $tags = $this->tagBuilder->build($scores, [
            'role_card'     => $roleCard,
            'strategy_card' => $strategyCard,
        ]);

        // =========================
        // 6) Build Highlights（templates -> builder）
        // =========================
        $reportForHL = [
            'profile'     => ['type_code' => $typeCode],
            'scores_pct'  => $scoresPct,
            'axis_states' => $axisStates,
            'tags'        => $tags, // ✅ 关键：让 highlights 的 RuleEngine 有 userSet 可命中
        ];

        $hlTemplatesDoc = $this->loadHighlightsTemplatesDoc(
            $scaleCode,
            $region,
            $locale,
            $contentPackageVersion,
            $contentPackageDir,
            $ctx
        );

        $builder = new HighlightBuilder();
        $baseHighlights = $builder->buildFromTemplatesDoc($reportForHL, $hlTemplatesDoc, 3, 10);

        Log::info('[HL] base_highlights', [
            'count' => count($baseHighlights),
            'ids'   => array_slice(array_map(fn($x) => $x['id'] ?? null, $baseHighlights), 0, 10),
        ]);

        // =========================
        // 7) borderline_note（给 identity micro 用）
        // =========================
        $borderlineNote = is_callable($ctx['buildBorderlineNote'] ?? null)
            ? (array)($ctx['buildBorderlineNote'])($scoresPct, $contentPackageDir)
            : ['items' => []];

        if (!is_array($borderlineNote['items'] ?? null)) {
            $borderlineNote['items'] = [];
        }

        // =========================
        // 8) Build layers.identity
        // =========================
        $identityLayer = $this->identityLayerBuilder->build(
            $contentPackageDir,
            $typeCode,
            $scoresPct,
            $borderlineNote
        );

        // =========================
        // 9) Build Sections Cards
        // =========================
        $sections = [
            'traits' => [
                'cards' => $this->cardGen->generate('traits', $contentPackageDir, $tags, $scores),
            ],
            'career' => [
                'cards' => $this->cardGen->generate('career', $contentPackageDir, $tags, $scores),
            ],
            'growth' => [
                'cards' => $this->cardGen->generate('growth', $contentPackageDir, $tags, $scores),
            ],
            'relationships' => [
                'cards' => $this->cardGen->generate('relationships', $contentPackageDir, $tags, $scores),
            ],
        ];

        // =========================
        // 10) recommended_reads（放最后）
        // =========================
        $recommendedReads = is_callable($ctx['buildRecommendedReads'] ?? null)
            ? (array)($ctx['buildRecommendedReads'])($contentPackageDir, $typeCode, $scoresPct)
            : [];

        // =========================
        // 10.5) load unified overrides doc
        // =========================
        $overridesDoc = $this->loadReportOverridesDoc(
            $scaleCode,
            $region,
            $locale,
            $contentPackageVersion,
            $contentPackageDir,
            $ctx
        );

        // =========================
        // 11) ✅ Overrides 统一入口
        // =========================
        [$highlights, $sections, $recommendedReads] = $this->applyOverridesUnified(
            $contentPackageDir,
            $typeCode,
            $tags,
            $baseHighlights,
            $sections,
            $recommendedReads,
            $overridesDoc
        );

        Log::info('[HL] final_highlights', [
            'count' => count($highlights),
            'ids'   => array_slice(array_map(fn($x) => $x['id'] ?? null, $highlights), 0, 10),
        ]);

        // =========================
        // 最终 report payload（schema 不变）
        // =========================
        $reportPayload = [
            'versions' => [
                'engine'                  => 'v1.2',
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
                'content_pack_id'         => $pack->packId,
                'content_package_dir'     => $contentPackageDir,
            ],

            'scores'      => $scores,
            'scores_pct'  => $scoresPct,
            'axis_states' => $axisStates,

            'tags' => $tags,

            'profile' => [
                'type_code'     => $profile['type_code'] ?? $typeCode,
                'type_name'     => $profile['type_name'] ?? null,
                'tagline'       => $profile['tagline'] ?? null,
                'rarity'        => $profile['rarity'] ?? null,
                'keywords'      => $profile['keywords'] ?? [],
                'short_summary' => $profile['short_summary'] ?? null,
            ],

            'identity_card'   => $identityCard,
            'highlights'      => $highlights,
            'borderline_note' => $borderlineNote,

            'layers' => [
                'role_card'     => $roleCard,
                'strategy_card' => $strategyCard,
                'identity'      => $identityLayer,
            ],

            'sections' => $sections,

            'recommended_reads' => $recommendedReads,

            'warnings' => $warnings,
        ];

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'type_code'  => $typeCode,
            'report'     => $reportPayload,
        ];
    }

    /**
     * ✅ Overrides 统一入口
     * 返回：[$highlights, $sections, $recommendedReads]
     */
    private function applyOverridesUnified(
    string $contentPackageDir,
    string $typeCode,
    array $tags,
    array $baseHighlights,
    array $sections,
    array $recommendedReads,
    ?array $overridesDoc
): array {
    // 1) 先跑旧 highlights overrides（兼容旧逻辑）
    $highlights = $this->overridesApplier->apply($contentPackageDir, $typeCode, $baseHighlights);

    // 2) 用统一 overrides（把已加载的 doc 直接塞给 applier，避免路径/变量问题）
    $ovrCtx = [
        'report_overrides_doc' => $overridesDoc, // ✅ 关键：直接用你 loadReportOverridesDoc 读到的
        'overrides_debug' => true,
    ];

    // highlights
    $highlights = $this->reportOverridesApplier->applyHighlights(
        $contentPackageDir,
        $typeCode,
        $highlights,
        $ovrCtx
    );

    // cards（逐 section）
    foreach ($sections as $sectionKey => &$sec) {
        $cards = is_array($sec['cards'] ?? null) ? $sec['cards'] : [];
        $sec['cards'] = $this->reportOverridesApplier->applyCards(
            $contentPackageDir,
            $typeCode,
            (string)$sectionKey,
            $cards,
            $ovrCtx
        );
    }
    unset($sec);

    // reads
    $recommendedReads = $this->reportOverridesApplier->applyReads(
        $contentPackageDir,
        $typeCode,
        $recommendedReads,
        $ovrCtx
    );

    return [$highlights, $sections, $recommendedReads];
}

    /**
     * 读取 report_highlights_templates.json（新体系优先 + 旧体系兜底 + ctx loader 兜底）
     */
    private function loadHighlightsTemplatesDoc(
        string $scaleCode,
        string $region,
        string $locale,
        string $contentPackageVersion,
        string $contentPackageDir,
        array $ctx
    ): ?array {
        $tplNewRel = 'content_packages/' . $scaleCode . '/' . $region . '/' . $locale . '/' . $contentPackageVersion . '/report_highlights_templates.json';
        $tplOldRel = rtrim($contentPackageDir, '/') . '/report_highlights_templates.json';

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());

        $candidates = [
            base_path('../' . $tplNewRel),
            $repoRoot . '/' . ltrim($tplNewRel, '/'),
            $tplOldRel,
            base_path($tplOldRel),
            base_path('../' . $tplOldRel),
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) continue;
            $json = json_decode((string)file_get_contents($path), true);
            if (is_array($json)) {
                Log::info('[HL] templates_loaded', [
                    'path' => $path,
                    'schema' => $json['schema'] ?? null,
                    'rules_keys' => array_keys($json['rules'] ?? []),
                    'templates_keys' => array_keys($json['templates'] ?? []),
                ]);
                return $json;
            }
        }

        // fallback: ctx loader（可选保留）
        if (is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($contentPackageDir, 'report_highlights_templates.json');

            if (is_object($raw)) $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) return $doc;
            }
        }

        return null;
    }

    /**
     * 读取 report_overrides.json（新体系优先 + 旧体系兜底 + ctx loader 兜底）
     */
    private function loadReportOverridesDoc(
        string $scaleCode,
        string $region,
        string $locale,
        string $contentPackageVersion,
        string $contentPackageDir,
        array $ctx
    ): ?array {
        $ovrNewRel = 'content_packages/' . $scaleCode . '/' . $region . '/' . $locale . '/' . $contentPackageVersion . '/report_overrides.json';
        $ovrOldRel = rtrim($contentPackageDir, '/') . '/report_overrides.json';

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());

        $candidates = [
            base_path('../' . $ovrNewRel),
            $repoRoot . '/' . ltrim($ovrNewRel, '/'),
            $ovrOldRel,
            base_path($ovrOldRel),
            base_path('../' . $ovrOldRel),
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) continue;
            $json = json_decode((string)file_get_contents($path), true);
            if (is_array($json)) {
                Log::info('[OVR] overrides_loaded', [
                    'path' => $path,
                    'schema' => $json['schema'] ?? null,
                    'count' => is_array($json['overrides'] ?? null) ? count($json['overrides']) : 0,
                ]);
                return $json;
            }
        }

        // fallback: ctx loader（可选保留）
        if (is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($contentPackageDir, 'report_overrides.json');

            if (is_object($raw)) $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) return $doc;
            }
        }

        return null;
    }

    private function buildScoresValueObject(array $scoresPct, array $dims): array
    {
        $out = [];

        foreach ($dims as $dim) {
            $rawPct = (int)($scoresPct[$dim] ?? 50);

            [$p1, $p2] = match ($dim) {
                'EI' => ['E', 'I'],
                'SN' => ['S', 'N'],
                'TF' => ['T', 'F'],
                'JP' => ['J', 'P'],
                'AT' => ['A', 'T'],
                default => ['', ''],
            };

            $side = $rawPct >= 50 ? $p1 : $p2;
            $displayPct = $rawPct >= 50 ? $rawPct : (100 - $rawPct); // 50..100

            $axis = AxisScore::fromPctAndSide($displayPct, $side);

            $out[$dim] = [
                'pct'   => $axis->pct,
                'state' => $axis->state,
                'side'  => $axis->side,
                'delta' => $axis->delta,
            ];
        }

        return $out;
    }
}