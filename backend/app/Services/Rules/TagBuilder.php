<?php

namespace App\Services\Rules;

final class TagBuilder
{
    /** @return array<string,true> set */
    public static function emptySet(): array
    {
        return [];
    }

    /** @param array<string,true> $set */
    public static function add(array &$set, string $tag): void
    {
        $tag = self::norm($tag);
        if ($tag === '') return;
        $set[$tag] = true;
    }

    /** @param array<string,true> $set */
    public static function addMany(array &$set, array $tags): void
    {
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            self::add($set, $t);
        }
    }

    /** @param array<string,true> $a @param array<string,true> $b @return array<string,true> */
    public static function union(array $a, array $b): array
    {
        // keys union
        return $a + $b;
    }

    /** @return string[] */
    public static function toList(array $set): array
    {
        return array_keys($set);
    }

    /**
     * 把“上下文事实”翻译成 tags（引擎只认识 tags）
     *
     * @param array $ctx 你现有的 report ctx（可以是 ReportComposer 里的 ctx）
     * @return array<string,true>
     */
    public static function buildContextTags(array $ctx): array
    {
        $set = self::emptySet();

        // 1) type
        $type = (string)($ctx['type_code'] ?? $ctx['type'] ?? '');
        if ($type !== '') {
            self::add($set, "type:{$type}");

            // 可选：从 type_code 自动补 axis:xx:*（如果你 ctx 里没给）
            self::addAxisTagsFromTypeCode($set, $type);
        }

        // 2) axis（如果 ctx 已经提供 axis sides，用它；否则用上面的 fromTypeCode）
        // 期望形态：['EI'=>'I','SN'=>'N','TF'=>'T','JP'=>'J','AT'=>'A']
        if (isset($ctx['axis']) && is_array($ctx['axis'])) {
            foreach ($ctx['axis'] as $dim => $side) {
                if (!is_string($dim) || !is_string($side)) continue;
                self::add($set, "axis:" . strtoupper($dim) . ":" . strtoupper($side));
            }
        }

        // 3) state:JP:borderline / state:EI:clear
        // 期望形态：['EI'=>'clear','JP'=>'borderline', ...]
        if (isset($ctx['axis_state']) && is_array($ctx['axis_state'])) {
            foreach ($ctx['axis_state'] as $dim => $st) {
                if (!is_string($dim) || !is_string($st)) continue;
                self::add($set, "state:" . strtoupper($dim) . ":" . strtolower($st));
            }
        }

        // 4) borderline:JP （你说你已有）
        // 期望形态：['JP','EI',...]
        if (isset($ctx['borderline']) && is_array($ctx['borderline'])) {
            foreach ($ctx['borderline'] as $dim) {
                if (!is_string($dim)) continue;
                self::add($set, "borderline:" . strtoupper($dim));
            }
        }

        // 5) role / strategy（如果 ctx 已经算好了就直接塞 tags；不在这里做业务推导）
        $role = (string)($ctx['role'] ?? '');
        if ($role !== '') self::add($set, "role:" . strtoupper($role));

        $strategy = (string)($ctx['strategy'] ?? '');
        if ($strategy !== '') self::add($set, "strategy:" . strtoupper($strategy));

        // 6) 如果你已经有一坨 context_tags（历史遗留），也可以直接并入（兼容）
        if (isset($ctx['tags']) && is_array($ctx['tags'])) {
            self::addMany($set, $ctx['tags']);
        }

        return $set;
    }

    /**
     * 评估某个候选 item 时，把 item.tags + section + item:id 合并进去
     *
     * @param array<string,true> $contextTags
     * @param array $item 候选 item（cards/highlights/reads 的一条）
     * @param string|null $section traits/career...（cards/highlights有意义）
     * @param string|null $itemId card.xxx/read.xxx...
     * @return array<string,true>
     */
    public static function buildEvalTags(array $contextTags, array $item, ?string $section, ?string $itemId): array
    {
        $set = $contextTags;

        // item 自带 tags（内容包里给的 tags）
        if (isset($item['tags']) && is_array($item['tags'])) {
            self::addMany($set, $item['tags']);
        }

        if ($section !== null && $section !== '') {
            self::add($set, "section:" . $section);
        }

        if ($itemId !== null && $itemId !== '') {
            self::add($set, "item:" . $itemId);
        }

        return $set;
    }

    // -------------------------
    // internal helpers
    // -------------------------

    /** 统一 tag 规范：trim + 把多余空白去掉；prefix 小写，value 保留原样（或你也可统一大小写） */
    private static function norm(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') return '';

        // 把中间空白压缩掉（可选）
        $tag = preg_replace('/\s+/', ' ', $tag) ?? $tag;

        // 推荐规范：prefix 全小写（type/axis/state/role...），其余保持
        // 只处理第一个 ":" 之前的部分
        $pos = strpos($tag, ':');
        if ($pos === false) return $tag;

        $prefix = strtolower(substr($tag, 0, $pos));
        $rest   = substr($tag, $pos + 1);

        return $prefix . ':' . $rest;
    }

    /** 从 ENTJ-A 补 axis:EI:E 等 */
    private static function addAxisTagsFromTypeCode(array &$set, string $typeCode): void
    {
        // 支持 ENFJ-A / ENTJ-T 这种
        if (!preg_match('/^([EI])([SN])([TF])([JP])-(A|T)$/', strtoupper($typeCode), $m)) {
            return;
        }
        self::add($set, "axis:EI:" . $m[1]);
        self::add($set, "axis:SN:" . $m[2]);
        self::add($set, "axis:TF:" . $m[3]);
        self::add($set, "axis:JP:" . $m[4]);
        self::add($set, "axis:AT:" . $m[5]);
    }
}