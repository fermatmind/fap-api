<?php
declare(strict_types=1);

namespace App\Services\Report;

/**
 * ReportContentNormalizer
 * - 统一把各种 report item 的字段“类型兜底 + 缺省补默认”收敛到这里
 * - 目标：其它地方（Generator/Builder/OverridesApplier）不要再写死默认 tips/tags/priority
 */
final class ReportContentNormalizer
{
    /** @var array<int,string> */
    public const DEFAULT_TIPS = ['先做一小步，再迭代优化'];

    /**
     * 把任意输入规范成 array<string>：
     * - 非数组 => []
     * - 过滤非字符串 / 空白
     * - trim
     *
     * @param mixed $v
     * @return array<int,string>
     */
    public static function normalizeStringArray(mixed $v): array
    {
        if (!is_array($v)) return [];

        $out = [];
        foreach ($v as $x) {
            if (!is_string($x)) continue;
            $s = trim($x);
            if ($s === '') continue;
            $out[] = $s;
        }
        return array_values($out);
    }

    /**
     * 统一：把 tips 规范成 array<string>，并在“缺省/空”时补默认。
     *
     * 注意：你的 pipeline 里“没写 tips”的情况最终可能变成 tips=[]，
     * 所以这里把 “empty array” 也视作需要补默认（用于验收“删字段回读”）。
     *
     * @param array<string,mixed> $it
     * @param array<int,string>|null $defaultTips
     * @param string $key
     * @return array<string,mixed>
     */
    public static function fillTipsIfMissing(array $it, ?array $defaultTips = null, string $key = 'tips'): array
    {
        $tipsRaw = $it[$key] ?? null;
        $tips    = self::normalizeStringArray($tipsRaw);

        if (count($tips) === 0) {
            $tips = self::normalizeStringArray($defaultTips ?? self::DEFAULT_TIPS);
        }

        $it[$key] = $tips;
        return $it;
    }

    /**
     * cards 统一 normalize：
     * - tips: array<string> 且至少 1 条（缺省则补默认）
     * - tags: array<string>（缺省 => []）
     * - priority: int（缺省 => 0）
     *
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public static function card(array $c): array
    {
        $c = self::fillTipsIfMissing($c);

        if (!array_key_exists('tags', $c)) $c['tags'] = [];
        $c['tags'] = self::normalizeStringArray($c['tags']);

        if (!array_key_exists('priority', $c)) $c['priority'] = 0;
        $c['priority'] = (int)($c['priority'] ?? 0);

        return $c;
    }

    /**
     * highlights 统一 normalize：
     * - tips: 默认可按 kind 做细分（action/blindspot 等），否则走 DEFAULT_TIPS
     * - tags: array<string>（缺省 => []）
     *
     * @param array<string,mixed> $h
     * @param string|null $typeCode 仅用于未来扩展，不依赖也没关系
     * @return array<string,mixed>
     */
    public static function highlight(array $h, ?string $typeCode = null): array
    {
        $kind = is_string($h['kind'] ?? null) ? trim((string)$h['kind']) : '';

        $defaultTips = match ($kind) {
            'action'    => ['把目标写成 1 句话，再拆成 3 个可交付节点'],
            'blindspot' => ['重要场景先做一次“反向校验”再决定'],
            default     => self::DEFAULT_TIPS,
        };

        $h = self::fillTipsIfMissing($h, $defaultTips, 'tips');

        if (!array_key_exists('tags', $h)) $h['tags'] = [];
        $h['tags'] = self::normalizeStringArray($h['tags']);

        return $h;
    }

    /**
     * recommended_reads 统一 normalize：
     * - tips: 若未来 reads 也支持 tips，可统一补（当前即使不用也不影响）
     * - tags: array<string>（缺省 => []）
     * - priority: int（缺省 => 0）
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function read(array $r): array
    {
        // reads 目前不一定有 tips，但统一补不会破坏兼容
        $r = self::fillTipsIfMissing($r);

        if (!array_key_exists('tags', $r)) $r['tags'] = [];
        $r['tags'] = self::normalizeStringArray($r['tags']);

        if (!array_key_exists('priority', $r)) $r['priority'] = 0;
        $r['priority'] = (int)($r['priority'] ?? 0);

        return $r;
    }

    /**
     * 统一 tips 入口：Controller 不要再出现 tips 的 default 分支
     *
     * @param mixed $tipsRaw 可能是 null|string|array|false
     * @param array $ctx     可选：用于按 type/locale/场景做差异化默认
     */
    public static function normalizeTips(mixed $tipsRaw, array $ctx = []): array
    {
        // 1) 显式关闭 tips（可选：如果你们支持这种用法）
        if ($tipsRaw === false) return [];

        // 2) 未传/空 -> 默认
        if ($tipsRaw === null) return self::DEFAULT_TIPS;
        if (is_string($tipsRaw) && trim($tipsRaw) === '') return self::DEFAULT_TIPS;

        // 3) array -> 直接用（必要时可在这里做字段兜底/清洗）
        if (is_array($tipsRaw)) return $tipsRaw;

        // 4) string -> 如果你支持 tips 策略 key，就在这里解析
        if (is_string($tipsRaw)) {
            // 示例：按 key 选择不同默认（你可按项目实际实现）
            // switch ($tipsRaw) { case 'default': return self::DEFAULT_TIPS; ... }
            // 现在至少兜底到 DEFAULT_TIPS，避免“奇怪字符串”把结果弄没
            return self::DEFAULT_TIPS;
        }

        // 5) 其他类型（数字/对象等）→ 强兜底
        return self::DEFAULT_TIPS;
    }
}