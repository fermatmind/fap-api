<?php

declare(strict_types=1);

namespace App\Support\Mbti;

final class MbtiZhResultContentPolicy
{
    /** @var array<string, string> */
    private const ASSET_LABELS = [
        'hero-illustration' => '人格类型插图',
        'traits-illustration' => '偏好维度插图',
        'traits-summary-illustration' => '维度摘要插图',
        'career-illustration' => '职业探索插图',
        'growth-illustration' => '成长探索插图',
        'relationships-illustration' => '关系模式插图',
        'final-offer-illustration' => '完整报告插图',
    ];

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public static function normalizeProjection(array $projection, string $locale): array
    {
        if (! self::isZh($locale)) {
            return $projection;
        }

        $projection = self::replaceLegacyCopy($projection);
        $projection['profile'] = is_array($projection['profile'] ?? null) ? $projection['profile'] : [];
        $projection['profile']['rarity'] = null;
        $projection['dimensions'] = self::normalizeDimensions(
            is_array($projection['dimensions'] ?? null) ? $projection['dimensions'] : [],
        );
        $projection['sections'] = self::normalizeSections(
            is_array($projection['sections'] ?? null) ? $projection['sections'] : [],
        );
        $projection['scientific_context'] = self::scientificContext();

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function normalizeDesktopContent(array $content, string $locale): array
    {
        if (! self::isZh($locale)) {
            return $content;
        }

        $content = self::replaceLegacyCopy($content);
        $content['hero'] = is_array($content['hero'] ?? null) ? $content['hero'] : [];
        $content['hero']['profile_identity'] = is_array($content['hero']['profile_identity'] ?? null)
            ? $content['hero']['profile_identity']
            : [];
        $content['hero']['profile_identity']['rarity'] = null;

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return list<array<string, mixed>>
     */
    public static function normalizeAssetSlots(array $slots, string $locale): array
    {
        if (! self::isZh($locale)) {
            return $slots;
        }

        foreach ($slots as $index => $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $slotId = trim((string) ($slot['slot_id'] ?? $slot['id'] ?? ''));
            $slot['label'] = self::ASSET_LABELS[$slotId] ?? '人格结果插图';
            // The clone illustrations repeat adjacent text and are decorative.
            $slot['alt'] = '';
            $slots[$index] = $slot;
        }

        return array_values($slots);
    }

    public static function clarityState(?int $dominantPct): ?string
    {
        if (! is_int($dominantPct)) {
            return null;
        }

        return match (true) {
            $dominantPct === 50 => 'tie',
            $dominantPct <= 55 => 'close_call',
            $dominantPct <= 59 => 'slight',
            $dominantPct <= 74 => 'clear',
            default => 'very_clear',
        };
    }

    /** @return array<string, mixed> */
    private static function scientificContext(): array
    {
        return [
            'metric_definition' => '百分比是 93Q 中归属于该轴的回答按题目权重换算后的方向得分；页面展示结果代码所采用一侧的比例。它不是回答一致性、测量信度、能力水平或人群百分位。',
            'close_call_rule' => '50% 表示两侧计分相同，51%–55% 表示当前仅有轻微偏向；这两种结果都不应解释为稳定人格事实。',
            'type_code_rule' => '为保持四字母结果格式，平分时沿用该计分版本预先配置的归类规则；页面必须同时提示平分并展示相邻类型。',
            'at_dimension' => [
                'label' => '压力与反馈风格（FermatMind 扩展）',
                'status' => 'A/T 不是官方 MBTI 的第五个偏好轴，而是 FermatMind 用于描述压力反应、反馈敏感度与自我确认方式的扩展维度。',
                'theoretical_source' => '四字母部分沿用 Myers-Briggs 类型偏好的四组二分框架；A/T 仅作为一般人格特质与压力/反馈反应的产品化扩展，不宣称获得 MBTI 官方体系验证。',
                'calculation' => 'A/T 百分比由 93Q 中标记到 A/T 维度的回答按题目权重归一化计算，页面展示被归类一侧的方向比例。',
                'scope' => '适合用于自我观察和比较不同情境下的反应倾向，不用于临床诊断，也不能直接推出抗压能力、心理健康或工作能力。',
            ],
            'use_limits' => [
                '本结果不用于临床或心理诊断。',
                '本结果不应作为招聘、淘汰或岗位胜任力的单一依据。',
                '职业、成长和关系内容是待验证的探索假设，不构成能力或职业结果保证。',
            ],
        ];
    }

    /**
     * @param  list<mixed>  $dimensions
     * @return list<mixed>
     */
    private static function normalizeDimensions(array $dimensions): array
    {
        foreach ($dimensions as $index => $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $axisId = strtoupper(trim((string) ($dimension['id'] ?? $dimension['code'] ?? '')));
            $pct = is_numeric($dimension['pct'] ?? null) ? (int) round((float) $dimension['pct']) : null;
            $dimension['clarity_state'] = self::clarityState($pct);
            $dimension['tie_break_applied'] = $pct === 50;

            if ($axisId === 'AT') {
                $dimension['name'] = '压力与反馈风格（FermatMind 扩展）';
                $dimension['label'] = '压力与反馈风格（FermatMind 扩展）';
                $dimension['summary'] = '用于观察面对压力、反馈与自我确认时的当前反应倾向；不是官方 MBTI 第五轴。';
                $dimension['description'] = '该比例来自 A/T 相关题目的加权方向得分，不代表抗压能力、心理健康或工作能力。';
            }

            $dimensions[$index] = $dimension;
        }

        return array_values($dimensions);
    }

    /**
     * @param  list<mixed>  $sections
     * @return list<mixed>
     */
    private static function normalizeSections(array $sections): array
    {
        foreach ($sections as $index => $section) {
            if (! is_array($section) || strtolower(trim((string) ($section['key'] ?? ''))) !== 'faq') {
                continue;
            }

            $section['title'] = '常见问题';
            $payload = is_array($section['payload'] ?? null) ? $section['payload'] : [];
            $payload['title'] = '常见问题';
            $payload['items'] = array_slice(
                is_array($payload['items'] ?? null) ? array_values($payload['items']) : [],
                0,
                4,
            );
            $section['payload'] = $payload;
            $sections[$index] = $section;
        }

        return array_values($sections);
    }

    private static function isZh(string $locale): bool
    {
        return in_array(strtolower(trim($locale)), ['zh', 'zh-cn', 'zh_cn'], true);
    }

    private static function replaceLegacyCopy(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace(
                [
                    '费马心理',
                    '它让成长从口号变成可重复的方法。',
                    '不爱空喊口号',
                ],
                [
                    '费马测试 / FermatMind',
                    '可观察的证据是：能指出触发点、说明下一次要调整的动作，并在类似场景复查结果。',
                    '更偏好用实际行动表达立场',
                ],
                $value,
            );
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::replaceLegacyCopy($item);
        }

        return $value;
    }
}
