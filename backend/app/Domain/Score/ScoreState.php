<?php

namespace App\Domain\Score;

final class ScoreState
{
    /**
     * 约定：pct 是 50~100（胜出侧的百分比，整数）
     */
    public static function stateFromPct(int $pct): string
    {
        // 防御：把 pct clamp 到 50~100
        $pct = max(50, min(100, $pct));

        // 你给的阈值（先定死，后面可配置化）
        if ($pct >= 80) return 'very_strong';
        if ($pct >= 70) return 'strong';
        if ($pct >= 60) return 'clear';
        if ($pct >= 55) return 'weak';
        return 'very_weak'; // 50-54
    }

    public static function isBorderline(int $pct, int $threshold = 5): bool
    {
        // pct >= 50 的约定下，borderline = pct <= 50 + threshold
        $pct = max(50, min(100, $pct));
        return $pct <= (50 + $threshold);
    }

    public static function deltaFromPct(int $pct): int
    {
        $pct = max(50, min(100, $pct));
        return $pct - 50;
    }
}