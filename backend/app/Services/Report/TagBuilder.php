<?php

namespace App\Services\Report;

final class TagBuilder
{
    /**
     * @param array $scores  来自 getReport() 里的 $scores（report.scores）结构：
     *   [
     *     'EI' => ['pct'=>..,'state'=>..,'side'=>..,'delta'=>..],
     *     ...
     *   ]
     * @param array $layers  只需要：['role_card'=>['code'=>..], 'strategy_card'=>['code'=>..]]
     * @return string[] tags
     */
    public function build(array $scores, array $layers = []): array
    {
        $tags = [];

        // 1) axis + state + borderline
        foreach ($scores as $dim => $s) {
            if (!is_array($s)) continue;

            $dim  = (string)$dim;
            $side = (string)($s['side'] ?? '');
            $state = (string)($s['state'] ?? '');
            $delta = (int)($s['delta'] ?? 0); // 0..50（你当前口径）

            if ($dim !== '' && $side !== '') {
                $this->add($tags, "axis:{$dim}:{$side}");
            }

            // 强度：既给全局 state:*，也给按轴 state:EI:*
            if ($state !== '') {
                $this->add($tags, "state:{$state}");
                if ($dim !== '') {
                    $this->add($tags, "state:{$dim}:{$state}");
                }
            }

            // 边界：你之前口径是 abs(rawPct-50)<=5，这里用 delta<=5 等价（delta 是 displayPct-50）
            if ($dim !== '' && $delta <= 5) {
                $this->add($tags, "borderline:{$dim}");
                // 你如果更喜欢这种也可以开：
                // $this->add($tags, "axis:{$dim}:borderline");
            }
        }

        // 2) role / strategy
        $role = (string)($layers['role_card']['code'] ?? '');
        if ($role !== '') {
            $this->add($tags, "role:{$role}");
        }

        $strategy = (string)($layers['strategy_card']['code'] ?? '');
        if ($strategy !== '') {
            $this->add($tags, "strategy:{$strategy}");
        }

        // 3) 通用兜底 tag（方便卡片写 fallback）
        $this->add($tags, "tagset:v1");

        return array_values($tags);
    }

    private function add(array &$set, string $tag): void
    {
        $tag = trim($tag);
        if ($tag === '') return;
        $set[$tag] = $tag; // 用关联数组去重
    }
}