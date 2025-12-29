<?php

namespace App\Services\Report;

/**
 * TagBuilder (v1)
 * - 唯一职责：把 report.scores 推导成稳定 tags（RuleEngine 唯一输入）
 * - 纯函数风格：输入数组 -> 输出 tags array[string]
 * - 输出稳定：去重 + 排序
 *
 * 依赖约定：
 * - $scores 形如：
 *   [
 *     'EI' => ['side'=>'E','delta'=>18,'pct'=>64],
 *     'SN' => ['side'=>'S','delta'=>10,'pct'=>55],
 *     'TF' => ['side'=>'T','delta'=>22,'pct'=>71],
 *     'JP' => ['side'=>'J','delta'=>16,'pct'=>62],
 *     'AT' => ['side'=>'A','delta'=>8,'pct'=>52],
 *   ]
 */
final class TagBuilder
{
    // 冻结维度集合（避免出现未知轴污染）
    public const DIMS = ['EI', 'SN', 'TF', 'JP', 'AT'];

    // borderline 判定阈值：abs(delta) < 阈值 => borderline
    public const BORDERLINE_DELTA = 12;

    /**
     * 主入口：从 scores + ctx 构造 tags
     *
     * @param array $scores report.scores 结构（含 side/delta/pct）
     * @param array|string|null $ctx
     *   - string: 直接当作 typeCode（如 'ESTJ-A'）
     *   - array : [
     *       'type_code' => 'ESTJ-A',
     *       'role_card' => ['code'=>'SJ', ...],
     *       'strategy_card' => ['code'=>'EA', ...],
     *       'extra' => ['debug:xxx', ...] // 可选
     *     ]
     * @return string[] tags（稳定排序，去重）
     */
    public function build(array $scores, array|string|null $ctx = null): array
    {
        $typeCode = null;
        $roleCode = null;
        $strategyCode = null;
        $extraTags = [];

        // 1) 解析 ctx
        if (is_string($ctx) && trim($ctx) !== '') {
            $typeCode = trim($ctx);
        } elseif (is_array($ctx)) {
            $typeCode = is_string($ctx['type_code'] ?? null) ? trim((string)$ctx['type_code']) : null;

            $roleCard = $ctx['role_card'] ?? null;
            if (is_array($roleCard)) {
                $roleCode = is_string($roleCard['code'] ?? null) ? trim((string)$roleCard['code']) : null;
            }

            $strategyCard = $ctx['strategy_card'] ?? null;
            if (is_array($strategyCard)) {
                $strategyCode = is_string($strategyCard['code'] ?? null) ? trim((string)$strategyCard['code']) : null;
            }

            $extra = $ctx['extra'] ?? null;
            if (is_array($extra)) {
                foreach ($extra as $t) {
                    if (is_string($t) && trim($t) !== '') $extraTags[] = trim($t);
                }
            }
        }

        // 2) 如果没传 typeCode，尝试从 scores 推导（兜底）
        if (!$typeCode) {
            $typeCode = $this->inferTypeFromAxis($scores);
        }

        // 3) 如果没传 role/strategy，尽量从 typeCode 推导（兜底）
        $mbti4 = $typeCode ? $this->extractMbti4($typeCode) : '';
        if (!$roleCode && $mbti4 !== '') {
            $roleCode = $this->keirseyRole($mbti4);
        }
        if (!$strategyCode && $typeCode) {
            $strategyCode = $this->strategyFromType($typeCode, $scores);
        }

        $tags = [];

        // 4) type / role / strategy
        if (is_string($typeCode) && $typeCode !== '') {
            $tags[] = "type:" . strtoupper($typeCode);
        }
        if (is_string($roleCode) && $roleCode !== '') {
            $tags[] = "role:" . strtoupper($roleCode);
        }
        if (is_string($strategyCode) && $strategyCode !== '') {
            $tags[] = "strategy:" . strtoupper($strategyCode);
        }

        // 5) axis / state / borderline
        foreach (self::DIMS as $dim) {
            $v = (isset($scores[$dim]) && is_array($scores[$dim])) ? $scores[$dim] : [];

            $side = isset($v['side']) && is_string($v['side']) ? strtoupper(trim($v['side'])) : '';
            $delta = (int)($v['delta'] ?? 0);

            if ($side !== '') {
                $tags[] = "axis:$dim:$side";
            }

            if (abs($delta) < self::BORDERLINE_DELTA) {
                $tags[] = "borderline:$dim";
                $tags[] = "state:$dim:borderline";
            } else {
                $tags[] = "state:$dim:clear";
            }
        }

        // 6) extra tags（慎用）
        foreach ($extraTags as $t) {
            $tags[] = $t;
        }

        return $this->finalize($tags);
    }

    /**
     * 输出稳定：去重 + 过滤空 + 排序
     */
    private function finalize(array $tags): array
    {
        $set = [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t === '') continue;
            $set[$t] = true;
        }
        $out = array_keys($set);
        sort($out, SORT_STRING);
        return array_values($out);
    }

    /**
     * 从 axis 反推 4 字母（尽量），AT 用 axis:AT: 反推 -A/-T
     * 注意：这只是兜底；强烈建议你传最终 typeCode 进来。
     */
    private function inferTypeFromAxis(array $axisScores): string
    {
        $mbti = '';
        $map = [
            'EI' => ['E', 'I'],
            'SN' => ['S', 'N'],
            'TF' => ['T', 'F'],
            'JP' => ['J', 'P'],
        ];

        foreach (['EI','SN','TF','JP'] as $dim) {
            $v = (isset($axisScores[$dim]) && is_array($axisScores[$dim])) ? $axisScores[$dim] : [];
            $side = isset($v['side']) && is_string($v['side']) ? strtoupper(trim($v['side'])) : '';
            if ($side === '' || !in_array($side, $map[$dim], true)) return '';
            $mbti .= $side;
        }

        if ($mbti === '' || strlen($mbti) !== 4) return '';

        $at = (isset($axisScores['AT']) && is_array($axisScores['AT'])) ? $axisScores['AT'] : [];
        $sideAT = isset($at['side']) && is_string($at['side']) ? strtoupper(trim($at['side'])) : '';
        if ($sideAT === 'A' || $sideAT === 'T') {
            return $mbti . '-' . $sideAT;
        }

        return $mbti;
    }

    private function extractMbti4(string $typeCode): string
    {
        $typeCode = strtoupper(trim($typeCode));
        if (preg_match('/^([EIN][SN][TF][JP])(?:-(A|T))?$/', $typeCode, $m)) {
            return $m[1] ?? '';
        }
        return '';
    }

    /**
     * Keirsey temperament:
     * - SJ/SP/NF/NT（由第2字母+第4字母/第3字母决定）
     */
    private function keirseyRole(string $mbti4): string
    {
        $mbti4 = strtoupper(trim($mbti4));
        if (strlen($mbti4) !== 4) return '';

        $s2 = $mbti4[1]; // S/N
        $s3 = $mbti4[2]; // T/F
        $s4 = $mbti4[3]; // J/P

        if (($s2 !== 'S' && $s2 !== 'N') || ($s3 !== 'T' && $s3 !== 'F') || ($s4 !== 'J' && $s4 !== 'P')) {
            return '';
        }

        if ($s2 === 'S' && $s4 === 'J') return 'SJ';
        if ($s2 === 'S' && $s4 === 'P') return 'SP';
        if ($s2 === 'N' && $s3 === 'F') return 'NF';
        if ($s2 === 'N' && $s3 === 'T') return 'NT';

        return '';
    }

    /**
     * Strategy: (E|I) + (A|T)
     * - 优先用 typeCode 的 -A/-T
     * - 没有的话用 axisScores['AT']['side']
     */
    private function strategyFromType(string $typeCode, array $axisScores): string
    {
        $typeCode = strtoupper(trim($typeCode));
        $mbti4 = $this->extractMbti4($typeCode);
        if ($mbti4 === '' || strlen($mbti4) !== 4) return '';

        $ei = $mbti4[0]; // E/I
        if ($ei !== 'E' && $ei !== 'I') return '';

        $at = '';
        if (preg_match('/-(A|T)$/', $typeCode, $m)) {
            $at = $m[1] ?? '';
        }

        if ($at !== 'A' && $at !== 'T') {
            $v = (isset($axisScores['AT']) && is_array($axisScores['AT'])) ? $axisScores['AT'] : [];
            $sideAT = isset($v['side']) && is_string($v['side']) ? strtoupper(trim($v['side'])) : '';
            if ($sideAT === 'A' || $sideAT === 'T') $at = $sideAT;
        }

        if ($at !== 'A' && $at !== 'T') return '';

        return $ei . $at; // EA/ET/IA/IT
    }
}