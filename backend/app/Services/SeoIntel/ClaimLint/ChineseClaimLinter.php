<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\ClaimLint;

final class ChineseClaimLinter
{
    public const RUNTIME = 'chinese_claim_linter';

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function lint(array $candidates): array
    {
        $results = [];

        foreach ($candidates as $candidate) {
            $results[] = $this->lintCandidate($candidate);
        }

        $lintState = $this->rollupState(array_column($results, 'lint_state'));
        $severity = $this->rollupSeverity(array_column($results, 'severity'));

        return [
            'runtime' => self::RUNTIME,
            'status' => $lintState === 'blocked' ? 'blocked' : 'success',
            'lint_state' => $lintState,
            'severity' => $severity,
            'candidate_count' => count($results),
            'results' => $results,
            'matched_rules' => $this->uniqueMerged($results, 'matched_rules'),
            'bounded_context_detected' => in_array(true, array_column($results, 'bounded_context_detected'), true),
            'blocked_phrases' => $this->uniqueMerged($results, 'blocked_phrases'),
            'needs_review_phrases' => $this->uniqueMerged($results, 'needs_review_phrases'),
            'allowed_bounded_phrases' => self::allowedBoundedPhrases(),
            'auto_rewrite_attempted' => false,
            'cms_mutation_attempted' => false,
            'production_scan_attempted' => false,
            'fap_web_modification_attempted' => false,
            'search_channel_enqueue_attempted' => false,
            'search_submission_attempted' => false,
            'seo_intel_write_attempted' => false,
            'scheduler_enabled' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function lintCandidate(array $candidate): array
    {
        $text = (string) ($candidate['text'] ?? '');
        $surface = (string) ($candidate['surface'] ?? 'unknown');
        $isPublic = (bool) ($candidate['is_public'] ?? false);
        $isIndexable = (bool) ($candidate['is_indexable'] ?? false);
        $blockedPhrases = $this->matchedPhrases($text, self::forbiddenPhrases());
        $needsReviewPhrases = $this->matchedPhrases($text, self::needsReviewPhrases());
        $boundedPhrases = $this->matchedPhrases($text, self::allowedBoundedPhrases());

        $lintState = $blockedPhrases !== []
            ? 'blocked'
            : ($needsReviewPhrases !== [] ? 'needs_review' : 'safe');

        return [
            'id' => (string) ($candidate['id'] ?? 'candidate'),
            'surface' => $surface,
            'is_public' => $isPublic,
            'is_indexable' => $isIndexable,
            'lint_state' => $lintState,
            'severity' => $this->severity($lintState, $surface, $isPublic, $isIndexable),
            'matched_rules' => $this->matchedRules($blockedPhrases, $needsReviewPhrases, $boundedPhrases),
            'bounded_context_detected' => $boundedPhrases !== [],
            'blocked_phrases' => $blockedPhrases,
            'needs_review_phrases' => $needsReviewPhrases,
            'allowed_bounded_phrases' => $boundedPhrases,
            'auto_rewrite_attempted' => false,
            'cms_mutation_attempted' => false,
            'production_scan_attempted' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public static function forbiddenPhrases(): array
    {
        return [
            '精准职业推荐',
            '最适合职业',
            'AI 职业规划',
            '岗位胜任力',
            '招聘适配',
            '职业成功率',
            '薪资保证',
            '个人离职预测',
            'MBTI决定收入',
            'MBTI预测离职',
            'Big Five职业精准匹配',
            'RIASEC推荐职业',
            '智商真实测量',
            '临床诊断',
            '诊断',
            '确诊',
            '治疗',
            '治愈',
            '心理疾病判断',
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedBoundedPhrases(): array
    {
        return [
            '职业方向参考',
            '兴趣信号',
            '工作方式倾向',
            '探索建议',
            '非诊断',
            '结果仅供参考',
            '自评筛查',
            '模型化指数',
            '聚合层面',
            '方向性趋势',
            'snapshot-based support',
            'evidence-backed explanation',
        ];
    }

    /**
     * @return list<string>
     */
    private static function needsReviewPhrases(): array
    {
        return [
            '推荐职业',
            '职业规划',
            '职业匹配',
            '胜任力',
            '预测',
            '测量',
        ];
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function matchedPhrases(string $text, array $phrases): array
    {
        $matches = [];

        foreach ($phrases as $phrase) {
            if ($phrase === '诊断' && str_contains($text, '非诊断')) {
                continue;
            }

            if ($phrase !== '' && str_contains($text, $phrase)) {
                $matches[] = $phrase;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<string>  $blockedPhrases
     * @param  list<string>  $needsReviewPhrases
     * @param  list<string>  $boundedPhrases
     * @return list<array<string, string>>
     */
    private function matchedRules(array $blockedPhrases, array $needsReviewPhrases, array $boundedPhrases): array
    {
        $rules = [];

        foreach ($blockedPhrases as $phrase) {
            $rules[] = ['rule' => 'forbidden_or_flagged_phrase', 'phrase' => $phrase];
        }

        foreach ($needsReviewPhrases as $phrase) {
            $rules[] = ['rule' => 'needs_review_phrase', 'phrase' => $phrase];
        }

        foreach ($boundedPhrases as $phrase) {
            $rules[] = ['rule' => 'allowed_bounded_phrase', 'phrase' => $phrase];
        }

        return $rules;
    }

    private function severity(string $lintState, string $surface, bool $isPublic, bool $isIndexable): string
    {
        if ($lintState === 'blocked' && $isPublic && $isIndexable) {
            return 'P0';
        }

        if ($lintState === 'blocked' && in_array($surface, ['seo_metadata', 'faq', 'llms', 'ai_answer', 'json_ld'], true)) {
            return 'P1';
        }

        if ($lintState === 'blocked' || $lintState === 'needs_review') {
            return 'P2';
        }

        return ($isPublic && $isIndexable) ? 'P3' : 'P3';
    }

    /**
     * @param  list<string>  $states
     */
    private function rollupState(array $states): string
    {
        if (in_array('blocked', $states, true)) {
            return 'blocked';
        }

        if (in_array('needs_review', $states, true)) {
            return 'needs_review';
        }

        return 'safe';
    }

    /**
     * @param  list<string>  $severities
     */
    private function rollupSeverity(array $severities): string
    {
        foreach (['P0', 'P1', 'P2', 'P3'] as $severity) {
            if (in_array($severity, $severities, true)) {
                return $severity;
            }
        }

        return 'P3';
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<mixed>
     */
    private function uniqueMerged(array $results, string $key): array
    {
        $items = [];

        foreach ($results as $result) {
            foreach ((array) ($result[$key] ?? []) as $item) {
                $items[] = is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $item;
            }
        }

        $items = array_values(array_unique(array_filter($items, static fn ($item): bool => $item !== null && $item !== false && $item !== '')));

        return array_map(static function ($item): mixed {
            if (! is_string($item) || ! str_starts_with($item, '{')) {
                return $item;
            }

            $decoded = json_decode($item, true);

            return is_array($decoded) ? $decoded : $item;
        }, $items);
    }
}
