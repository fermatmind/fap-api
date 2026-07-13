<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiFullComparisonImportDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_plans_all_20_chinese_comparison_assets_without_writes(): void
    {
        $this->seedPublishedIndexableTargets();
        [$atPackage, $crossPackage] = $this->writeApprovedPackages();

        $exitCode = Artisan::call('personality:mbti-full-comparison-import-dry-run', [
            '--at-package' => $atPackage,
            '--cross-type-package' => $crossPackage,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['ok']);
        self::assertSame('pass', $payload['status']);
        self::assertTrue($payload['dry_run_only']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertSame(20, $payload['record_count']);
        self::assertSame(16, $payload['at_comparison_count']);
        self::assertSame(4, $payload['cross_type_comparison_count']);
        self::assertSame(15, $payload['repair_record_count']);
        self::assertSame(5, $payload['verify_only_record_count']);
        self::assertCount(20, $payload['rows']);
        self::assertSame('/zh/personality/intj-a-vs-intj-t', $payload['rows'][0]['canonical_target']);
        self::assertTrue($payload['rows'][0]['content_mapping']['jsonld_source_fields']['faq_page']);
        self::assertSame(1, PersonalityProfileSection::query()->count());
        self::assertSame(4, MbtiCrossTypeComparisonAuthority::query()->count());

        $repeatExitCode = Artisan::call('personality:mbti-full-comparison-import-dry-run', [
            '--at-package' => $atPackage,
            '--cross-type-package' => $crossPackage,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $repeatPayload = $this->jsonOutput();

        self::assertSame(0, $repeatExitCode);
        self::assertSame($payload['idempotency_key'], $repeatPayload['idempotency_key']);
        self::assertSame(1, PersonalityProfileSection::query()->count());
        self::assertSame(4, MbtiCrossTypeComparisonAuthority::query()->count());
    }

    public function test_it_fails_closed_when_a_cross_type_target_is_not_indexable(): void
    {
        $this->seedPublishedIndexableTargets();
        MbtiCrossTypeComparisonAuthority::query()->where('slug', 'intj-vs-intp')->update(['is_indexable' => false]);
        [$atPackage, $crossPackage] = $this->writeApprovedPackages();

        $exitCode = Artisan::call('personality:mbti-full-comparison-import-dry-run', [
            '--at-package' => $atPackage,
            '--cross-type-package' => $crossPackage,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertContains('existing_target_not_indexable', array_column($payload['errors'], 'code'));
        self::assertSame(4, MbtiCrossTypeComparisonAuthority::query()->count());
    }

    public function test_it_rejects_invalid_quick_judgment_and_unsafe_links_without_writes(): void
    {
        $this->seedPublishedIndexableTargets();
        [$atPackage, $crossPackage] = $this->writeApprovedPackages();
        $package = json_decode((string) File::get($atPackage), true, 512, JSON_THROW_ON_ERROR);
        $package['assets'][0]['cms_fields']['quick_judgment_table'] = [];
        $package['assets'][0]['cms_fields']['internal_links'][0]['href'] = '/zh/result/secret';
        File::put($atPackage, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $exitCode = Artisan::call('personality:mbti-full-comparison-import-dry-run', [
            '--at-package' => $atPackage,
            '--cross-type-package' => $crossPackage,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertContains('quick_judgment_table_invalid', array_column($payload['errors'], 'code'));
        self::assertContains('unsafe_internal_link', array_column($payload['errors'], 'code'));
        self::assertSame(1, PersonalityProfileSection::query()->count());
    }

    public function test_it_refuses_write_mode_before_reading_packages(): void
    {
        $exitCode = Artisan::call('personality:mbti-full-comparison-import-dry-run', [
            '--at-package' => '/not/a/real/at-package.json',
            '--cross-type-package' => '/not/a/real/cross-package.json',
            '--write' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    private function seedPublishedIndexableTargets(): void
    {
        $profiles = [];
        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $profiles[$typeCode] = PersonalityProfile::query()->create([
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => $typeCode,
                'canonical_type_code' => $typeCode,
                'slug' => strtolower($typeCode),
                'locale' => 'zh-CN',
                'title' => $typeCode.' 人格',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => now()->subMinute(),
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ]);
        }
        PersonalityProfileSection::query()->create([
            'org_id' => 0,
            'profile_id' => $profiles['INTP']->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'INTP A/T 对比',
            'render_variant' => 'rich_text',
            'body_md' => 'Existing verified comparison.',
            'sort_order' => 920,
            'is_enabled' => true,
        ]);
        foreach ([
            ['intj-vs-intp', 'INTJ', 'INTP'],
            ['entj-vs-intj', 'ENTJ', 'INTJ'],
            ['infj-vs-infp', 'INFJ', 'INFP'],
            ['istj-vs-isfj', 'ISTJ', 'ISFJ'],
        ] as [$slug, $left, $right]) {
            MbtiCrossTypeComparisonAuthority::query()->create([
                'org_id' => 0,
                'locale' => 'zh-CN',
                'slug' => $slug,
                'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
                'left_type_code' => $left,
                'right_type_code' => $right,
                'title' => $left.' 与 '.$right.' 对比',
                'seo_title' => $left.' 与 '.$right.' 对比',
                'seo_description' => '已存在的公开比较内容。',
                'summary' => '已存在的公开比较内容。',
                'content_payload_json' => ['source' => 'fixture'],
                'review_status' => 'approved',
                'publish_status' => 'published',
                'indexability_status' => 'indexable',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'search_submission_eligible' => true,
                'published_at' => now()->subMinute(),
                'imported_at' => now()->subMinute(),
            ]);
        }
    }

    /** @return array{0:string,1:string} */
    private function writeApprovedPackages(): array
    {
        $atAssets = [];
        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $atAssets[] = $this->atAsset($typeCode, $typeCode === 'INTP');
        }
        $crossAssets = [];
        foreach ([
            ['intj-vs-intp', 'INTJ', 'INTP'],
            ['entj-vs-intj', 'ENTJ', 'INTJ'],
            ['infj-vs-infp', 'INFJ', 'INFP'],
            ['istj-vs-isfj', 'ISTJ', 'ISFJ'],
        ] as [$slug, $left, $right]) {
            $crossAssets[] = $this->crossAsset($slug, $left, $right);
        }
        $atPath = sys_get_temp_dir().'/mbti-cms-comp-38-at-'.bin2hex(random_bytes(4)).'.json';
        $crossPath = sys_get_temp_dir().'/mbti-cms-comp-38-cross-'.bin2hex(random_bytes(4)).'.json';
        File::put($atPath, (string) json_encode(['artifact' => 'MBTI-COMP-AT-35-CONTENT-ASSETS', 'assets' => $atAssets], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($crossPath, (string) json_encode(['artifact' => 'MBTI-CMS-06-COMPARISON-CONTENT-ASSETS', 'assets' => $crossAssets], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return [$atPath, $crossPath];
    }

    /** @return array<string,mixed> */
    private function atAsset(string $typeCode, bool $verifyOnly): array
    {
        $slug = strtolower($typeCode).'-a-vs-'.strtolower($typeCode).'-t';
        $cms = $verifyOnly ? null : $this->atCms($typeCode);

        return [
            'asset_id' => 'MBTI-COMP-AT-35:'.$slug,
            'framework' => 'mbti64',
            'page_type' => 'at_comparison',
            'locale' => 'zh',
            'path' => '/zh/personality/'.$slug,
            'comparison_pair' => ['left' => $typeCode.'-A', 'right' => $typeCode.'-T'],
            'audit_status' => $verifyOnly ? 'verify_only' : 'needs_content_repair',
            'source_refs' => ['fixture-'.$slug],
            'handoff_policy' => ['artifact_only' => true, 'cms_write_attempted' => false, 'production_import_attempted' => false, 'frontend_runtime_change_attempted' => false, 'sitemap_llms_mutation_attempted' => false, 'gsc_mutation_attempted' => false, 'production_deploy_attempted' => false],
            'cms_fields' => $cms,
        ];
    }

    /** @return array<string,mixed> */
    private function crossAsset(string $slug, string $left, string $right): array
    {
        return [
            'asset_id' => 'MBTI-CMS-06:/zh/personality/'.$slug,
            'framework' => 'mbti_comparison',
            'page_type' => 'hot_comparison',
            'locale' => 'zh-CN',
            'path' => '/zh/personality/'.$slug,
            'comparison_pair' => ['left' => $left, 'right' => $right],
            'handoff_policy' => ['cms_review_required' => true, 'cms_write_attempted' => false, 'production_import_attempted' => false, 'db_migration_attempted' => false, 'frontend_runtime_change_attempted' => false, 'frontend_local_editorial_fallback_added' => false],
            'cms_fields' => $this->crossCms($left, $right),
        ];
    }

    /** @return array<string,mixed> */
    private function atCms(string $typeCode): array
    {
        $sections = [];
        foreach (['biggest_difference', 'quick_judgment_table', 'easy_misread', 'work_scenarios', 'relationship_scenarios', 'stress_scenarios', 'do_not_misjudge'] as $key) {
            $sections[] = ['key' => $key, 'title' => $key, 'body' => $key.' 的可见说明。'];
        }

        return [
            'title' => $typeCode.'-A 和 '.$typeCode.'-T 的区别', 'h1' => $typeCode.' A/T 对比', 'meta_description' => 'A/T 对比的最大区别、快速判断与 FAQ。', 'direct_answer' => 'A/T 差异用于自我理解，不用于诊断或筛选。',
            'sections' => $sections,
            'quick_judgment_table' => array_map(static fn (string $dimension): array => ['dimension' => $dimension, 'a' => 'A 的说明', 't' => 'T 的说明'], ['最大区别', '压力反应', '工作场景', '容易误判']),
            'faq' => array_map(static fn (int $index): array => ['question' => 'A/T 问题 '.$index, 'answer' => 'A/T 回答 '.$index], range(1, 5)),
            'internal_links' => $this->links(),
        ];
    }

    /** @return array<string,mixed> */
    private function crossCms(string $left, string $right): array
    {
        $modules = [];
        foreach (['biggest_difference', 'quick_judgment_table', 'easy_misread', 'real_scenario_differences', 'do_not_misjudge', 'faq'] as $key) {
            $modules[] = ['key' => $key, 'required' => true, 'body' => $key.' 的可见说明。'];
        }

        return [
            'title' => $left.' 和 '.$right.' 的区别', 'h1' => $left.' 和 '.$right.' 的区别', 'meta_description' => '跨类型比较的快速判断与 FAQ。', 'answer_block' => '跨类型差异用于自我理解，不用于诊断或筛选。',
            'modules' => $modules,
            'quick_judgment_table' => array_map(static fn (string $dimension): array => ['dimension' => $dimension, 'left' => '左侧说明', 'right' => '右侧说明'], ['最大区别', '信息入口', '容易误判', '使用边界']),
            'faq' => array_map(static fn (int $index): array => ['question' => '跨类型问题 '.$index, 'answer' => '跨类型回答 '.$index], range(1, 5)),
            'internal_links' => $this->links(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function links(): array
    {
        return [
            ['href' => '/zh/personality', 'safe_public_route' => true],
            ['href' => '/zh/tests/mbti-personality-test-16-personality-types', 'safe_public_route' => true],
            ['href' => '/zh/personality/intj-a', 'safe_public_route' => true],
            ['href' => '/zh/personality/intp-a', 'safe_public_route' => true],
            ['href' => '/zh/personality/intj-a-vs-intj-t', 'safe_public_route' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
