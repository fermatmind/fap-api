<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiComparisonCmsImportDryRunCommandTest extends TestCase
{
    public function test_comparison_dry_run_plans_at_and_cross_type_rows_without_writes(): void
    {
        $packagePath = $this->writePackage($this->validComparisonPackage());

        $exitCode = Artisan::call('personality:mbti-comparison-cms-import-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $atRow = $payload['rows'][0] ?? [];
        $crossTypeRow = $payload['rows'][1] ?? [];

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run_only']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertSame(2, $payload['row_count']);
        $this->assertSame(2, $payload['comparison_row_count']);
        $this->assertSame(0, $payload['profile_row_count']);
        $this->assertSame(1, $payload['at_comparison_count']);
        $this->assertSame(1, $payload['cross_type_comparison_count']);

        $this->assertSame('at', $atRow['identity']['comparison_kind'] ?? null);
        $this->assertSame('INTJ', $atRow['identity']['base_type_code'] ?? null);
        $this->assertSame('INTJ-A', $atRow['identity']['left_type_code'] ?? null);
        $this->assertSame('INTJ-T', $atRow['identity']['right_type_code'] ?? null);
        $this->assertSame('personality_profile_sections', $atRow['target']['target_table'] ?? null);
        $this->assertSame('mbti64_comparison_a_vs_t', $atRow['target']['section_key'] ?? null);
        $this->assertSame('not_supported', $atRow['write_mode_in_this_pr'] ?? null);

        $this->assertSame('cross_type', $crossTypeRow['identity']['comparison_kind'] ?? null);
        $this->assertSame('ENTJ', $crossTypeRow['identity']['left_type_code'] ?? null);
        $this->assertSame('INTJ', $crossTypeRow['identity']['right_type_code'] ?? null);
        $this->assertSame('mbti_cross_type_comparison_authorities', $crossTypeRow['target']['target_table'] ?? null);
        $this->assertSame('entj-vs-intj', $crossTypeRow['target']['lookup']['comparison_slug'] ?? null);
        $this->assertContains('personality_profile_sections', $payload['field_mapping_contract']['target_tables']);
        $this->assertContains('mbti_cross_type_comparison_authorities', $payload['field_mapping_contract']['target_tables']);
        $this->assertContains('comparison_public_projection_v1', $payload['field_mapping_contract']['target_tables']);
    }

    public function test_profile_rows_are_rejected_for_comparison_scope(): void
    {
        $package = $this->validComparisonPackage();
        $package['rows'][] = $this->row('/zh/personality/intp-a', 'zh-CN', 'variant', 'profile');
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti-comparison-cms-import-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(1, $payload['profile_row_count']);
        $this->assertContains('profile_rows_out_of_scope', $codes);
    }

    public function test_forbidden_private_routes_and_sensitive_query_keys_fail_closed(): void
    {
        $package = $this->validComparisonPackage();
        $package['rows'][0]['internal_links'][] = [
            'href' => '/zh/result/private?token=secret',
            'anchor_text' => 'private result',
            'role' => 'forbidden',
            'safe_public_route' => false,
        ];
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti-comparison-cms-import-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_public_route_pattern_present', $codes);
        $this->assertContains('forbidden_query_pattern_present', $codes);
    }

    public function test_command_requires_explicit_dry_run(): void
    {
        $packagePath = $this->writePackage($this->validComparisonPackage());

        $exitCode = Artisan::call('personality:mbti-comparison-cms-import-dry-run', [
            '--package' => $packagePath,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('runtime_error', $payload['errors'][0]['code'] ?? null);
        $this->assertStringContainsString('--dry-run is required', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    public function test_command_refuses_write_mode(): void
    {
        $packagePath = $this->writePackage($this->validComparisonPackage());

        $exitCode = Artisan::call('personality:mbti-comparison-cms-import-dry-run', [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function validComparisonPackage(): array
    {
        return [
            'artifact' => 'mbti-comparison-cms-import-dry-run-fixture',
            'version' => 'cms13-fixture-v1',
            'status' => 'ready_for_dry_run',
            'content_line' => 'comparison',
            'rows' => [
                $this->row('/zh/personality/intj-a-vs-intj-t', 'zh-CN', 'comparison', 'at'),
                $this->row('/en/personality/entj-vs-intj', 'en', 'comparison', 'cross_type'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $url, string $locale, string $pageType, string $comparisonKind): array
    {
        $isZh = str_starts_with($url, '/zh/');

        return [
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'comparison_kind' => $comparisonKind,
            'canonical_target' => $url,
            'primary_query' => $isZh ? 'intj-a vs intj-t' : 'entj vs intj',
            'secondary_queries' => $isZh ? ['intj-a 和 intj-t 区别', 'intj-a intj-t'] : ['entj and intj difference', 'entj intj comparison'],
            'gsc_query_evidence' => [
                ['query' => $isZh ? 'intj-a vs intj-t' : 'entj vs intj', 'clicks' => 1, 'impressions' => 12],
            ],
            'target_intent' => 'comparison explanation',
            'method_boundary' => 'Personality comparisons are educational self-understanding aids, not diagnosis.',
            'trademark_boundary' => 'MBTI-related terms are used descriptively.',
            'claim_risk_notes' => ['Avoid deterministic hiring or medical claims.'],
            'qa_flags_for_codex' => ['Keep answer block direct and non-diagnostic.'],
            'route_safety' => ['forbidden_route_patterns_absent_from_internal_links' => true],
            'source_document' => 'fixture',
            'status' => 'draft_for_cms_dry_run',
            'seo' => [
                'seo_title' => $isZh ? 'INTJ-A 和 INTJ-T 的区别' : 'ENTJ vs INTJ: Key Differences',
                'seo_description' => $isZh ? '快速理解 INTJ-A 与 INTJ-T 的最大区别、判断表、误判原因、真实场景和 FAQ。' : 'Compare ENTJ and INTJ by key difference, quick judgment table, common confusion, real situations, and FAQ.',
                'breadcrumb_title' => $isZh ? 'INTJ-A vs INTJ-T' : 'ENTJ vs INTJ',
                'h1' => $isZh ? 'INTJ-A 和 INTJ-T 的区别' : 'ENTJ vs INTJ',
                'quick_answer_summary' => $isZh ? 'INTJ-A 更稳定自信，INTJ-T 更容易复盘修正。' : 'ENTJ tends to organize outward action; INTJ tends to build internal strategy first.',
            ],
            'content' => [
                'quick_answer' => $isZh ? 'INTJ-A 与 INTJ-T 的核心差异在自我确认和压力复盘方式。' : 'ENTJ and INTJ differ most in how strategy becomes action.',
                'max_difference' => $isZh ? '最大区别是 A 更稳定确认，T 更敏感复盘。' : 'The biggest difference is outward mobilization versus internal modeling.',
                'quick_judgment_table' => [
                    ['signal' => $isZh ? '压力下' : 'under pressure', 'left' => $isZh ? '保持稳定判断' : 'mobilizes others', 'right' => $isZh ? '反复校准' : 'rechecks the model'],
                    ['signal' => $isZh ? '决策时' : 'when deciding', 'left' => $isZh ? '更少摇摆' : 'moves quickly', 'right' => $isZh ? '更重验证' : 'validates first'],
                ],
                'confusion_reason' => $isZh ? '两者都偏长期规划，所以容易只看表面标签。' : 'Both can look strategic, so users often confuse the surface.',
                'real_scene_differences' => $isZh ? '在团队压力、关系沟通和学习规划里差异更明显。' : 'The gap appears in teams, communication, and planning scenes.',
                'misjudgment_warning' => $isZh ? '不要把类型差异误判为能力高低。' : 'Do not treat type differences as ability rankings.',
            ],
            'faq' => [
                ['question' => $isZh ? 'INTJ-A 和 INTJ-T 最大区别是什么？' : 'What is the biggest ENTJ vs INTJ difference?', 'answer' => $isZh ? '最大区别是自我确认和压力复盘方式。' : 'The biggest difference is how strategy turns into action.'],
                ['question' => $isZh ? 'INTJ-A 比 INTJ-T 更好吗？' : 'Is ENTJ better than INTJ?', 'answer' => $isZh ? '不是，它们只是压力与自评风格不同。' : 'No, they are different strategy styles.'],
                ['question' => $isZh ? '这个对比能用于招聘吗？' : 'Can this comparison be used for hiring?', 'answer' => $isZh ? '不能，它只能作为自我理解参考。' : 'No, it is only for self-understanding.'],
            ],
            'internal_links' => [
                ['href' => $isZh ? '/zh/personality' : '/en/personality', 'anchor_text' => 'MBTI hub', 'role' => 'hub', 'safe_public_route' => true],
                ['href' => $isZh ? '/zh/tests/mbti-personality-test-16-personality-types' : '/en/tests/mbti-personality-test-16-personality-types', 'anchor_text' => 'MBTI test', 'role' => 'test', 'safe_public_route' => true],
                ['href' => $isZh ? '/zh/personality/intj-a' : '/en/personality/entj', 'anchor_text' => 'related profile', 'role' => 'profile', 'safe_public_route' => true],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/mbti-cms13-comparison-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
