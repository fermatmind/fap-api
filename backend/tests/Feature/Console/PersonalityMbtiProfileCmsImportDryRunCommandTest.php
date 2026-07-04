<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiProfileCmsImportDryRunCommandTest extends TestCase
{
    public function test_profile_dry_run_plans_variant_rows_without_writes(): void
    {
        $packagePath = $this->writePackage($this->validProfilePackage());

        $exitCode = Artisan::call('personality:mbti-profile-cms-import-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $row = $payload['rows'][0] ?? [];

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
        $this->assertSame(2, $payload['profile_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame('variant', $row['page_type'] ?? null);
        $this->assertSame('INTP-A', $row['identity']['runtime_type_code'] ?? null);
        $this->assertSame('App\\Models\\PersonalityProfileVariantRevision', $row['target']['target_model'] ?? null);
        $this->assertSame('personality_profile_variant_revisions', $row['target']['target_table'] ?? null);
        $this->assertSame('mbti_cms_12_profile_import_dry_run_v1', $row['draft_revision']['snapshot_key'] ?? null);
        $this->assertSame('not_supported', $row['write_mode_in_this_pr'] ?? null);
        $this->assertContains('personality_profile_variant_sections', $payload['field_mapping_contract']['target_tables']);
        $this->assertContains('personality_profile_variant_seo_meta', $payload['field_mapping_contract']['target_tables']);
    }

    public function test_comparison_rows_are_rejected_for_profile_scope(): void
    {
        $package = $this->validProfilePackage();
        $package['rows'][] = $this->row('/zh/personality/entj-vs-intj', 'zh-CN', 'comparison');
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti-profile-cms-import-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(1, $payload['comparison_row_count']);
        $this->assertContains('comparison_rows_out_of_scope', $codes);
    }

    public function test_command_requires_explicit_dry_run(): void
    {
        $packagePath = $this->writePackage($this->validProfilePackage());

        $exitCode = Artisan::call('personality:mbti-profile-cms-import-dry-run', [
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
        $packagePath = $this->writePackage($this->validProfilePackage());

        $exitCode = Artisan::call('personality:mbti-profile-cms-import-dry-run', [
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
    private function validProfilePackage(): array
    {
        return [
            'artifact' => 'mbti-profile-cms-import-dry-run-fixture',
            'version' => 'cms12-fixture-v1',
            'status' => 'ready_for_dry_run',
            'content_line' => 'profile',
            'rows' => [
                $this->row('/zh/personality/intp-a', 'zh-CN', 'variant'),
                $this->row('/en/personality/intj-t', 'en', 'variant'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $url, string $locale, string $pageType): array
    {
        $isZh = str_starts_with($url, '/zh/');

        return [
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'canonical_target' => $url,
            'primary_query' => $isZh ? 'intp-a 人格' : 'intj-t personality',
            'secondary_queries' => $isZh ? ['intp a', 'intp-a 适合职业'] : ['intj t', 'intj-t careers'],
            'gsc_query_evidence' => [
                ['query' => $isZh ? 'intp a' : 'intj t', 'clicks' => 1, 'impressions' => 10],
            ],
            'target_intent' => 'profile explanation',
            'method_boundary' => 'Personality content is an educational self-understanding aid, not diagnosis.',
            'trademark_boundary' => 'MBTI-related terms are used descriptively.',
            'claim_risk_notes' => ['Avoid deterministic hiring or medical claims.'],
            'qa_flags_for_codex' => ['Keep answer block direct and non-diagnostic.'],
            'route_safety' => ['forbidden_route_patterns_absent_from_internal_links' => true],
            'source_document' => 'fixture',
            'status' => 'draft_for_cms_dry_run',
            'seo' => [
                'seo_title' => $isZh ? 'INTP-A 人格特点' : 'INTJ-T Personality Profile',
                'seo_description' => $isZh ? '了解 INTP-A 的定义、适合场景、常见误解、职业、关系和压力表现。' : 'Learn the INTJ-T definition, fit, misconceptions, work, relationships, and stress patterns.',
                'breadcrumb_title' => $isZh ? 'INTP-A' : 'INTJ-T',
                'h1' => $isZh ? 'INTP-A 人格特点' : 'INTJ-T Personality Profile',
                'quick_answer_summary' => $isZh ? 'INTP-A 是更自信稳定的逻辑探索型人格变体。' : 'INTJ-T is a more self-questioning strategic planner variant.',
            ],
            'content' => [
                'quick_answer' => $isZh ? 'INTP-A 通常以独立分析和稳定自评为核心。' : 'INTJ-T often combines strategy with active self-correction.',
                'definition' => $isZh ? '定义说明。' : 'Definition.',
                'suitable_for' => $isZh ? '适合需要独立分析的人。' : 'People who need strategic self-review.',
                'not_suitable_for' => $isZh ? '不适合作为诊断或招聘筛选。' : 'Not for diagnosis or hiring screens.',
                'common_misconception' => $isZh ? '常见误解是把类型当成能力证明。' : 'A common misconception is treating type as proof of ability.',
                'base_type_difference' => $isZh ? '与基础 INTP 相比，A/T 说明自我确认方式。' : 'Compared with base INTJ, A/T describes self-assurance style.',
                'at_difference' => $isZh ? 'A 更稳定自信，T 更容易复盘修正。' : 'A is steadier; T is more self-correcting.',
                'career_scenarios' => $isZh ? '适合研究、系统分析和产品判断。' : 'Useful in planning, research, and systems work.',
                'relationship_scenarios' => $isZh ? '关系中需要清晰边界和解释空间。' : 'Relationships benefit from clear expectations.',
                'stress_scenarios' => $isZh ? '压力下可能过度推演。' : 'Under stress, over-analysis can increase.',
            ],
            'faq' => [
                ['question' => $isZh ? 'INTP-A 是什么意思？' : 'What does INTJ-T mean?', 'answer' => $isZh ? '它是 INTP 的 A 型变体。' : 'It is the turbulent INTJ variant.'],
                ['question' => $isZh ? 'INTP-A 适合什么职业？' : 'Is INTJ-T a diagnosis?', 'answer' => $isZh ? '适合分析、研究和系统类工作。' : 'No, it is an educational personality label.'],
            ],
            'internal_links' => [
                ['href' => $isZh ? '/zh/personality' : '/en/personality', 'anchor_text' => 'MBTI hub', 'role' => 'hub', 'safe_public_route' => true],
                ['href' => $isZh ? '/zh/tests/mbti-personality-test-16-personality-types' : '/en/tests/mbti-personality-test-16-personality-types', 'anchor_text' => 'MBTI test', 'role' => 'test', 'safe_public_route' => true],
                ['href' => $isZh ? '/zh/personality/intp-a-vs-intp-t' : '/en/personality/intj-a-vs-intj-t', 'anchor_text' => 'A/T comparison', 'role' => 'comparison', 'safe_public_route' => true],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/mbti-cms12-profile-'.bin2hex(random_bytes(6)).'.json';
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
