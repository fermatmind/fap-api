<?php

namespace App\Services\Report;

use App\Services\Content\ContentPack;
use App\Services\Content\ContentStore;
use App\Services\Rules\RuleEngine;
use Illuminate\Support\Facades\Log;

final class SectionCardGenerator
{
    /**
     * ✅ 新入口：统一走 ContentStore（pack chain -> store -> 标准化 doc）
     *
     * @param string $section traits/career/growth/relationships
     * @param ContentPack[] $chain primary + fallback packs
     * @param array $userTags TagBuilder 输出
     * @param array $axisInfo 建议传 report.scores（含 delta/side/pct）；可选 axisInfo['attempt_id'] 用于稳定打散
     * @param string|null $legacyContentPackageDir 仅用于日志/兜底信息（不会读取旧路径）
     * @return array[] cards
     */
    public function generateFromPackChain(
        string $section,
        array $chain,
        array $userTags,
        array $axisInfo = [],
        ?string $legacyContentPackageDir = null
    ): array {
        $store = new ContentStore($chain);

        $doc   = $store->loadCardsDoc($section);
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];
        $selectRules = $store->loadSelectRules(); // ✅ 统一 rules（含 target）

        Log::info('[CARDS] loaded_from_store', [
            'section'    => $section,
            'file'       => "report_cards_{$section}.json",
            'items'      => count($items),
            'rules'      => $rules,
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return $this->generateFromItems(
    $section,
    $items,
    $userTags,
    $axisInfo,
    $legacyContentPackageDir,
    $rules,
    $selectRules
);
    }

    /**
     * ✅ 从 “已标准化的 items(+rules)” 生成 cards
     * 关键变化：选卡逻辑全部迁移到 RuleEngine::selectConstrained()
     */
    private function generateFromItems(
    string $section,
    array $items,
    array $userTags,
    array $axisInfo = [],
    ?string $legacyContentPackageDir = null,
    array $rules = [],
    array $selectRules = []
): array {
        // ==========
        // rules：强依赖 store 的标准输出
        // ==========
        $minCardsVal    = $rules['min_cards'] ?? null;
        $targetCardsVal = $rules['target_cards'] ?? null;
        $maxCardsVal    = $rules['max_cards'] ?? null;
        $fallbackTags   = $rules['fallback_tags'] ?? null;

        if (!is_numeric($minCardsVal) || !is_numeric($targetCardsVal) || !is_numeric($maxCardsVal) || !is_array($fallbackTags)) {
            throw new \RuntimeException('CARDS_RULES_NOT_NORMALIZED: generator expects store-normalized rules (min_cards/target_cards/max_cards/fallback_tags)');
        }

        $minCards    = (int)$minCardsVal;
        $targetCards = (int)$targetCardsVal;
        $maxCards    = (int)$maxCardsVal;

        // assets 不存在：直接返回兜底
        if (empty($items)) {
            return $this->fallbackCards($section, $minCards);
        }

        // normalize userTags set（这里仅做 userTags 的集合化）
        $userSet = [];
        foreach ($userTags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t !== '') $userSet[$t] = true;
        }

        // seed（用于 shuffle 稳定）
        $seed = $this->stableSeed($userSet, $axisInfo);

        // RuleEngine
/** @var RuleEngine $re */
$re = app(RuleEngine::class);
$ctx = "cards:{$section}";

// explain 开关：
// - debugRE：控制日志（local/dev 才建议开）
// - captureExplain：控制“把 _explain 收集进 reportPayload”（可在 production 开，但建议按需开）
$debugRE = app()->environment('local', 'development') && (
    (bool) env('FAP_RE_DEBUG', false) ||
    (bool) env('RE_EXPLAIN', false)   ||       // 开 explain 日志就等同 debug
    (bool) env('RE_CTX_TAGS', false)          // 你要看 ctx tags 时也应该算 debug
);
$captureExplain = (bool)($axisInfo['capture_explain'] ?? $axisInfo['_capture_explain'] ?? false);

// explain 收集器：ReportComposer 可以塞到 $scores(axisInfo) 里下传
$explainCollector = null;
if (isset($axisInfo['explain_collector']) && is_callable($axisInfo['explain_collector'])) {
    $explainCollector = $axisInfo['explain_collector'];
} elseif (isset($axisInfo['_explain_collector']) && is_callable($axisInfo['_explain_collector'])) {
    $explainCollector = $axisInfo['_explain_collector'];
}

        // ========== 2.1 先按 rules 过滤（不截断，拿全量 kept）==========

// 这里的 $rules 是 cards 的“配额规则对象”（min_cards/target_cards/...），不是 rule list
// rule list 需要你从 doc 里拿一个数组字段（例如 doc['rules_items'] / doc['select_rules'] / doc['filters'] 等）
// 你现在的 doc 结构里没有展示这个字段，所以这里先安全兜底为空数组（等你把规则列表加进 doc 再接上）

[$kept, $evals1] = $re->selectTargeted($items, $userSet, [
    'target'    => 'cards',
    'rules'     => $selectRules,

    'ctx'       => $ctx,
    'section'   => $section,
    'seed'      => $seed,

    // 关键：不截断（先拿全量 kept）
    'max_items' => is_array($items) ? count($items) : 0,

    // explain/采样
    'rejected_samples' => 6,
    'debug'            => $debugRE,
    'capture_explain'  => $captureExplain,
    'explain_collector'=> $explainCollector,

    // 预留
    'global_rules' => [],
]);

// ========== 2.2 再做你已有的 axis/non-axis/fallback 配额编排（这里才截断）==========

[$selectedItems, $evals2] = $re->selectConstrained($kept, $userSet, [
    'ctx'      => $ctx,
    'section'  => $section,
    'seed'     => $seed,

    // explain/采样
    'debug'            => $debugRE,
    'capture_explain'  => $captureExplain,
    'explain_collector'=> $explainCollector,
    'rejected_samples' => 6,

    // 配额规则
    'min_cards'     => $minCards,
    'target_cards'  => $targetCards,
    'max_cards'     => $maxCards,
    'fallback_tags' => $fallbackTags,

    // axis match 需要
    'axis_info'    => $axisInfo,

    // 预留
    'global_rules' => [],
]);

$evals = array_merge($evals1, $evals2);

        // 如果引擎一个都没选出来，直接兜底
        if (empty($selectedItems)) {
            $out = $this->fallbackCards($section, $minCards);

            Log::info('[CARDS] selected', [
                'section'    => $section,
                'ids'        => array_map(fn($x) => $x['id'] ?? null, $out),
                'legacy_dir' => $legacyContentPackageDir,
            ]);

            return $out;
        }

        // ✅ 映射成最终输出 cards（保持你原先输出结构）
        $out = array_map(function ($it) use ($section) {
            if (!is_array($it)) return null;

            return [
                'id'       => (string)($it['id'] ?? ''),
                'section'  => (string)($it['section'] ?? $section),
                'title'    => (string)($it['title'] ?? ''),
                'desc'     => (string)($it['desc'] ?? ''),
                'bullets'  => is_array($it['bullets'] ?? null) ? array_values($it['bullets']) : [],
                'tips'     => is_array($it['tips'] ?? null) ? array_values($it['tips']) : [],
                'tags'     => is_array($it['tags'] ?? null) ? $it['tags'] : [],
                'priority' => (int)($it['priority'] ?? 0),
                'match'    => $it['match'] ?? null,
            ];
        }, $selectedItems);

        $out = array_values(array_filter($out, fn($x) => is_array($x) && (string)($x['id'] ?? '') !== ''));

        // 仍不足：补齐到 minCards（保持你原来的 fallbackCards 行为）
        if (count($out) < $minCards) {
            $out = array_merge($out, $this->fallbackCards($section, $minCards - count($out)));
        }

        // 最终硬切 max_cards
        $out = array_slice(array_values($out), 0, $maxCards);

        Log::info('[CARDS] selected', [
            'section'    => $section,
            'ids'        => array_map(fn($x) => $x['id'] ?? null, $out),
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return $out;
    }

    /**
     * ✅ 从 store 直接生成（保留）
     */
    public function generateFromStore(
        string $section,
        ContentStore $store,
        array $userTags,
        array $axisInfo,
        ?string $legacyContentPackageDir = null
    ): array {
        $doc = $store->loadCardsDoc($section);

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];
        $selectRules = $store->loadSelectRules();

        return $this->generateFromItems(
    $section,
    $items,
    $userTags,
    $axisInfo,
    $legacyContentPackageDir,
    $rules,
    $selectRules
);
    }

    /**
     * ⚠️ 旧入口（建议只保留给兼容调用）；开启开关后，任何旧调用直接炸
     */
    public function generate(string $section, string $contentPackageVersion, array $userTags, array $axisInfo = []): array
    {
        if ((bool) env('FAP_FORBID_LEGACY_CARDS_LOADER', false)) {
            throw new \RuntimeException('LEGACY_SECTION_CARD_GENERATOR_USED: generate(section, contentPackageVersion, ...)');
        }

        // 兼容：不再读取旧路径，直接兜底卡
        return $this->fallbackCards($section, 2);
    }

    // =========================
    // helpers（seed + fallback）
    // =========================

    private function stableSeed(array $userSet, array $axisInfo): int
    {
        $attemptId = $axisInfo['attempt_id'] ?? null;
        if (is_string($attemptId) && $attemptId !== '') {
            return $this->ucrc32($attemptId);
        }

        $tags = array_keys($userSet);
        sort($tags);

        $dims = ['EI','SN','TF','JP','AT'];
        $axes = [];
        foreach ($dims as $dim) {
            $v = (isset($axisInfo[$dim]) && is_array($axisInfo[$dim])) ? $axisInfo[$dim] : [];
            $side  = (string)($v['side'] ?? '');
            $delta = (int)($v['delta'] ?? 0);
            $pct   = (int)($v['pct'] ?? 0);
            $axes[] = "{$dim}:{$side}:{$delta}:{$pct}";
        }

        $payload = json_encode([$tags, $axes], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) $payload = '';

        return $this->ucrc32($payload);
    }

    private function ucrc32(string $s): int
    {
        $u = sprintf('%u', crc32($s));
        return (int)$u;
    }

    private function fallbackCards(string $section, int $need): array
    {
        $out = [];
        for ($i = 1; $i <= $need; $i++) {
            $out[] = [
                'id'       => "{$section}_fallback_{$i}",
                'section'  => $section,
                'title'    => 'General Tip',
                'desc'     => 'Content pack did not provide enough matched cards. Showing a safe fallback tip.',
                'bullets'  => [
                    'Turn strengths into a repeatable template',
                    'Add one counter-check in key moments',
                    'Weekly review: keep what works, remove what doesn’t'
                ],
                'tips'     => [
                    'Write your first instinct, then add one alternative',
                    'Use checklists to reduce cognitive load'
                ],
                'tags'     => ['fallback'],
                'priority' => 0,
                'match'    => null,
            ];
        }
        return $out;
    }
}