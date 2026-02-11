<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Models\Attempt;
use App\Models\Result;

use App\Services\Report\TagBuilder;
use App\Services\Report\SectionCardGenerator;
use App\Services\Overrides\HighlightsOverridesApplier;
use App\Services\Report\IdentityLayerBuilder;
use App\Services\Report\HighlightBuilder;
use App\Services\Overrides\ReportOverridesApplier;

use App\Services\ContentPackResolver;
use App\Services\Content\ContentPack;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentStore;
use App\Services\RuleEngine\ReportRuleEngine;
use App\DTO\ResolvedPack;

use App\Domain\Score\AxisScore;

class ReportComposer
{
    public function __construct(
        private TagBuilder $tagBuilder,
        private SectionCardGenerator $cardGen,
        private HighlightsOverridesApplier $overridesApplier,
        private IdentityLayerBuilder $identityLayerBuilder,
        private ReportOverridesApplier $reportOverridesApplier,
        private ContentPackResolver $resolver, // ✅ 改为 DI：不再 ContentPackResolver::make()
        private ContentPacksIndex $packsIndex,
    ) {}

    public function compose(Attempt $attempt, array $ctx = [], ?Result $result = null): array
    {
        // 1) Use pre-authorized Attempt + org-scoped Result
        $attemptId = (string) $attempt->id;
        if ($result === null) {
            $result = Result::query()
                ->where('org_id', (int) $attempt->org_id)
                ->where('attempt_id', $attempt->id)
                ->firstOrFail();
        }

        // =========================
        // 版本信息：以 Attempt 记录为真源
        // =========================
        $profileVersion = $result->profile_version
            ?? ($ctx['defaultProfileVersion'] ?? 'mbti32-v2.5');

        $packId = (string) ($attempt->pack_id ?? $result->pack_id ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $result->dir_version ?? '');
        $contentPackageVersion = (string) ($attempt->content_package_version ?? $result->content_package_version ?? '');
        $scaleCode = (string) ($attempt->scale_code ?? $result->scale_code ?? '');
        $region = (string) ($attempt->region ?? '');
        $locale = (string) ($attempt->locale ?? '');

        if ($packId === '' || $dirVersion === '') {
            return [
                'ok' => false,
                'error' => 'REPORT_CONTEXT_MISSING',
                'message' => 'attempt pack context missing.',
                'status' => 500,
            ];
        }

        if ($packId !== '' && $dirVersion !== '') {
            $found = $this->packsIndex->find($packId, $dirVersion);
            if ($found['ok'] ?? false) {
                $item = $found['item'] ?? [];
                if ($contentPackageVersion === '') {
                    $contentPackageVersion = (string) ($item['content_package_version'] ?? '');
                }
                if ($scaleCode === '') {
                    $scaleCode = (string) ($item['scale_code'] ?? '');
                }
                if ($region === '') {
                    $region = (string) ($item['region'] ?? '');
                }
                if ($locale === '') {
                    $locale = (string) ($item['locale'] ?? '');
                }
            }
        }

        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($ctx['content_package_version'] ?? '');
        }
        if ($contentPackageVersion === ''
            && isset($ctx['currentContentPackageVersion'])
            && is_callable($ctx['currentContentPackageVersion'])
        ) {
            $contentPackageVersion = (string) ($ctx['currentContentPackageVersion'])();
        }
        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($this->normalizeRequestedVersion($dirVersion) ?? '');
        }
        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($this->normalizeRequestedVersion($packId) ?? '');
        }

        if ($scaleCode === '' && $packId !== '') {
            $scaleCode = (string) strtok($packId, '.');
        }
        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }
        if ($region === '') {
            $region = (string) config('content_packs.default_region', 'CN_MAINLAND');
        }
        if ($locale === '') {
            $locale = (string) config('content_packs.default_locale', 'zh-CN');
        }

        $rp = $this->resolver->resolve($scaleCode, $region, $locale, $contentPackageVersion, $dirVersion);

        // ✅ 适配：ResolvedPack -> ContentPack chain（让后续 loadJsonDocFromPackChain/overrides 等逻辑不动）
        $chain = $this->toContentPackChain($rp);

        /** @var ContentPack $pack */
        $pack = $chain[0];

        $contentPackageVersion = (string) $pack->version(); // e.g. v0.2.1-TEST
        $contentPackId = (string) $pack->packId();

        // ✅ 旧 loader 兼容：优先 Attempt.dir_version
        $contentPackageDir = $dirVersion !== '' ? $dirVersion : basename((string) ($rp->baseDir ?? ''));
        if ($dirVersion === '') {
            $dirVersion = $contentPackageDir;
        }

        $typeCode = (string)($result->type_code ?? '');

        $store = new ContentStore($chain, $ctx, $contentPackageDir);

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

        // scores（稳定结构：pct/state/side/delta）
        $scores = $this->buildScoresValueObject($scoresPct, $dims);

        // ✅ 统一 state 来源：axis_states 永远从 scores 派生，避免两套枚举打架
$axisStates = [];
foreach ($dims as $d) {
    $axisStates[$d] = (string)($scores[$d]['state'] ?? 'moderate');
}

        // =========================
        // 3) Load Profile / IdentityCard（保留 ctx 注入 loader 兜底）
        // =========================
        $profile = $this->loadTypeProfileFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);
$identityCard = $this->loadIdentityCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);

        // =========================
        // 4) role/strategy（必须先算出来）
        // =========================
        $roleCard = $this->loadRoleCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);
$strategyCard = $this->loadStrategyCardFromPackChain($chain, $typeCode, $ctx, $contentPackageDir);

        // =========================
        // 5) Build Tags（依赖 role/strategy）
        // =========================
        $tags = $this->tagBuilder->build($scores, [
            'type_code'     => $typeCode,
            'role_card'     => $roleCard,
            'strategy_card' => $strategyCard,
        ]);

        // =========================
// 5.1) explain payload：骨架 + collector（可开关）
// =========================
$wantExplainPayload = app()->environment('local') && (
    (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
);

$explainPayload = null;
$explainCollector = null;

if ($wantExplainPayload) {
    // 统一兜底结构：即使为空也必须有 key
    $empty = function (string $target, string $ctxName) use ($tags) {
        $ctxTags = is_array($tags) ? array_values(array_filter($tags, fn($x)=>is_string($x) && $x!=='')) : [];
        return [
            'target'       => $target,
            'ctx'          => $ctxName,
            'context_tags' => $ctxTags,
            'selected'     => [],
            'rejected'     => [],
        ];
    };

    $explainPayload = [
        'reads'      => $empty('reads', 'reads'),
        'highlights' => $empty('highlights', 'highlights'),
        'cards'      => [
            'traits'        => $empty('cards', 'cards:traits'),
            'career'        => $empty('cards', 'cards:career'),
            'growth'        => $empty('cards', 'cards:growth'),
            'relationships' => $empty('cards', 'cards:relationships'),
        ],
        // 先占位，后面 Step 11 拿到 $ovrExplain 再覆盖
        'overrides'  => $empty('overrides', 'overrides'),

        // ✅ NEW: assembler 占位（RuleEngine 后补齐的统计会写到这里）
        'assembler'  => [
            'cards' => [
                'traits'        => [],
                'career'        => [],
                'growth'        => [],
                'relationships' => [],
            ],
        ],
    ];

    // collector：RuleEngine::explain() 会把每个 ctx 的 explain 推到这里
    $explainCollector = function (string $ctx, array $payload) use (&$explainPayload) {
        if (!is_array($explainPayload)) return;

        // ctx 约定：
        // - reads / reads:xxx      -> _explain.reads
        // - highlights / highlights:xxx -> _explain.highlights
        // - cards:<section>        -> _explain.cards.<section>
        if ($ctx === 'reads' || str_starts_with($ctx, 'reads:')) {
            $explainPayload['reads'] = $payload;
            return;
        }

        if ($ctx === 'highlights' || str_starts_with($ctx, 'highlights:')) {
            $explainPayload['highlights'] = $payload;
            return;
        }

        if (str_starts_with($ctx, 'cards:')) {
            $sec = explode(':', $ctx, 2)[1] ?? '';
            if ($sec !== '' && isset($explainPayload['cards'][$sec])) {
                $explainPayload['cards'][$sec] = $payload;
            }
            return;
        }

        // 其他 ctx：先忽略（需要的话你也可以落到 overrides / 或新增 key）
    };
}

// ✅ 让 applyOverridesUnified() 内也能读到同一个 collector
if ($wantExplainPayload) {
    $GLOBALS['__re_explain_collector__'] = $explainCollector;
} else {
    unset($GLOBALS['__re_explain_collector__']);
}

        // =========================
        // 6) Build Highlights（templates -> builder）
        // =========================
        $reportForHL = [
            'profile'     => ['type_code' => $typeCode],
            'scores_pct'  => $scoresPct,
            'axis_states' => $axisStates,
            'tags'        => $tags,
        ];

        // ✅ 不再拼路径：从 pack.assets(highlights) 在 chain 里找 report_highlights_templates.json
        $hlTemplatesDoc = $store->loadHighlights();

        $builder = new HighlightBuilder();
$selectRules = $store->loadSelectRules();

/**
 * ✅ NEW: buildFromTemplatesDoc 返回：
 *   - ['items' => [...], 'meta' => [...]]  （推荐）
 * 兼容旧实现仍返回 list 的情况
 */
$hlBuild = $builder->buildFromTemplatesDoc($reportForHL, $hlTemplatesDoc, 3, 10, $selectRules);

$baseHighlights = [];
$hlMetaBase = [];

if (is_array($hlBuild) && array_key_exists('items', $hlBuild)) {
    $baseHighlights = is_array($hlBuild['items'] ?? null) ? $hlBuild['items'] : [];
    // ✅ 注意：HighlightBuilder 返回的是 _meta，不是 meta
    $hlMetaBase     = is_array($hlBuild['_meta'] ?? null) ? $hlBuild['_meta'] : [];
} else {
    // 兼容旧返回：直接是 list
    $baseHighlights = is_array($hlBuild) ? $hlBuild : [];
    $hlMetaBase = [
        'compat' => true,
        'note' => 'HighlightBuilder returned legacy list; consider returning items+_meta.',
    ];
}

Log::info('[HL] generated', [
    'stage'  => 'base_from_templates_doc',
    'schema' => $hlTemplatesDoc['schema'] ?? null,
    'count'  => is_array($baseHighlights) ? count($baseHighlights) : -1,
    'sample' => array_slice($baseHighlights ?? [], 0, 2),
]);

        Log::info('[HL] base_highlights', [
            'pack_id' => $contentPackId,
            'version' => $contentPackageVersion,
            'count' => count($baseHighlights),
            'ids'   => array_slice(array_map(fn($x) => $x['id'] ?? null, $baseHighlights), 0, 10),
        ]);

        // =========================
        // 7) borderline_note（给 identity micro 用）
        // =========================
        $borderlineNote = $this->loadBorderlineNoteFromPackChain(
    $chain,
    $scoresPct,
    $ctx,
    $contentPackageDir
);

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
// 8.8) ✅ preload unified overrides (for cards pipeline)
// - 目的：让 cards 生成后立刻 applyCards，保证日志顺序：base selected -> rule_applied
// - 同时把 resetExplain 提前，避免后面覆盖掉 cards 的 overrides explain
// =========================
$overridesDoc = $store->loadOverrides();
$overridesOrderBuckets = $store->overridesOrderBuckets();

// ✅ reset overrides explain（本次 report 一次性收集）
// NOTE：必须在任何 applyCards/applyHighlights/applyReads 之前调用
$this->reportOverridesApplier->resetExplain();

// ✅ build unified doc once
$unifiedOverridesDoc = $this->buildUnifiedOverridesDocForApplierFromPackChain($chain, $overridesDoc);

$ovrCaptureExplain = app()->environment('local') && (
    (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
);

$ovrCtx = [
    'report_overrides_doc' => $unifiedOverridesDoc,
    'overrides_debug'      => (bool) env('FAP_OVR_DEBUG', false),
    'tags'                 => $tags,

    // explain 透传给 overrides applier -> RuleEngine
    'capture_explain'      => (bool) $ovrCaptureExplain,
    'explain_collector'    => $ovrCaptureExplain ? ($GLOBALS['__re_explain_collector__'] ?? null) : null,
];

// =========================
// 9) Build Sections Cards（按 policy 决定要生成多少张）
// =========================

// 9.0) 读取 report_section_policies.json（从 pack chain）
$sectionPoliciesDoc = $this->loadSectionPoliciesDocFromPackChain($chain);

// 9.1) 根据 policy 计算该 section 的目标 cards 数（建议优先 target，至少满足 min，不超过 max）
$wantCards = function (string $secKey) use ($sectionPoliciesDoc): int {
    $defaults = [
        'min_cards'       => 4,
        'target'          => 5,
        'max'             => 7,
        'allow_fallback'  => true,
    ];

    $p = $this->pickSectionPolicy($sectionPoliciesDoc, $secKey, $defaults);

    $min    = (int)($p['min_cards'] ?? $p['min'] ?? $defaults['min_cards']);
    $target = (int)($p['target'] ?? $p['target_cards'] ?? $defaults['target']);
    $max    = (int)($p['max'] ?? $p['max_cards'] ?? $defaults['max']);

    // 防御性修正
    if ($min < 0) $min = 0;
    if ($target < 0) $target = 0;
    if ($max <= 0) $max = $defaults['max'];
    if ($max < $min) $max = $min;

    // wantN：生成阶段就尽量按 target 生成，但至少满足 min，且不超过 max
    $want = max($min, min($target, $max));
    return $want;
};

// 9.2) 给 cards 的 axisInfo 塞 explain 控制/collector（不影响 EI/SN/... 的结构）
$axisInfoForCards = $scores;
$axisInfoForCards['attempt_id'] = $attemptId;
$axisInfoForCards['capture_explain'] = (bool)$wantExplainPayload;
$axisInfoForCards['explain_collector'] = $explainCollector; // 可能为 null

// 9.3) 生成 sections（先 base selected，再 apply overrides，再写入 report.sections）
$sections = [];

foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {

    // 1) base selected
    $baseCards = $this->cardGen->generateFromStore(
        $sectionKey,
        $store,
        $tags,
        $axisInfoForCards,
        $contentPackageDir,
        $wantCards($sectionKey)
    );

    // ✅ 先打 base selected 日志（必须在 applyCards 之前）
    \Illuminate\Support\Facades\Log::info('[CARDS] selected (base)', [
        'section' => $sectionKey,
        'count'   => is_array($baseCards) ? count($baseCards) : -1,
        'ids'     => is_array($baseCards)
            ? array_slice(array_map(fn($x) => $x['id'] ?? null, $baseCards), 0, 12)
            : null,
    ]);

    // 2) overrides applied（applier 内部会打 [OVR] rule_applied）
    $finalCards = $this->reportOverridesApplier->applyCards(
        $contentPackageDir,
        $typeCode,
        (string)$sectionKey,
        is_array($baseCards) ? $baseCards : [],
        $ovrCtx
    );

    // 3) write into sections
    $sections[$sectionKey] = [
        'cards' => $finalCards,
    ];
}

// （可选但强烈建议）打个日志，确认 wantN 与实际 cards 数
\Illuminate\Support\Facades\Log::info('[CARDS] generated_by_policy', [
    'want' => [
        'traits' => $wantCards('traits'),
        'career' => $wantCards('career'),
        'growth' => $wantCards('growth'),
        'relationships' => $wantCards('relationships'),
    ],
    'got' => [
        'traits' => is_array($sections['traits']['cards'] ?? null) ? count($sections['traits']['cards']) : null,
        'career' => is_array($sections['career']['cards'] ?? null) ? count($sections['career']['cards']) : null,
        'growth' => is_array($sections['growth']['cards'] ?? null) ? count($sections['growth']['cards']) : null,
        'relationships' => is_array($sections['relationships']['cards'] ?? null) ? count($sections['relationships']['cards']) : null,
    ],
]);

        // =========================
        // 10) recommended_reads（content_graph）
        // =========================
        $contentGraphEnabled = (bool) env('CONTENT_GRAPH_ENABLED', false);
        $includeRecommendedReads = false;
        $recommendedReads = [];

        if ($contentGraphEnabled) {
            [$recommendedReads, $includeRecommendedReads] = $this->buildRecommendedReadsFromContentGraph(
                $chain,
                $scaleCode,
                $region,
                $locale,
                $typeCode,
                $scores,
                $axisStates
            );
        }

// =========================
// 10.5) overrides 已在 Step 8.8 preload（给 cards pipeline 用）
// =========================

        // =========================
        // 11) ✅ Overrides 统一入口
        // =========================
        [$highlights, $sections, $recommendedReads, $ovrExplain] = $this->applyOverridesUnified(
    $chain,
    $contentPackageDir,
    $typeCode,
    $tags,
    $baseHighlights,
    $sections,
    $recommendedReads,
    $overridesDoc,
    $overridesOrderBuckets,
    false
);

// =========================
// 12) ✅ RuleEngine（只做筛选/排序）
// 顺序：base -> overrides -> RuleEngine -> fallback
// =========================
$rulesDoc = $this->loadReportRulesDocFromPackChain($chain);

// 如果你暂时还没独立 RuleEngine 类，这里先用一个服务（见我下面 B 部分：新增 ReportRuleEngine）
$reCtxBase = [
    'type_code' => $typeCode,
    'content_package_dir' => $contentPackageDir,
    'tags' => $tags,
    'capture_explain' => (bool)$wantExplainPayload,
    'explain_collector' => $explainCollector,
];

// highlights
$highlights = app(\App\Services\RuleEngine\ReportRuleEngine::class)
    ->apply('highlights', $highlights, $rulesDoc, $reCtxBase, 'highlights');

// cards（逐 section）
foreach ($sections as $sectionKey => &$sec) {
    $cards = is_array($sec['cards'] ?? null) ? $sec['cards'] : [];
    $sec['cards'] = app(\App\Services\RuleEngine\ReportRuleEngine::class)
        ->apply('cards', $cards, $rulesDoc, array_merge($reCtxBase, ['section_key' => (string)$sectionKey]), "cards:{$sectionKey}");
}
unset($sec);

// reads（content_graph 走固定顺序，不再过 RuleEngine）
if (!$includeRecommendedReads) {
    $recommendedReads = [];
}

// =========================
// ✅ NEW: highlights finalize AFTER overrides + RuleEngine
// - 永远保证产物约束成立（max2 strength / 总数 3~4 / 不足补齐）
// - meta 写入 _meta.highlights.finalize_meta
// =========================
try {
    /**
     * ✅ HighlightBuilder::finalize 签名：
     * finalize(array $highlights, array $report, ContentStore $store, int $min=3, int $max=4)
     * 返回：['items'=>..., '_meta'=>...]
     */

    // ✅ 给 finalize 的 report 传“它需要的结构”
    // - profile.type_code / scores_pct / axis_states 必须有
    // - tags/capture_explain/explain_collector 可带上（不影响 finalize 但利于 debug）
    $hlReportForFinalize = $reportForHL;
    $hlReportForFinalize['tags'] = $tags;
    $hlReportForFinalize['capture_explain'] = (bool)$wantExplainPayload;
    $hlReportForFinalize['explain_collector'] = $explainCollector;

    $hlFinal = $builder->finalize(
        $highlights,            // 1) items list（可能为空）
        $hlReportForFinalize,   // 2) report ctx
        $store,                 // 3) ContentStore（✅ 必传）
        3,                      // 4) min
        4                       // 5) max
    );

    if (is_array($hlFinal) && array_key_exists('items', $hlFinal)) {
        $highlights  = is_array($hlFinal['items'] ?? null) ? $hlFinal['items'] : $highlights;
        // ✅ 注意：HighlightBuilder 返回的是 _meta
        $hlMetaFinal = is_array($hlFinal['_meta'] ?? null) ? $hlFinal['_meta'] : [];
    } else {
        // 兼容：如果有人把 finalize 写成直接返回 list
        $highlights = is_array($hlFinal) ? $hlFinal : $highlights;
        $hlMetaFinal = [
            'compat' => true,
            'note' => 'HighlightBuilder::finalize returned legacy list; consider returning items+_meta.',
        ];
    }

} catch (\Throwable $e) {
    Log::warning('[HL] finalize_failed', ['error' => $e->getMessage()]);
    $hlMetaFinal = [
        'error' => $e->getMessage(),
    ];
}

// =========================
// ✅ 12.4) DEBUG: 强制制造缺卡（为了运行时验收 4/5/7）
// - 例：FAP_DEBUG_FORCE_SHORT_SECTION=relationships
// - 例：FAP_DEBUG_FORCE_SHORT_SECTION=relationships:1   （只保留 1 张）
// =========================
$force = (string) env('FAP_DEBUG_FORCE_SHORT_SECTION', '');
$force = trim($force);

if ($force !== '') {
    $secName = $force;
    $keepN = 1;

    // 支持 "relationships:1" / "relationships:0"
    if (str_contains($force, ':')) {
        [$secName, $n] = explode(':', $force, 2);
        $secName = trim((string)$secName);
        $n = trim((string)$n);
        if (is_numeric($n)) $keepN = max(0, (int)$n);
    } else {
        $secName = trim($secName);
    }

    if ($secName !== '' && isset($sections[$secName]) && is_array($sections[$secName])) {
        $cards0 = is_array($sections[$secName]['cards'] ?? null) ? $sections[$secName]['cards'] : [];
        $before = count($cards0);
        $sections[$secName]['cards'] = array_slice($cards0, 0, $keepN);
        $after = count($sections[$secName]['cards']);

        \Illuminate\Support\Facades\Log::info('[DBG] force_short_section', [
            'section' => $secName,
            'keep' => $keepN,
            'before' => $before,
            'after' => $after,
        ]);
    }
}

// =========================
// 12.5) ✅ SectionAssembler（RuleEngine 跑完后补齐 cards）
// - 依据 report_section_policies.json 的 min/target/max
// - 统计写到 report['_meta']['sections'][sec]['assembler']（Assembler 若有输出）
// - 可选：capture_explain=true 时写到 report['_explain']['assembler']['cards']
// =========================

// ✅ NEW: 用临时变量承接“全局 assembler 状态”，避免此时访问未定义的 $reportPayload
$assemblerGlobalMeta = null;

// 用一个临时 report 承载 sections + meta（最终再合回 reportPayload）
$tmpReport = [
    'sections' => $sections,
];

// ✅ 关键：传 ContentStore，让 Assembler 真正走 loader
$tmpReport = app(\App\Services\Report\SectionAssembler::class)
    ->apply($tmpReport, $store, [
        // explain 只是可选；meta 不能依赖这个开关
        'capture_explain' => (bool)$wantExplainPayload,
    ]);

// 回写 sections（Assembler 可能补齐了 cards）
$sections = (is_array($tmpReport['sections'] ?? null)) ? $tmpReport['sections'] : $sections;

// --------- 12.5.a 取出 assembler meta（如果它有写）---------
$rawMetaSections = (is_array($tmpReport['_meta']['sections'] ?? null))
    ? $tmpReport['_meta']['sections']
    : [];

// DEBUG：确认 assembler 是否真的写了 meta
Log::info('[ASM] after_apply', [
    'tmp_meta_keys' => is_array($tmpReport['_meta'] ?? null) ? array_keys($tmpReport['_meta']) : null,
    'sections_meta_type' => gettype($tmpReport['_meta']['sections'] ?? null),
    'sections_meta_count' => is_array($tmpReport['_meta']['sections'] ?? null) ? count($tmpReport['_meta']['sections']) : null,
]);

// --------- 12.5.b 规范化 meta 形状：list->map / map->map---------
$assemblerMetaSections = $this->normalizeAssemblerMetaSections($rawMetaSections);

// --------- 12.5.c 如果 assembler 没写 → 只做“诊断兜底 meta”，不要伪装成功---------
if (empty($assemblerMetaSections)) {
    $assemblerMetaSections = $this->buildFallbackAssemblerMetaSections(
        $sections,
        $chain,
        $store,
        $contentPackageDir
    );

    // ✅ 只写到临时变量：此时 $reportPayload 还没创建，不能引用它
    $assemblerGlobalMeta = [
        'ok' => false,
        'reason' => 'assembler_did_not_emit_meta',
        'meta_fallback_used' => true,
    ];

    Log::error('[ASM] meta_missing_diagnostic_fallback_used', [
        'keys' => array_keys($assemblerMetaSections),
    ]);
} else {
    // ✅ assembler 有写 meta：也给一个全局 ok，方便前端/验收侧读
    $assemblerGlobalMeta = [
        'ok' => true,
        'meta_fallback_used' => false,
    ];
}

// --------- 12.5.d explain：把每个 section 的 assembler meta 写进 explainPayload（可开关）---------
if ($wantExplainPayload && is_array($explainPayload)) {
    foreach ($assemblerMetaSections as $secKey => $node) {
        $secKey = (string)$secKey;
        $explainPayload['assembler']['cards'][$secKey] = is_array($node)
            ? ($node['assembler'] ?? $node)
            : [];
    }
}

// ✅ IMPORTANT：把这俩变量留着，后面“构造完 $reportPayload”后再合进去：
// - $assemblerMetaSections  -> $reportPayload['_meta']['sections']
// - $assemblerGlobalMeta   -> $reportPayload['_meta']['section_assembler']

        // =========================
        // 最终 report payload（schema 不变）
        // =========================
        // $contentPackageDir 目前是 Attempt.dir_version（legacy loader 对齐）
$legacyContentPackageDir = $contentPackageDir;

// ✅ 逻辑目录：从 pack_id 推导成 scale/region/locale/version
$realContentPackageDir = $this->packIdToDir($contentPackId);
        
        $reportPayload = [
            'versions' => [
    'engine'                  => 'v1.2',
    'profile_version'         => $profileVersion,
    'content_package_version' => $contentPackageVersion,
    'content_pack_id'         => $contentPackId,
    'dir_version'             => $legacyContentPackageDir,

    // ✅ 对外返回真实目录（新体系）
    'content_package_dir'     => $realContentPackageDir,

    // ✅ 保留旧体系目录，便于兼容/排查
    'legacy_dir'              => $legacyContentPackageDir,
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

            'warnings' => $warnings,

    ]; // $reportPayload end

if ($includeRecommendedReads) {
    $reportPayload['recommended_reads'] = $recommendedReads;
}

// Optional norms payload (feature-flagged)
$normsPayload = $this->buildNormsPayload($contentPackId, $scoresPct);
if (is_array($normsPayload)) {
    $reportPayload['norms'] = $normsPayload;
}

// =====================================================
// ✅ _meta：保证 validate-report 需要的 sections.assembler 存在
// 同时保留 highlights 的 base/finalize/explain meta
// =====================================================

// 0) _meta 必须是 array（防止被意外写成 null/string）
$reportPayload['_meta'] = is_array($reportPayload['_meta'] ?? null) ? $reportPayload['_meta'] : [];
if (is_array($assemblerGlobalMeta)) {
    $reportPayload['_meta']['section_assembler'] = $assemblerGlobalMeta;
}

// -----------------------------------------------------
// 1) highlights meta（长期可保留）
// -----------------------------------------------------
$reportPayload['_meta']['highlights'] = is_array($reportPayload['_meta']['highlights'] ?? null)
    ? $reportPayload['_meta']['highlights']
    : [];

$reportPayload['_meta']['highlights']['base_meta'] = $hlMetaBase ?? [];
$reportPayload['_meta']['highlights']['finalize_meta'] = $hlMetaFinal ?? [];

// explain：调试开关打开时写真实 explain；否则保持稳定字段（null 占位）
if ($wantExplainPayload && is_array($explainPayload)) {
    $reportPayload['_meta']['highlights']['explain'] = $explainPayload['highlights'] ?? null;
} else {
    $reportPayload['_meta']['highlights']['explain'] = $reportPayload['_meta']['highlights']['explain'] ?? null;
}

// -----------------------------------------------------
// 2) sections assembler meta（给 fap:validate-report 用）
// 目标形态：_meta.sections.<sec>.assembler.policy.min_cards + counts.final
// -----------------------------------------------------
if (!empty($assemblerMetaSections) && is_array($assemblerMetaSections)) {
    // ✅ 深合并，避免覆盖掉已有的 _meta 其它字段
    $reportPayload['_meta']['sections'] = array_replace_recursive(
        is_array($reportPayload['_meta']['sections'] ?? null) ? $reportPayload['_meta']['sections'] : [],
        $assemblerMetaSections
    );

// ✅ 正常路径：Assembler 已经写了 sections meta，则全局也要标记 ok
$reportPayload['_meta']['section_assembler'] = is_array($reportPayload['_meta']['section_assembler'] ?? null)
    ? $reportPayload['_meta']['section_assembler']
    : [];

$reportPayload['_meta']['section_assembler'] = array_merge(
    [
        'ok' => true,
        'meta_fallback_used' => false,
    ],
    $reportPayload['_meta']['section_assembler']
);

} else {
    // 兜底：至少让 _meta.sections 是 array（字段稳定）
    $reportPayload['_meta']['sections'] = is_array($reportPayload['_meta']['sections'] ?? null)
        ? $reportPayload['_meta']['sections']
        : [];
}

Log::info('[ASM] final_meta_sections', [
    'type' => gettype($reportPayload['_meta']['sections'] ?? null),
    'keys' => is_array($reportPayload['_meta']['sections'] ?? null) ? array_keys($reportPayload['_meta']['sections']) : null,
    'traits_policy' => data_get($reportPayload, '_meta.sections.traits.assembler.policy'),
    'traits_counts' => data_get($reportPayload, '_meta.sections.traits.assembler.counts'),
]);

// （可选但强烈建议）打个日志，下一步定位 assemblerMetaSections 是否为空
Log::info('[ASM] meta_sections_merged', [
    'sections_keys' => array_keys($reportPayload['_meta']['sections'] ?? []),
    'traits_node_keys' => is_array($reportPayload['_meta']['sections']['traits'] ?? null)
        ? array_keys($reportPayload['_meta']['sections']['traits'])
        : null,
]);

// =========================
// ✅ NEW: _meta.highlights（轻量可长期保存）
// - base_meta：来自 HighlightBuilder::buildFromTemplatesDoc
// - explain：优先写 highlights explain（即使 _explain 关闭，也至少保留 base_meta）
// =========================
$reportPayload['_meta'] = $reportPayload['_meta'] ?? [];
$reportPayload['_meta']['highlights'] = $reportPayload['_meta']['highlights'] ?? [];

$reportPayload['_meta']['highlights']['base_meta'] = $hlMetaBase ?? [];
$reportPayload['_meta']['highlights']['finalize_meta'] = $hlMetaFinal ?? [];

// 强烈建议：把 highlights explain 抄到 _meta（调试开关打开时更完整）
if ($wantExplainPayload && is_array($explainPayload)) {
    $reportPayload['_meta']['highlights']['explain'] = $explainPayload['highlights'] ?? null;
} else {
    // 非调试环境：也保留一个轻量占位，避免前端/验收侧“字段忽有忽无”
    $reportPayload['_meta']['highlights']['explain'] = $reportPayload['_meta']['highlights']['explain'] ?? null;
}

// =========================
// 3.3) _explain payload（可开关）
// - 结构一定齐全：reads/highlights/cards/overrides 都有 key
// - cards/highlights/reads 的 explain 可能来自两路：
//   A) RuleEngine collector（cards generator 等）
//   B) ReportOverridesApplier->getExplain()（overrides pipeline）
// - 这里要把 B) 的 reads/highlights/cards 也合并进来（否则 highlights 永远是空骨架）
// =========================
if ($wantExplainPayload) {

    // 把 overrides applier 的 explain 统一抽成一个 “root”
    $ovrRoot = null;
    if (is_array($ovrExplain ?? null)) {
        $ovrRoot = is_array($ovrExplain['_explain'] ?? null) ? $ovrExplain['_explain'] : $ovrExplain;
    }

    Log::info('[DBG] explain_types', [
        'ovrExplain_type' => gettype($ovrExplain ?? null),
        'ovrRoot_type' => gettype($ovrRoot),
        'ovrRoot_highlights_type' => (is_array($ovrRoot) && array_key_exists('highlights', $ovrRoot))
            ? gettype($ovrRoot['highlights'])
            : null,
        'ovrRoot_reads_type' => (is_array($ovrRoot) && array_key_exists('reads', $ovrRoot))
            ? gettype($ovrRoot['reads'])
            : null,
    ]);

        // 小工具：array/object 都统一转成 array（避免 stdClass 导致 is_array 判定失败）
    $toArr = function ($x): ?array {
        if (is_array($x)) return $x;
        if (is_object($x)) {
            $a = json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true);
            return is_array($a) ? $a : null;
        }
        return null;
    };

    // 小工具：从多种结构里取出某个 ctx 的 explain payload
    // ✅ 注意：这里不再要求入参必须是 ?array，而是 mixed
    $pickCtx = function ($root, string $ctx) use ($toArr) : ?array {
        $root = $toArr($root);
        if (!is_array($root)) return null;

        // 1) 直接按 target key：highlights / reads
        if ($ctx === 'highlights') return $toArr($root['highlights'] ?? null);
        if ($ctx === 'reads')      return $toArr($root['reads'] ?? null);

        // 2) by_ctx / contexts：例如 "highlights:overrides"
        $by = $toArr($root['by_ctx'] ?? null);
        if (is_array($by)) {
            $hit = $toArr($by[$ctx] ?? null);
            if ($hit) return $hit;
        }

        $cs = $toArr($root['contexts'] ?? null);
        if (is_array($cs)) {
            $hit = $toArr($cs[$ctx] ?? null);
            if ($hit) return $hit;
        }

        // 3) 嵌套一层：root['overrides'] 里也可能有
        $o = $toArr($root['overrides'] ?? null);
        if (is_array($o)) {
            if ($ctx === 'highlights') {
                $hit = $toArr($o['highlights'] ?? null);
                if ($hit) return $hit;
            }
            if ($ctx === 'reads') {
                $hit = $toArr($o['reads'] ?? null);
                if ($hit) return $hit;
            }

            $by2 = $toArr($o['by_ctx'] ?? null);
            if (is_array($by2)) {
                $hit = $toArr($by2[$ctx] ?? null);
                if ($hit) return $hit;
            }

            $cs2 = $toArr($o['contexts'] ?? null);
            if (is_array($cs2)) {
                $hit = $toArr($cs2[$ctx] ?? null);
                if ($hit) return $hit;
            }
        }

        return null;
    };

    // (a) overrides 全量保留（统一转 array，避免内部混有 object/stdClass）
$ovrRootArr = $toArr($ovrRoot);

if (is_array($explainPayload) && is_array($ovrRootArr)) {
    $explainPayload['overrides'] = $ovrRootArr;
}

// ✅ 下面开始：直接从 explainPayload['overrides'] 里“抄”到顶层，别再猜 $ovrRoot 的结构
$ovrSaved = $toArr($explainPayload['overrides'] ?? null) ?? [];

$explainPayload['overrides'] = $ovrRootArr;

    // 最终写入 reportPayload
    $reportPayload['_explain'] = $explainPayload;
}

        $this->persistReportJson($attemptId, $reportPayload);


        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'type_code'  => $typeCode,
            'report'     => $reportPayload,
        ];
    }

    private function buildNormsPayload(string $packId, array $scoresPct): ?array
    {
        if (!$this->isNormsEnabled()) {
            return null;
        }
        if (!Schema::hasTable('norms_versions') || !Schema::hasTable('norms_table')) {
            return null;
        }

        $version = $this->resolveNormsVersion($packId);
        if (!$version) {
            return null;
        }

        $metrics = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $metricsPayload = [];

        foreach ($metrics as $metric) {
            if (!array_key_exists($metric, $scoresPct)) {
                return null;
            }
            $score = $scoresPct[$metric];
            if (!is_numeric($score)) {
                return null;
            }

            $scoreInt = (int) round((float) $score);
            $row = DB::table('norms_table')
                ->where('norms_version_id', (string) $version->id)
                ->where('metric_key', $metric)
                ->where('score_int', $scoreInt)
                ->first();

            if (!$row) {
                return null;
            }

            $percentile = (float) ($row->percentile ?? 0.0);
            $metricsPayload[$metric] = [
                'score_int' => $scoreInt,
                'percentile' => $percentile,
                'over_percent' => (int) floor($percentile * 100),
            ];
        }

        return [
            'pack_id' => $packId,
            'version_id' => (string) ($version->id ?? ''),
            'N' => (int) ($version->sample_n ?? 0),
            'window_start_at' => (string) ($version->window_start_at ?? ''),
            'window_end_at' => (string) ($version->window_end_at ?? ''),
            'rank_rule' => (string) ($version->rank_rule ?? 'leq'),
            'metrics' => $metricsPayload,
        ];
    }

    private function isNormsEnabled(): bool
    {
        return (int) env('NORMS_ENABLED', 0) === 1;
    }

    private function resolveNormsVersion(string $packId): ?object
    {
        $pin = trim((string) env('NORMS_VERSION_PIN', ''));
        $query = DB::table('norms_versions')->where('pack_id', $packId);

        if ($pin !== '') {
            return $query->where('id', $pin)->first();
        }

        return $query
            ->where('status', 'active')
            ->orderByDesc('computed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function normalizeRequestedVersion($requested): ?string
    {
        if (!is_string($requested) || $requested === '') return null;

        // pack_id like MBTI.cn-mainland.zh-CN.v0.2.1-TEST
        if (substr_count($requested, '.') >= 3) {
            $parts = explode('.', $requested);
            return implode('.', array_slice($parts, 3));   // v0.2.1-TEST
        }

        // dir_version like IQ-RAVEN-CN-v0.3.0-DEMO
        $pos = strripos($requested, '-v');
        if ($pos !== false) {
            return substr($requested, $pos + 1);   // v0.3.0-DEMO
        }

        return $requested;
    }

/**
 * ✅ Adapter：把 App\Services\ContentPackResolver::resolve() 返回的 ResolvedPack
 * 转成你现有 ReportComposer 期望的 ContentPack chain（primary + manifest.fallback 链）
 */
private function toContentPackChain(ResolvedPack $rp): array
{
    $make = function (array $manifest, string $baseDir): ContentPack {
        return new ContentPack(
            packId:  (string)($manifest['pack_id'] ?? ''),
            scaleCode: (string)($manifest['scale_code'] ?? ''),
            region: (string)($manifest['region'] ?? ''),
            locale: (string)($manifest['locale'] ?? ''),
            version: (string)($manifest['content_package_version'] ?? ''),
            basePath: $baseDir,
            manifest: $manifest,
        );
    };

    $out = [];
    // primary
    $out[] = $make($rp->manifest ?? [], (string)($rp->baseDir ?? ''));

    // fallback chain：ContentPackResolver.php 里 buildFallbackChain() 的结构
    $fbs = $rp->fallbackChain ?? [];
    if (is_array($fbs)) {
        foreach ($fbs as $fb) {
            if (!is_array($fb)) continue;
            $m = is_array($fb['manifest'] ?? null) ? $fb['manifest'] : [];
            $d = (string)($fb['base_dir'] ?? '');
            if ($m && $d !== '') $out[] = $make($m, $d);
        }
    }

    return $out;
}

    /**
     * ✅ 从 pack chain 里按 manifest.assets 声明的文件列表加载指定 basename 的 JSON
     * - assetKey: highlights / overrides / cards / reads / identity ...
     * - wantedBasename: 例如 report_highlights_templates.json / report_overrides.json
     * - 找不到：fallback 到 ctx['loadReportAssetJson']（保持你旧逻辑可用）
     */
    private function loadJsonDocFromPackChain(
        array $chain,
        string $assetKey,
        string $wantedBasename,
        array $ctx,
        string $legacyContentPackageDir
    ): ?array {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) continue;

            $paths = $this->flattenAssetPaths($p->assets()[$assetKey] ?? null);

            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;
                if (basename($rel) !== $wantedBasename) continue;

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) continue;

                $json = json_decode((string)file_get_contents($abs), true);
                if (is_array($json)) {
                    Log::info('[PACK] json_loaded', [
                        'asset' => $assetKey,
                        'file'  => $wantedBasename,
                        'pack_id' => $p->packId(),
                        'version' => $p->version(),
                        'path' => $abs,
                        'schema' => $json['schema'] ?? null,
                    ]);
                    return $json;
                }
            }
        }

        // fallback: ctx loader（旧兼容）
        if (is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($legacyContentPackageDir, $wantedBasename);

            if (is_object($raw)) $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) return $doc;
            }
        }

        Log::warning('[PACK] json_not_found', [
            'asset' => $assetKey,
            'file' => $wantedBasename,
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return null;
    }

private function loadReportRulesDocFromPackChain(array $chain): ?array
{
    foreach ($chain as $p) {
        if (!$p instanceof \App\Services\Content\ContentPack) continue;

        $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_rules.json';
        if (!is_file($abs)) continue;

        $j = json_decode((string)file_get_contents($abs), true);
        if (!is_array($j)) continue;

        // 给 doc 打来源
        $j['__src'] = [
            'pack_id' => $p->packId(),
            'version' => $p->version(),
            'file'    => 'report_rules.json',
            'rel'     => 'report_rules.json',
            'path'    => $abs,
        ];

        return $j;
    }
    return null;
}

    /**
     * 把 manifest.assets[xxx] 可能出现的几种结构统一 flatten 成 “相对路径 list”
     * - 常见：["a.json","b.json"]
     * - overrides：{"order":[...], "unified":[...], "highlights_legacy":[...]}
     */
    private function flattenAssetPaths($assetVal): array
    {
        if (!is_array($assetVal)) return [];

        // list
        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn($x) => is_string($x) && trim($x) !== ''));
        }

        // map (e.g. overrides)
        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') continue;
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') $out[] = $x;
            }
        }
        return array_values(array_unique($out));
    }

/**
 * Normalize SectionAssembler output:
 * - validator expects: _meta.sections.<sec> = ['assembler' => ...]
 * - but assembler might output:
 *   A) map-shape: ['traits'=>['assembler'=>...], ...]
 *   B) list-shape: [ ['section'=>'traits','assembler'=>...], ... ]
 *   C) empty list: []
 */
private function normalizeAssemblerMetaSections($sectionsMeta): array
{
    if (!is_array($sectionsMeta) || $sectionsMeta === []) return [];

    $isList = array_keys($sectionsMeta) === range(0, count($sectionsMeta) - 1);

    // map-shape
    if (!$isList) {
        $out = [];
        foreach ($sectionsMeta as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            if (is_array($v)) $out[$k] = $v;
        }
        return $out;
    }

    // list-shape -> map-shape
    $out = [];
    foreach ($sectionsMeta as $node) {
        if (!is_array($node)) continue;

        $sec =
            (string)($node['section'] ?? '') ?:
            (string)($node['section_key'] ?? '') ?:
            (string)($node['key'] ?? '') ?:
            (string)($node['name'] ?? '');

        $sec = trim($sec);
        if ($sec === '') continue;

        if (isset($node['assembler']) && is_array($node['assembler'])) {
            $out[$sec] = $node;
        } else {
            $out[$sec] = ['assembler' => $node];
        }
    }

    return $out;
}

/**
 * When SectionAssembler didn't emit _meta.sections (your current situation),
 * composer builds minimal assembler meta so validate-report can pass.
 *
 * Output shape:
 *   [
 *     'traits' => ['assembler' => ['policy'=>..., 'counts'=>...]],
 *     ...
 *   ]
 */
private function buildFallbackAssemblerMetaSections(
    array $sections,
    array $chain,
    \App\Services\Content\ContentStore $store,
    string $legacyContentPackageDir
): array {
    $policyDoc = $this->loadSectionPoliciesDocFromPackChain($chain);

    $defaults = [
        'min_cards' => 4,
        'target'    => 5,
        'max'       => 7,
        'allow_fallback' => true,
    ];

    $out = [];

    foreach ($sections as $secKey => $secNode) {
        $secKey = (string)$secKey;
        $cards = is_array($secNode['cards'] ?? null) ? $secNode['cards'] : [];
        $final = count($cards);

        $policy = $this->pickSectionPolicy($policyDoc, $secKey, $defaults);

        // ✅ 同时给出 min 和 min_cards，避免 validator 取错 key
        if (!isset($policy['min']) && isset($policy['min_cards'])) {
            $policy['min'] = $policy['min_cards'];
        }
        if (!isset($policy['min_cards']) && isset($policy['min'])) {
            $policy['min_cards'] = $policy['min'];
        }

                $want = max((int)($policy['min_cards'] ?? 0), (int)($policy['target'] ?? 0));
        $max  = (int)($policy['max'] ?? 0);
        if ($max > 0) $want = min($want, $max);

        $out[$secKey] = [
            'assembler' => [
                // ✅ 关键：不要伪装成功
                'ok' => false,
                'reason' => 'composer_built_meta_fallback_because_assembler_meta_missing',
                'meta_fallback' => true,

                'policy' => array_merge($policy, [
                    'want_cards' => $want,
                ]),
                'counts' => [
                    'final' => $final,
                    'final_count' => $final,
                    'want' => $want,
                    'shortfall' => max(0, $want - $final),
                ],
            ],
        ];
    }

    return $out;
}

/**
 * Try read report_section_policies.json from pack base dir.
 * (We don't rely on ContentStore having a specific loader method.)
 */
private function loadSectionPoliciesDocFromPackChain(array $chain): ?array
{
    foreach ($chain as $p) {
        if (!$p instanceof \App\Services\Content\ContentPack) continue;

        $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_section_policies.json';
        if (!is_file($abs)) continue;

        $j = json_decode((string)file_get_contents($abs), true);
        if (!is_array($j)) continue;

        $j['__src'] = [
            'pack_id' => $p->packId(),
            'version' => $p->version(),
            'file'    => 'report_section_policies.json',
            'rel'     => 'report_section_policies.json',
            'path'    => $abs,
        ];
        return $j;
    }
    return null;
}

/**
 * Pick policy for a section from doc with tolerant schema.
 * Accept common shapes:
 * - {items:{traits:{...}}}
 * - {policies:{traits:{...}}}
 * - {sections:{traits:{...}}}
 * - {traits:{...}}
 */
private function pickSectionPolicy(?array $doc, string $secKey, array $defaults): array
{
    if (!is_array($doc)) return $defaults;

    $candidates = [
        $doc['items'][$secKey]     ?? null,
        $doc['policies'][$secKey]  ?? null,
        $doc['sections'][$secKey]  ?? null,
        $doc[$secKey]              ?? null,
    ];

    foreach ($candidates as $c) {
        if (is_array($c)) {
            // normalize common keys
            $min = $c['min_cards'] ?? $c['min'] ?? null;
            $target = $c['target'] ?? $c['target_cards'] ?? null;
            $max = $c['max'] ?? $c['max_cards'] ?? null;

            $out = $defaults;

            if (is_numeric($min)) $out['min_cards'] = (int)$min;
            if (is_numeric($target)) $out['target'] = (int)$target;
            if (is_numeric($max)) $out['max'] = (int)$max;

            if (array_key_exists('allow_fallback', $c)) {
                $out['allow_fallback'] = (bool)$c['allow_fallback'];
            }

            // also provide alias min
            $out['min'] = $out['min_cards'];
            return $out;
        }
    }

    return $defaults;
}

    private function isListArray(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
 * ✅ Overrides 统一入口（Step3）
 * - highlights: legacy/unified 的执行顺序由 manifest.assets.overrides.order 决定
 * - cards/reads: 只有 unified 生效（legacy 只影响 highlights）
 */
private function applyOverridesUnified(
    array $chain,
    string $contentPackageDir,
    string $typeCode,
    array $tags,
    array $baseHighlights,
    array $sections,
    array $recommendedReads,
    ?array $overridesDoc,
    array $overridesOrderBuckets,
    bool $applyReadsOverrides = true
): array {
    // 统一 overrides：把已加载的 doc 直接塞给 applier
    $unifiedDoc = $this->buildUnifiedOverridesDocForApplierFromPackChain($chain, $overridesDoc);

$ovrCtx = [
    'report_overrides_doc' => $unifiedDoc,
    'overrides_debug'      => (bool) env('FAP_OVR_DEBUG', false),
    'tags'                 => $tags,

    // ✅ explain 透传给 overrides applier -> RuleEngine
    'capture_explain'      => app()->environment('local') && (
        (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
    ),
    'explain_collector'    => (app()->environment('local') && (
        (bool) env('RE_EXPLAIN_PAYLOAD', false) || (bool) env('RE_EXPLAIN', false)
    )) ? ($GLOBALS['__re_explain_collector__'] ?? null) : null,
];

    // 1) highlights pipeline：严格按 manifest order
    $highlights = $baseHighlights;

    foreach ($overridesOrderBuckets as $bucket) {
        if ($bucket === 'highlights_legacy') {
            // legacy highlights overrides
            $highlights = $this->overridesApplier->apply($contentPackageDir, $typeCode, $highlights);
            continue;
        }
        if ($bucket === 'unified') {
            // unified overrides (highlights target)
            $highlights = $this->reportOverridesApplier->applyHighlights(
                $contentPackageDir,
                $typeCode,
                $highlights,
                $ovrCtx
            );
            continue;
        }
        // 其他 bucket 暂不处理（但 file_loaded 仍会被审计）
    }

    if ($applyReadsOverrides) {
        Log::debug('[reads] before applyReads', [
      'count' => is_array($recommendedReads) ? count($recommendedReads) : -1,
      'first' => is_array($recommendedReads) ? ($recommendedReads[0]['id'] ?? null) : null,
    ]);

        // 3) reads 只跑 unified
        $recommendedReads = $this->reportOverridesApplier->applyReads(
            $contentPackageDir,
            $typeCode,
            $recommendedReads,
            $ovrCtx
        );

        Log::debug('[reads] after applyReads', [
      'count' => is_array($recommendedReads) ? count($recommendedReads) : -1,
      'first' => is_array($recommendedReads) ? ($recommendedReads[0]['id'] ?? null) : null,
    ]);
    }


    $ovrExplain = $this->reportOverridesApplier->getExplain();

return [$highlights, $sections, $recommendedReads, $ovrExplain];
}

// ✅ 把 report_overrides.json（已由 store 加载） + report_rules.json（同 pack 目录）合并成 Applier 认可的 doc
private function buildUnifiedOverridesDocForApplierFromPackChain(array $chain, ?array $overridesDoc): ?array
{
    $docs = [];

    // 1) 先放入 store->loadOverrides() 的结果（report_overrides.json）
    if (is_array($overridesDoc)) {
        $docs[] = $overridesDoc;
    }

    if (empty($docs)) return null;

    // 3) 归一化成 ReportOverridesApplier 认可的结构：schema + rules + __src_chain
    $base = [
        'schema' => 'fap.report.overrides.v1',
        'rules' => [],
        '__src_chain' => [],
    ];

    foreach ($docs as $d) {
        if (!is_array($d)) continue;

        $rules = null;
        if (is_array($d['rules'] ?? null)) $rules = $d['rules'];
        elseif (is_array($d['overrides'] ?? null)) $rules = $d['overrides'];

        if (is_array($rules)) {
            foreach ($rules as $r) {
                if (!is_array($r)) continue;

                // ✅ 每条 rule 没有 __src 就继承 doc 的 __src
                if (!isset($r['__src']) && is_array($d['__src'] ?? null)) {
                    $r['__src'] = $d['__src'];
                }
                $base['rules'][] = $r;
            }
        }

        if (is_array($d['__src'] ?? null)) $base['__src_chain'][] = $d['__src'];
        if (is_array($d['__src_chain'] ?? null)) {
            foreach ($d['__src_chain'] as $src) {
                if (is_array($src)) $base['__src_chain'][] = $src;
            }
        }
    }

    return $base;
}

// 1) 在 ReportComposer 类里新增：合并 overrides + rules + select_rules 为统一 doc
private function buildUnifiedOverridesDocForApplier(string $contentPackageDir, ?array $overridesDoc): ?array
{
    $docs = [];

    // A) 先放入 Composer 已经算出来的 overridesDoc（通常来自 report_overrides.json）
    if (is_array($overridesDoc)) {
        $docs[] = $overridesDoc;
    }

    // B) 再补齐两份“规则文件”（里面就有 target=reads）
    foreach (['report_rules.json', 'report_select_rules.json'] as $rel) {
        $p = rtrim($contentPackageDir, '/').'/'.$rel;
        if (!is_file($p)) continue;

        $raw = @file_get_contents($p);
        if ($raw === false) continue;

        $j = json_decode($raw, true);
        if (!is_array($j)) continue;

        // 给 doc 打上来源（用于你现有的 src_chain/debug）
        $j['__src'] = [
            'pack_id'  => data_get($overridesDoc, '__src_chain.0.pack_id'),
            'version'  => data_get($overridesDoc, '__src_chain.0.version'),
            'file'     => basename($rel),
            'rel'      => $rel,
            'path'     => realpath($p) ?: $p,
        ];

        $docs[] = $j;
    }

    if (empty($docs)) return null;

    // C) 统一成 ReportOverridesApplier 认可的结构：schema + rules + __src_chain
    $base = [
        'schema' => 'fap.report.overrides.v1',
        'rules' => [],
        '__src_chain' => [],
    ];

    foreach ($docs as $d) {
        if (!is_array($d)) continue;

        $rules = null;
        if (is_array($d['rules'] ?? null)) $rules = $d['rules'];
        elseif (is_array($d['overrides'] ?? null)) $rules = $d['overrides']; // 兼容老 key

        if (is_array($rules)) {
            foreach ($rules as $r) {
                if (!is_array($r)) continue;

                // ✅ 每条 rule 没有 __src 的话，就继承 doc 的 __src（方便审计）
                if (!isset($r['__src']) && is_array($d['__src'] ?? null)) {
                    $r['__src'] = $d['__src'];
                }

                $base['rules'][] = $r;
            }
        }

        if (is_array($d['__src'] ?? null)) {
            $base['__src_chain'][] = $d['__src'];
        }
        if (is_array($d['__src_chain'] ?? null)) {
            foreach ($d['__src_chain'] as $src) {
                if (is_array($src)) $base['__src_chain'][] = $src;
            }
        }
    }

    return $base;
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

/**
 * 从 pack chain 读取 type_profiles.json，并取出 $typeCode 对应 profile
 * 优先：pack.assets(type_profiles) 声明的文件
 * 兜底：ctx['loadTypeProfile']（仅当 pack 找不到时才会触发）
 */
private function loadTypeProfileFromPackChain(
    array $chain,
    string $typeCode,
    array $ctx,
    string $legacyContentPackageDir
): array {
    $doc = $this->loadJsonDocFromPackChain(
        $chain,
        'type_profiles',
        'type_profiles.json',
        $ctx,
        $legacyContentPackageDir
    );

    $items = null;

    // 常见结构：{ "items": { "ESTJ-A": {...} } }
    if (is_array($doc) && is_array($doc['items'] ?? null)) {
        $items = $doc['items'];
    } elseif (is_array($doc)) {
        // 兜底：有些文件可能直接就是 map 或 list
        $items = $doc;
    }

    $picked = $this->pickItemByTypeCode($items, $typeCode, 'type_code');
    if (is_array($picked)) return $picked;

    // fallback：旧 ctx loader（测试“爆炸验证”时这里不应该被走到）
    if (is_callable($ctx['loadTypeProfile'] ?? null)) {
        $p = ($ctx['loadTypeProfile'])($legacyContentPackageDir, $typeCode);
        if (is_array($p)) return $p;
        if (is_object($p)) return json_decode(json_encode($p, JSON_UNESCAPED_UNICODE), true) ?: [];
    }

    return [];
}

/**
 * 从 pack chain 读取 report_identity_cards.json，并取出 $typeCode 对应 identity card
 * 优先：pack.assets(identity) 声明的文件
 * 兜底：ctx['loadReportAssetItems']（仅当 pack 找不到时才会触发）
 */
private function loadIdentityCardFromPackChain(
    array $chain,
    string $typeCode,
    array $ctx,
    string $legacyContentPackageDir
): ?array {
    $doc = $this->loadJsonDocFromPackChain(
        $chain,
        'identity',
        'report_identity_cards.json',
        $ctx,
        $legacyContentPackageDir
    );

    $items = null;

    // 可能结构 1：{items: { "ESTJ-A": {...} } }
    if (is_array($doc) && is_array($doc['items'] ?? null)) {
        $items = $doc['items'];
    } elseif (is_array($doc)) {
        $items = $doc;
    }

    $picked = $this->pickItemByTypeCode($items, $typeCode, 'type_code');
    if (is_array($picked)) return $picked;

    // fallback：旧 ctx loader
    if (is_callable($ctx['loadReportAssetItems'] ?? null)) {
        $map = ($ctx['loadReportAssetItems'])($legacyContentPackageDir, 'report_identity_cards.json', 'type_code');
        if (is_object($map)) $map = json_decode(json_encode($map, JSON_UNESCAPED_UNICODE), true);
        if (is_array($map) && is_array($map[$typeCode] ?? null)) return $map[$typeCode];
    }

    return null;
}

/**
 * 支持两种 items 形态：
 * 1) map：items["ESTJ-A"] = {...}
 * 2) list：items[] = ["type_code"=>"ESTJ-A", ...]
 */
private function pickItemByTypeCode($items, string $typeCode, string $keyField = 'type_code'): ?array
{
    if (!is_array($items)) return null;

    // map 形态：key 就是 typeCode
    if (isset($items[$typeCode]) && is_array($items[$typeCode])) {
        return $items[$typeCode];
    }

    // list 形态：遍历找 keyField
    $isList = (array_keys($items) === range(0, count($items) - 1));
    if ($isList) {
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $k = (string)($row[$keyField] ?? '');
            if ($k === $typeCode) return $row;
        }
    }

    return null;
}

private function loadRoleCardFromPackChain(
    array $chain,
    string $typeCode,
    array $ctx,
    string $legacyContentPackageDir
): array {
    $role = $this->roleCodeFromType($typeCode);

    $doc = $this->loadJsonDocFromPackChain(
        $chain,
        'identity',                 // ✅ 大概率 roles/strategies 都挂在 identity 资产里
        'report_roles.json',
        $ctx,
        $legacyContentPackageDir
    );

    $items = is_array($doc['items'] ?? null) ? $doc['items'] : (is_array($doc) ? $doc : []);
    $card = (isset($items[$role]) && is_array($items[$role])) ? $items[$role] : [];

    if ($card) {
        $card['code'] = $card['code'] ?? $role;
    }

    // fallback（只有 pack 找不到时才会触发；做“爆炸验证”时不应走到）
    if (!$card && is_callable($ctx['buildRoleCard'] ?? null)) {
        $x = ($ctx['buildRoleCard'])($legacyContentPackageDir, $typeCode);
        if (is_array($x)) return $x;
        if (is_object($x)) return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: [];
    }

    return $card ?: [];
}

private function loadStrategyCardFromPackChain(
    array $chain,
    string $typeCode,
    array $ctx,
    string $legacyContentPackageDir
): array {
    $st = $this->strategyCodeFromType($typeCode);

    $doc = $this->loadJsonDocFromPackChain(
        $chain,
        'identity',
        'report_strategies.json',
        $ctx,
        $legacyContentPackageDir
    );

    $items = is_array($doc['items'] ?? null) ? $doc['items'] : (is_array($doc) ? $doc : []);
    $card = (isset($items[$st]) && is_array($items[$st])) ? $items[$st] : [];

    if ($card) {
        $card['code'] = $card['code'] ?? $st;
    }

    if (!$card && is_callable($ctx['buildStrategyCard'] ?? null)) {
        $x = ($ctx['buildStrategyCard'])($legacyContentPackageDir, $typeCode);
        if (is_array($x)) return $x;
        if (is_object($x)) return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: [];
    }

    return $card ?: [];
}

/**
 * Role: NT / NF / SJ / SP
 * - N+T => NT
 * - N+F => NF
 * - S+J => SJ
 * - S+P => SP
 */
private function roleCodeFromType(string $typeCode): string
{
    // e.g. "ESTJ-A" => "ESTJ"
    $core = strtoupper(explode('-', $typeCode)[0] ?? '');
    if (strlen($core) < 4) return 'NT';

    $m2 = $core[1]; // S/N
    $m3 = $core[2]; // T/F
    $m4 = $core[3]; // J/P

    if ($m2 === 'N') {
        return ($m3 === 'F') ? 'NF' : 'NT';
    }

    // S
    return ($m4 === 'P') ? 'SP' : 'SJ';
}

/**
 * Strategy: EA / ET / IA / IT
 * - E/I + A/T
 */
private function strategyCodeFromType(string $typeCode): string
{
    $core = strtoupper(explode('-', $typeCode)[0] ?? '');
    $suffix = strtoupper(explode('-', $typeCode)[1] ?? 'T'); // A/T

    $ei = ($core !== '' && ($core[0] === 'I')) ? 'I' : 'E';
    $at = ($suffix === 'A') ? 'A' : 'T';

    return $ei . $at; // EA/ET/IA/IT
}

private function loadBorderlineNoteFromPackChain(
    array $chain,
    array $scoresPct,
    array $ctx,
    string $legacyContentPackageDir
): array {
    // 这里的文件名按你 CN v0.2.1 的资产命名：
    // borderline 是一个 assetKey，里面通常有 templates + notes 两个文件
    // 你这里要的是“note”（给 identity micro 用），一般是 report_borderline_notes.json
    $doc = $this->loadJsonDocFromPackChain(
        $chain,
        'borderline',
        'report_borderline_notes.json',
        $ctx,
        $legacyContentPackageDir
    );

    // 如果你的实际文件名不是 notes，而是 report_borderline_note.json 或 items 结构不同，
    // 这里先做一个“宽松兜底”：保证返回至少 {items:[]}
    if (is_array($doc)) {
        if (isset($doc['items']) && is_array($doc['items'])) {
            return $doc;
        }
        // 有的会直接把 items 放在顶层
        if ($this->isAssocArrayLoose($doc)) {
            return ['items' => $doc];
        }
    }

    // fallback：只在 pack 没找到时才会触发（爆炸验证时不应走到）
    if (is_callable($ctx['buildBorderlineNote'] ?? null)) {
        $x = ($ctx['buildBorderlineNote'])($scoresPct, $legacyContentPackageDir);
        if (is_array($x)) return $x;
        if (is_object($x)) return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: ['items' => []];
    }

    return ['items' => []];
}

/**
 * 轻量判断 assoc（不依赖你别的 helper）
 */
private function isAssocArrayLoose(array $a): bool
{
    if ($a === []) return false;
    return array_keys($a) !== range(0, count($a) - 1);
}

private function buildRecommendedReadsFromDoc(

    ?array $doc,
    string $typeCode,
    array $scoresPct,
    array $roleCard,
    array $strategyCard,
    int $limit = 10
): array {
    Log::debug('[reads] enter buildRecommendedReadsFromDoc', [
        'type' => $typeCode,
        'limit' => $limit,
        'scores_pct_keys' => array_keys($scoresPct),
        'doc_is_null' => $doc === null,
        'doc_keys' => is_array($doc) ? array_keys($doc) : null,
        'role_code' => $roleCard['code'] ?? null,
        'strategy_code' => $strategyCard['code'] ?? null,
    ]);
    if (!is_array($doc)) return [];

    $items = $doc['items'] ?? null;
    if (!is_array($items)) return [];

    $picked = [];

    $pushList = function ($list) use (&$picked) {
        if (!is_array($list)) return;
        foreach ($list as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;
            if (isset($picked[$id])) continue;
            $picked[$id] = $it;
        }
    };

    // 1) by_type
    $pushList($items['by_type'][$typeCode] ?? null);

    // 2) by_role (NT/NF/SJ/SP)
    $role = (string)($roleCard['code'] ?? '');
    if ($role !== '') $pushList($items['by_role'][$role] ?? null);

    // 3) by_strategy (EA/ET/IA/IT)
    $st = (string)($strategyCard['code'] ?? '');
    if ($st !== '') $pushList($items['by_strategy'][$st] ?? null);

    // 4) by_top_axis（按每轴“更偏的一边”挑）
    $dims = ['EI','SN','TF','JP','AT'];
    foreach ($dims as $d) {
        $raw = (int)($scoresPct[$d] ?? 50);
        [$p1,$p2] = match ($d) {
            'EI' => ['E','I'],
            'SN' => ['S','N'],
            'TF' => ['T','F'],
            'JP' => ['J','P'],
            'AT' => ['A','T'],
            default => ['',''],
        };
        $side = $raw >= 50 ? $p1 : $p2;
        $axisKey = "{$d}:{$side}";
        $pushList($items['by_top_axis'][$axisKey] ?? null);
    }

    // 5) fallback
    $pushList($items['fallback'] ?? null);

    // 最后按 priority 降序 + 截断
    $out = array_values($picked);
    usort($out, fn($a,$b) => ((float)($b['priority'] ?? 0)) <=> ((float)($a['priority'] ?? 0)));

    return array_slice($out, 0, $limit);
}

/**
 * ContentGraph 推荐 reads（v1）
 * 返回：[$items, $ok]
 */
private function buildRecommendedReadsFromContentGraph(
    array $chain,
    string $scaleCode,
    string $region,
    string $locale,
    string $typeCode,
    array $scores,
    array $axisStates
): array {
    $pack = $this->resolveContentGraphPack($chain, $scaleCode, $region, $locale);
    if (!$pack instanceof ContentPack) {
        return [[], false];
    }

    if (!$this->packSupportsContentGraph($pack)) {
        return [[], false];
    }

    $doc = $this->loadContentGraphDoc($pack->basePath());
    if (!is_array($doc)) {
        return [[], false];
    }

    $nodes = $this->loadContentGraphNodes($pack->basePath());
    $items = $this->buildRecommendedReadsFromContentGraphDoc($doc, $nodes, $typeCode, $scores, $axisStates);

    return [is_array($items) ? $items : [], true];
}

private function resolveContentGraphPack(
    array $chain,
    string $scaleCode,
    string $region,
    string $locale
): ?ContentPack {
    $primary = $chain[0] ?? null;
    if (!$primary instanceof ContentPack) {
        return null;
    }

    $pin = trim((string) env('CONTENT_GRAPH_PACK_PIN', ''));
    if ($pin === '') {
        return $primary;
    }

    $pinVersion = $this->normalizeRequestedVersion($pin);
    if (!is_string($pinVersion) || $pinVersion === '') {
        return $primary;
    }

    try {
        $rp = $this->resolver->resolve($scaleCode, $region, $locale, $pinVersion);
        $pinChain = $this->toContentPackChain($rp);
        $pinPack = $pinChain[0] ?? null;
        if ($pinPack instanceof ContentPack) {
            return $pinPack;
        }
    } catch (\Throwable $e) {
        Log::warning('[content_graph] pack_pin_resolve_failed', [
            'pin' => $pin,
            'scale' => $scaleCode,
            'region' => $region,
            'locale' => $locale,
            'error' => $e->getMessage(),
        ]);
    }

    return $primary;
}

private function packSupportsContentGraph(ContentPack $pack): bool
{
    $caps = $pack->capabilities();
    return (bool) ($caps['content_graph'] ?? false);
}

private function loadContentGraphDoc(string $baseDir): ?array
{
    $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'content_graph.json';
    if (!is_file($path)) return null;

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

private function loadContentGraphNodes(string $baseDir): array
{
    $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

    return [
        'read' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'reads', 'read'),
        'role_card' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'role_cards', 'role_card'),
        'strategy_card' => $this->loadContentGraphNodesFromDir($baseDir . DIRECTORY_SEPARATOR . 'strategy_cards', 'strategy_card'),
    ];
}

private function loadContentGraphNodesFromDir(string $dir, string $type): array
{
    if (!is_dir($dir)) return [];

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
    if (!is_array($files)) return [];

    sort($files, SORT_STRING);

    $out = [];
    foreach ($files as $path) {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') continue;

        $json = json_decode($raw, true);
        if (!is_array($json)) continue;

        $nodeType = (string) ($json['type'] ?? '');
        if ($nodeType !== $type) continue;

        $id = (string) ($json['id'] ?? '');
        if ($id === '') continue;

        $status = (string) ($json['status'] ?? '');
        if ($status !== 'active') continue;

        $out[$id] = [
            'id' => $id,
            'type' => $type,
            'title' => (string) ($json['title'] ?? ''),
            'slug' => (string) ($json['slug'] ?? ''),
            'status' => $status,
        ];
    }

    return $out;
}

private function buildRecommendedReadsFromContentGraphDoc(
    array $doc,
    array $nodes,
    string $typeCode,
    array $scores,
    array $axisStates
): array {
    $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];
    $typeRules = is_array($rules['type_code'] ?? null) ? $rules['type_code'] : [];
    $roleRules = is_array($typeRules['role_card'] ?? null) ? $typeRules['role_card'] : [];
    $readRules = is_array($typeRules['reads'] ?? null) ? $typeRules['reads'] : [];
    $axisRules = is_array($rules['axis_state_or_trait_bucket'] ?? null) ? $rules['axis_state_or_trait_bucket'] : [];

    $typeCodeNorm = strtoupper(trim($typeCode));
    if ($typeCodeNorm === '') return [];

    $traitBuckets = $this->deriveTraitBucketsFromScores($scores);
    $axisTokens = $this->deriveAxisStateTokens($scores, $axisStates);
    $axisStates = is_array($axisStates) ? $axisStates : [];

    $out = [];
    $seen = [];

    $append = function (string $id, string $type, string $why) use (&$out, &$seen, $nodes): bool {
        if ($id === '' || isset($seen[$id])) return false;
        if (!isset($nodes[$type][$id]) || !is_array($nodes[$type][$id])) return false;

        $node = $nodes[$type][$id];
        $out[] = [
            'id' => (string) ($node['id'] ?? $id),
            'type' => $type,
            'title' => (string) ($node['title'] ?? ''),
            'slug' => (string) ($node['slug'] ?? ''),
            'why' => $why,
            'show_order' => 0,
        ];
        $seen[$id] = true;
        return true;
    };

    // 1) role_card by type_code (first match)
    foreach ($roleRules as $rule) {
        if (!is_array($rule)) continue;
        $tc = strtoupper(trim((string) ($rule['type_code'] ?? '')));
        if ($tc !== $typeCodeNorm) continue;

        foreach ($this->normalizeStringList($rule['ids'] ?? []) as $id) {
            if ($append($id, 'role_card', $this->formatContentGraphWhy($typeCodeNorm, '', ''))) {
                break; // role_card 仅保留 1 条
            }
        }
        break;
    }

    $readsCount = 0;

    // 2) reads by type_code (first match)
    foreach ($readRules as $rule) {
        if (!is_array($rule)) continue;
        $tc = strtoupper(trim((string) ($rule['type_code'] ?? '')));
        if ($tc !== $typeCodeNorm) continue;

        foreach ($this->normalizeStringList($rule['ids'] ?? []) as $id) {
            if ($readsCount >= 3) break;
            if ($append($id, 'read', $this->formatContentGraphWhy($typeCodeNorm, '', ''))) {
                $readsCount++;
            }
        }
        break;
    }

    // 3) axis_state/trait_bucket rules (ordered)
    $extraReads = [];
    $strategyCards = [];

    foreach ($axisRules as $rule) {
        if (!is_array($rule)) continue;

        $ruleTraitBuckets = $this->normalizeStringList($rule['trait_buckets'] ?? []);
        $ruleAxisStates = $this->normalizeStringList($rule['axis_states'] ?? []);

        $matchTrait = $this->firstMatch($ruleTraitBuckets, $traitBuckets);
        $matchAxis = $this->firstMatch($ruleAxisStates, $axisTokens);

        if ($matchTrait === null && $matchAxis === null) continue;

        $axisStateForWhy = $this->axisStateForWhy($matchAxis, $matchTrait, $axisStates);
        $why = $this->formatContentGraphWhy($typeCodeNorm, $matchTrait ?? '', $axisStateForWhy);

        foreach ($this->normalizeStringList($rule['read_ids'] ?? []) as $id) {
            $extraReads[] = ['id' => $id, 'why' => $why];
        }
        foreach ($this->normalizeStringList($rule['strategy_card_ids'] ?? []) as $id) {
            $strategyCards[] = ['id' => $id, 'why' => $why];
        }
    }

    foreach ($extraReads as $it) {
        if ($readsCount >= 3) break;
        if ($append((string) ($it['id'] ?? ''), 'read', (string) ($it['why'] ?? ''))) {
            $readsCount++;
        }
    }

    $strategyCount = 0;
    foreach ($strategyCards as $it) {
        if ($strategyCount >= 2) break;
        if ($append((string) ($it['id'] ?? ''), 'strategy_card', (string) ($it['why'] ?? ''))) {
            $strategyCount++;
        }
    }

    $out = array_slice($out, 0, 6);
    foreach ($out as $i => &$item) {
        $item['show_order'] = $i + 1;
    }
    unset($item);

    return $out;
}

private function normalizeStringList($list): array
{
    if (!is_array($list)) return [];

    $out = [];
    foreach ($list as $v) {
        if (!is_string($v)) continue;
        $v = trim($v);
        if ($v === '') continue;
        $out[] = $v;
    }
    return $out;
}

private function firstMatch(array $ruleValues, array $inputValues): ?string
{
    if (empty($ruleValues) || empty($inputValues)) return null;

    $set = [];
    foreach ($inputValues as $v) {
        if (!is_string($v)) continue;
        $set[$v] = true;
    }

    foreach ($ruleValues as $v) {
        if (!is_string($v)) continue;
        if (isset($set[$v])) return $v;
    }
    return null;
}

private function deriveTraitBucketsFromScores(array $scores): array
{
    $map = [
        'JP' => [
            'J' => 'high_conscientiousness',
            'P' => 'low_conscientiousness',
        ],
        'TF' => [
            'F' => 'high_empathy',
            'T' => 'low_empathy',
        ],
        'AT' => [
            'A' => 'high_resilience',
            'T' => 'low_resilience',
        ],
    ];

    $out = [];
    foreach ($map as $dim => $pairs) {
        $side = strtoupper((string) ($scores[$dim]['side'] ?? ''));
        if (isset($pairs[$side])) {
            $out[] = $pairs[$side];
        }
    }

    return $out;
}

private function deriveAxisStateTokens(array $scores, array $axisStates): array
{
    $out = [];
    $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

    foreach ($dims as $dim) {
        $side = strtoupper((string) ($scores[$dim]['side'] ?? ''));
        if ($side !== '') $out[] = "{$dim}:{$side}";

        $state = (string) ($axisStates[$dim] ?? '');
        if ($state !== '') $out[] = "{$dim}:{$state}";
    }

    return array_values(array_unique($out));
}

private function axisStateForWhy(?string $matchedAxis, ?string $matchedTrait, array $axisStates): string
{
    if (is_string($matchedAxis) && $matchedAxis !== '') {
        return $matchedAxis;
    }

    if (is_string($matchedTrait) && $matchedTrait !== '') {
        $dim = $this->traitBucketToAxisDim($matchedTrait);
        if ($dim && is_string($axisStates[$dim] ?? null)) {
            return (string) $axisStates[$dim];
        }
    }

    return '';
}

private function traitBucketToAxisDim(string $traitBucket): ?string
{
    return match ($traitBucket) {
        'high_conscientiousness', 'low_conscientiousness' => 'JP',
        'high_empathy', 'low_empathy' => 'TF',
        'high_resilience', 'low_resilience' => 'AT',
        default => null,
    };
}

private function formatContentGraphWhy(string $typeCode, string $traitBucket, string $axisState): string
{
    $t = $typeCode !== '' ? $typeCode : '-';
    $tb = $traitBucket !== '' ? $traitBucket : '-';
    $as = $axisState !== '' ? $axisState : '-';
    return "type_code:{$t} / trait_bucket:{$tb} / axis_state:{$as}";
}

/**
 * ✅ 按 manifest.assets.overrides 的 order 定死顺序加载 overrides 文件（支持多文件/多 bucket）
 * - 每个 doc 会带 __src
 * - 每条 rule 会带 __src（src_idx/src_pack_id/src_file）
 * - FAP_OVR_TRACE=1 时打印 [OVR] file_loaded
 */
private function loadOverridesDocsFromPackChain(array $chain, array $ctx, string $legacyContentPackageDir): array
{
    $trace = (bool) env('FAP_OVR_TRACE', false);

    $docs = [];
    $idx  = 0;

    foreach ($chain as $p) {
        if (!$p instanceof ContentPack) continue;

        $assetVal = $p->assets()['overrides'] ?? null;
        if (!is_array($assetVal) || $assetVal === []) continue;

        // 1) 先按 order 拿到 “相对路径列表”
        $orderedPaths = $this->getOverridesOrderedPaths($assetVal);

        // 2) 依次加载（顺序就是 manifest 定死的顺序）
        foreach ($orderedPaths as $rel) {
            if (!is_string($rel) || trim($rel) === '') continue;

            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
            if (!is_file($abs)) continue;

            $json = json_decode((string) file_get_contents($abs), true);
            if (!is_array($json)) continue;

            // ✅ 归一化：允许 doc 用 overrides:[]，统一映射到 rules:[]
if (!is_array($json['rules'] ?? null) && is_array($json['overrides'] ?? null)) {
    $json['rules'] = $json['overrides'];
}

            // 注入 doc 源信息
            $src = [
                'idx'     => $idx,
                'pack_id' => $p->packId(),
                'version' => $p->version(),
                'file'    => basename($rel),
                'rel'     => $rel,
                'path'    => $abs,
            ];
            $json['__src'] = $src;

            // 注入每条 rule 源信息（用于 rule_applied 可追溯）
            if (is_array($json['rules'] ?? null)) {
    foreach ($json['rules'] as &$r) {
        if (is_array($r)) $r['__src'] = $src;
    }
    unset($r);
}

            if ($trace) {
                Log::info('[OVR] file_loaded', [
                    'idx'     => $src['idx'],
                    'pack_id' => $src['pack_id'],
                    'version' => $src['version'],
                    'file'    => $src['file'],
                    'rel'     => $src['rel'],
                    'schema'  => $json['schema'] ?? null,
        'count'   => is_array($json['rules'] ?? null) ? count($json['rules']) : 0,
                ]);
            }

            $docs[] = $json;
            $idx++;
        }
    }

    // 兜底：如果 pack chain 完全没找到 overrides 文件，才允许走 ctx['loadReportAssetJson']
    if (empty($docs) && is_callable($ctx['loadReportAssetJson'] ?? null)) {
        $raw = ($ctx['loadReportAssetJson'])($legacyContentPackageDir, 'report_overrides.json');
        if (is_object($raw)) $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
        if (is_array($raw)) {
            $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
            if (is_object($doc)) $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
            if (is_array($doc)) {
                $doc['__src'] = [
                    'idx' => 0,
                    'pack_id' => 'LEGACY_CTX',
                    'version' => null,
                    'file' => 'report_overrides.json',
                    'rel' => null,
                    'path' => null,
                ];
                if (is_array($doc['rules'] ?? null)) {
    foreach ($doc['rules'] as &$r) {
        if (is_array($r)) $r['__src'] = $doc['__src'];
    }
    unset($r);
}
                $docs[] = $doc;
            }
        }
    }

    return $docs;
}

/**
 * 把 manifest.assets.overrides 解析成“按 order 的相对路径列表”
 * 支持两种形态：
 * 1) list: ["report_overrides.json"]
 * 2) map : {"order":["highlights_legacy","unified"], "highlights_legacy":[...], "unified":[...]}
 */
private function getOverridesOrderedPaths(array $assetVal): array
{
    // 形态 1：list
    if ($this->isListArray($assetVal)) {
        return array_values(array_filter($assetVal, fn($x) => is_string($x) && trim($x) !== ''));
    }

    // 形态 2：map + order
    $order = $assetVal['order'] ?? null;
    $out = [];

    if (is_array($order) && $order !== []) {
        foreach ($order as $bucket) {
            if (!is_string($bucket) || $bucket === '') continue;
            $v = $assetVal[$bucket] ?? null;
            if (!is_array($v)) continue;

            foreach ($v as $path) {
                if (is_string($path) && trim($path) !== '') $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    // 没有 order：退化为“所有 bucket（排除 order）拼起来”
    foreach ($assetVal as $k => $v) {
        if ($k === 'order') continue;
        if (!is_array($v)) continue;
        foreach ($v as $path) {
            if (is_string($path) && trim($path) !== '') $out[] = $path;
        }
    }
    return array_values(array_unique($out));
}

/**
 * 从 pack chain 的第一个可用 pack 里取 overrides 的 bucket 顺序（manifest.assets.overrides.order）
 * - list 形态：返回 ['unified']（只是一个默认 bucket 名）
 * - map 形态：返回 order 数组；没有 order 则返回除 order 外的 keys（顺序按出现顺序）
 */
private function getOverridesOrderBucketsFromPackChain(array $chain): array
{
    foreach ($chain as $p) {
        if (!$p instanceof ContentPack) continue;
        $assetVal = $p->assets()['overrides'] ?? null;
        if (!is_array($assetVal) || $assetVal === []) continue;
        return $this->getOverridesOrderBuckets($assetVal);
    }
    return ['highlights_legacy', 'unified']; // 兜底
}

private function getOverridesOrderBuckets(array $assetVal): array
{
    // list
    if ($this->isListArray($assetVal)) {
        return ['unified'];
    }

    // map + order
    $order = $assetVal['order'] ?? null;
    if (is_array($order) && $order !== []) {
        $out = [];
        foreach ($order as $x) {
            if (is_string($x) && trim($x) !== '') $out[] = $x;
        }
        return $out ?: ['highlights_legacy', 'unified'];
    }

    // no order: keys except order
    $out = [];
    foreach ($assetVal as $k => $_) {
        if ($k === 'order') continue;
        if (is_string($k) && trim($k) !== '') $out[] = $k;
    }
    return $out ?: ['highlights_legacy', 'unified'];
}

/**
 * 把多个 overrides doc 合并成一个 doc：
 * - overrides = 按 docs 顺序依次 concat
 * - 每条 rule 已经带 __src，不会丢“来源”
 */
private function mergeOverridesDocs(array $docs): ?array
{
    if (empty($docs)) return null;

    $base = [
    'schema' => 'fap.report.overrides.v1',
    'rules' => [],
    '__src_chain' => [],
];

foreach ($docs as $d) {
    if (!is_array($d)) continue;

    if (is_array($d['rules'] ?? null)) {
        foreach ($d['rules'] as $r) {
            if (is_array($r)) $base['rules'][] = $r;
        }
    }

    if (is_array($d['__src'] ?? null)) $base['__src_chain'][] = $d['__src'];
}

    return $base;
}

private function packIdToDir(string $packId): string
{
    $s = trim($packId);
    if ($s === '') return '';

    // 例：MBTI.global.en.v0.2.1-TEST
    if (substr_count($s, '.') >= 3) {
        $parts  = explode('.', $s);
        $scale  = $parts[0] ?? 'MBTI';
        $region = strtoupper($parts[1] ?? 'GLOBAL');
        $locale = $parts[2] ?? 'en';
        $ver    = implode('.', array_slice($parts, 3)); // v0.2.1-TEST（含点）
        return "{$scale}/{$region}/{$locale}/{$ver}";
    }

    // 如果传进来已经是路径形式，就原样返回
    if (str_contains($s, '/')) return trim($s, "/");

    // 兜底：给个可读值
    return $s;
}

/**
 * 把最终 report payload 落盘成 JSON，方便排障/复现
 * 路径：storage/app/private/reports/{attemptId}/report.json
 * 同时写一份带时间戳的快照，便于对比多次生成差异：
 * storage/app/private/reports/{attemptId}/report.{Ymd_His}.json
 */
private function persistReportJson(string $attemptId, array $reportPayload): void
{
    try {
        // 你的 local disk root 已经是 storage/app/private
        $disk = Storage::disk('local');

        // ✅ 关键：不要再多拼一个 private/
        $baseDir = "reports/{$attemptId}";
        $disk->makeDirectory($baseDir);

        $json = json_encode(
            $reportPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            Log::warning('[REPORT] persist_report_json_encode_failed', [
                'attempt_id' => $attemptId,
                'json_error' => json_last_error_msg(),
            ]);
            return;
        }

        $pathLatest = "{$baseDir}/report.json";
        $disk->put($pathLatest, $json);

        $ts = now()->format('Ymd_His');
        $pathSnapshot = "{$baseDir}/report.{$ts}.json";
        $disk->put($pathSnapshot, $json);

        Log::info('[REPORT] persisted', [
            'attempt_id' => $attemptId,
            'disk' => 'local',
            'root' => (string) config('filesystems.disks.local.root'),
            'latest' => $pathLatest,
            'snapshot' => $pathSnapshot,
            'latest_exists' => $disk->exists($pathLatest),
            // 如果有 path() 方法就顺便打绝对路径
            'latest_abs' => method_exists($disk, 'path') ? $disk->path($pathLatest) : null,
        ]);
    } catch (\Throwable $e) {
        Log::warning('[REPORT] persist_report_failed', [
            'attempt_id' => $attemptId,
            'error' => $e->getMessage(),
        ]);
    }
}
}
