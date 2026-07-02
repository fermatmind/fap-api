<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecContentScienceBoundaryLintTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_SECTIONS = [
        'hero_activity_chain',
        'six_dimension_map',
        'pair_blend',
        'activity_explorer',
        'occupation_examples',
        '140q_cta',
        '140q_context_cards',
        'share_card',
        'pdf',
        'history',
        'feedback_overlay',
        'dimension_deep_copy',
        'pair_blend_copy',
        '140q_layer_copy',
        'quality_copy',
        'structural_difference_copy',
        'aspirations_copy',
        'feedback_response_copy',
    ];

    /** @var list<string> */
    private const REQUIRED_BOUNDARIES = [
        'interest_evidence_only',
        'not_personality_identity',
        'not_ability_or_skill_measure',
        'not_career_recommendation',
        'not_job_fit',
        'not_success_prediction',
        'not_hiring_or_screening_use',
        'examples_not_matches',
        'no_60q_140q_raw_delta',
        '140q_contextual_not_more_accurate',
        'feedback_does_not_mutate_measured_result',
        'missing_content_fails_closed',
        'frontend_fallback_forbidden',
    ];

    /** @var array<string,string> */
    private const POSITIVE_HIGH_RISK_PATTERNS = [
        'best_career' => '/最佳职业|最适合的职业|推荐职业|职业推荐/u',
        'job_fit' => '/岗位匹配|职业匹配|岗位胜任|匹配度|适合度/u',
        'success_prediction' => '/成功概率|职业成功|保证成功|一定成功/u',
        'deterministic_identity' => '/天生适合|你就是|最终答案|注定/u',
        'form_override' => '/140Q\s*更准确|140题更准确|推翻\s*60Q|60Q错了|60题错了/iu',
    ];

    public function test_science_boundary_registry_covers_all_authorized_sections(): void
    {
        $registry = $this->registry();

        $this->assertSame('riasec.content_science_boundary_lint.v1', $registry['schema_version']);
        $this->assertSame('RIASEC', $registry['scale_code']);
        $this->assertSame('backend_content_assets', $registry['content_authority']);
        $this->assertFalse(data_get($registry, 'runtime_behavior.frontend_fallback_allowed'));
        $this->assertFalse(data_get($registry, 'runtime_behavior.cms_write_allowed'));
        $this->assertFalse(data_get($registry, 'runtime_behavior.production_import_allowed'));
        $this->assertFalse(data_get($registry, 'runtime_behavior.production_deploy_allowed'));

        $sections = array_column((array) $registry['sections'], 'section_key');
        sort($sections);

        $expected = self::REQUIRED_SECTIONS;
        sort($expected);

        $this->assertSame($expected, $sections);
    }

    public function test_science_boundary_registry_declares_required_boundaries_and_forbidden_claims(): void
    {
        $registry = $this->registry();

        foreach (self::REQUIRED_BOUNDARIES as $boundary) {
            $this->assertContains($boundary, $registry['required_boundaries']);
        }

        foreach ([
            'career_match',
            'job_fit',
            'success_prediction',
            'ability_or_skill_inference',
            'personality_identity',
            '140q_more_accurate',
            'feedback_changes_scores',
            'cms_or_frontend_runtime_authority',
        ] as $claim) {
            $this->assertContains($claim, $registry['positive_claims_forbidden']);
        }
    }

    public function test_science_boundary_registry_maps_current_top_level_assets(): void
    {
        $registry = $this->registry();
        $listedAssets = (array) $registry['asset_inventory'];

        foreach ($this->topLevelRiasecAssets() as $asset) {
            $this->assertContains($asset, $listedAssets, "{$asset} must be explicitly mapped before section repair");
        }

        foreach ((array) $registry['sections'] as $section) {
            $this->assertNotEmpty($section['primary_assets'], $section['section_key'].' must name source assets');
            $this->assertNotEmpty($section['service_files'], $section['section_key'].' must name backend service files');
            $this->assertNotEmpty($section['risk'], $section['section_key'].' must record the risk being repaired');
            $this->assertNotEmpty($section['repair_goal'], $section['section_key'].' must record the repair goal');
        }
    }

    public function test_positive_claim_matcher_distinguishes_negated_boundaries_from_positive_claims(): void
    {
        $this->assertFalse($this->containsPositiveHighRiskClaim(
            '140Q 更具体地观察任务、环境和角色责任，不推翻 60Q，也不比较不同表单原始分。',
            self::POSITIVE_HIGH_RISK_PATTERNS['form_override'],
        ));
        $this->assertFalse($this->containsPositiveHighRiskClaim(
            '这不是岗位胜任依据，也不输出职业推荐。',
            self::POSITIVE_HIGH_RISK_PATTERNS['job_fit'],
        ));
        $this->assertTrue($this->containsPositiveHighRiskClaim(
            '这个结果说明你岗位胜任，职业匹配度很高。',
            self::POSITIVE_HIGH_RISK_PATTERNS['job_fit'],
        ));
        $this->assertTrue($this->containsPositiveHighRiskClaim(
            '140Q 更准确，会推翻 60Q。',
            self::POSITIVE_HIGH_RISK_PATTERNS['form_override'],
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function registry(): array
    {
        $path = base_path('docs/riasec/content-science-boundary-lint-v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function topLevelRiasecAssets(): array
    {
        $base = base_path('content_assets/riasec');
        $assets = [];

        foreach (glob($base.'/*.{json,jsonl}', GLOB_BRACE) ?: [] as $path) {
            $assets[] = 'content_assets/riasec/'.basename($path);
        }

        sort($assets);

        return $assets;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assetRecords(string $relativePath): array
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        if (str_ends_with($path, '.jsonl')) {
            $records = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $records[] = $decoded;
            }

            return $records;
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return [$decoded];
    }

    private function containsPositiveHighRiskClaim(string $text, string $pattern): bool
    {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($matches[0] as [$match, $offset]) {
            if (! $this->isNegatedBoundaryMention($text, (string) $match, (int) $offset)) {
                return true;
            }
        }

        return false;
    }

    private function isNegatedBoundaryMention(string $text, string $match, int $offset): bool
    {
        $prefix = mb_substr(substr($text, 0, $offset), -14);
        $window = $prefix.$match;

        foreach (['不', '不是', '不能', '不得', '不可', '不应', '不要', '不会', '不代表', '不作为', '不等于', '非'] as $negation) {
            if (str_contains($window, $negation)) {
                return true;
            }
        }

        return false;
    }
}
