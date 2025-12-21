<?php

namespace App\Domain\Score;

final class AxisScore
{
    public function __construct(
        public readonly int $pct,         // 50~100（胜出侧百分比）
        public readonly string $side,     // 'E'/'I' 等（胜出侧字母）
        public readonly int $delta,       // pct - 50
        public readonly string $state,    // very_weak/weak/clear/strong/very_strong
        public readonly bool $borderline  // abs(pct-50)<=5（在本约定下等价于 pct<=55）
    ) {}

    /**
     * 用 counts 算轴分数（推荐）
     * @param int $countA 例如 E 的票数
     * @param int $countB 例如 I 的票数
     * @param string $sideA 例如 'E'
     * @param string $sideB 例如 'I'
     */
    public static function fromCounts(int $countA, int $countB, string $sideA, string $sideB): self
    {
        $a = max(0, $countA);
        $b = max(0, $countB);
        $total = $a + $b;

        // total=0 时：无法判断，按 50/sideA 兜底
        if ($total <= 0) {
            $pct = 50;
            $side = $sideA;
            return self::fromPctAndSide($pct, $side);
        }

        // rawPctA：A 侧占比（0~100）
        $rawPctA = ($a / $total) * 100.0;

        // 约定：输出胜出侧 pct（>=50）
        if ($rawPctA >= 50.0) {
            $pct = (int) round($rawPctA);
            $side = $sideA;
        } else {
            $pct = (int) round(100.0 - $rawPctA);
            $side = $sideB;
        }

        // 再 clamp 一下
        $pct = max(50, min(100, $pct));

        return self::fromPctAndSide($pct, $side);
    }

    /**
     * 已有 pct+side 时也能构造（兜底/兼容用）
     */
    public static function fromPctAndSide(int $pct, string $side): self
    {
        $pct = max(50, min(100, $pct));

        $delta = ScoreState::deltaFromPct($pct);
        $state = ScoreState::stateFromPct($pct);
        $borderline = ScoreState::isBorderline($pct);

        return new self(
            pct: $pct,
            side: $side,
            delta: $delta,
            state: $state,
            borderline: $borderline
        );
    }

    public function toArray(): array
    {
        return [
            'pct' => $this->pct,
            'state' => $this->state,
            'side' => $this->side,
            'delta' => $this->delta,
            'borderline' => $this->borderline,
        ];
    }
}