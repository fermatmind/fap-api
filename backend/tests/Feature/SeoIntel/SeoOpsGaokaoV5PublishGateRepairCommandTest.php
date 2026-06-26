<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\SeoOpsGaokaoV5PublishGateRepairCommand;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SeoOpsGaokaoV5PublishGateRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(app(SeoOpsGaokaoV5PublishGateRepairCommand::class));
    }

    public function test_dry_run_plans_metadata_repair_without_writing(): void
    {
        [$package, $packageSha] = $this->packageFixture();
        $article = $this->draftArticle();
        $revision = $article->workingRevision;
        $artifactDir = storage_path('framework/testing/gaokao-publish-gate-dryrun');
        File::deleteDirectory($artifactDir);

        $this->artisan('seo-ops:gaokao-v5-publish-gate-repair', [
            '--package' => $package,
            '--confirm-package-sha256' => $packageSha,
            '--article' => (string) $article->id,
            '--revision-id' => (string) $revision?->id,
            '--translation-group-id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            '--expected-zh-slug' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
            '--reviewed-by' => '7',
            '--artifact-dir' => $artifactDir,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('status=planned')
            ->expectsOutputToContain('dry_run=1')
            ->assertExitCode(0);

        $freshRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail((int) $revision?->id);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $freshRevision->revision_status);
        $this->assertNull($freshRevision->approved_at);
        $this->assertSame(0, ArticleTagCount::forArticle((int) $article->id));
        $this->assertSame(0, (int) ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $article->id)->latest('id')->first()?->references_count);

        $evidenceFiles = glob($artifactDir.'/seo-ops-gaokao-v5-publish-gate-repair-*.json') ?: [];
        $this->assertCount(1, $evidenceFiles);
        $evidence = json_decode((string) file_get_contents($evidenceFiles[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('planned', $evidence['status'] ?? null);
        $this->assertSame(8, $evidence['planned_metadata_repairs']['faq_items_count'] ?? null);
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.cms_publish'));
    }

    public function test_execute_repairs_metadata_and_publish_dry_run_passes_with_make_indexable(): void
    {
        [$package, $packageSha] = $this->packageFixture();
        $article = $this->draftArticle();
        $revision = $article->workingRevision;
        $confirmation = "I explicitly approve SEO-OPS-GAOKAO-V5-PUBLISH-GATE-REPAIR-01 to repair draft metadata for article {$article->id} revision {$revision?->id} from package sha256 {$packageSha} reviewed_by 7; no publish, no URL Truth, no sitemap/llms, no schema/hreflang enablement, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.";

        $this->artisan('seo-ops:gaokao-v5-publish-gate-repair', [
            '--package' => $package,
            '--confirm-package-sha256' => $packageSha,
            '--article' => (string) $article->id,
            '--revision-id' => (string) $revision?->id,
            '--translation-group-id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            '--expected-zh-slug' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
            '--reviewed-by' => '7',
            '--artifact-dir' => storage_path('framework/testing/gaokao-publish-gate-execute'),
            '--execute' => true,
            '--confirm-repair' => $confirmation,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('status=success')
            ->assertExitCode(0);

        $article->refresh();
        $article->load(['workingRevision', 'seoMeta', 'tags']);

        $this->assertSame('draft', (string) $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertFalse((bool) $article->is_indexable);
        $this->assertFalse((bool) $article->sitemap_eligible);
        $this->assertFalse((bool) $article->llms_eligible);
        $this->assertNull($article->published_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, (string) $article->workingRevision?->revision_status);
        $this->assertSame(7, (int) $article->workingRevision?->reviewed_by);
        $this->assertNotNull($article->workingRevision?->reviewed_at);
        $this->assertNotNull($article->workingRevision?->approved_at);

        $import = ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $article->id)->latest('id')->firstOrFail();
        $this->assertSame(3, (int) $import->references_count);
        $this->assertSame('complete', (string) data_get($import->references_json, 'status'));
        $this->assertSame('complete', (string) data_get($import->graph_json, 'status'));
        $this->assertSame('complete', (string) data_get($import->answer_surface_json, 'status'));

        $this->assertGreaterThanOrEqual(7, $article->tags->count());
        $this->assertCount(2, (array) data_get($article->seoMeta?->schema_json, 'editorial_package_v1.cta_slots'));
        $this->assertCount(8, (array) data_get($article->seoMeta?->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
        $this->assertFalse((bool) data_get($article->seoMeta?->schema_json, 'editorial_package_v1.search_submission_allowed', true));

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--dry-run' => true,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=1')
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    public function test_execute_requires_exact_confirmation(): void
    {
        [$package, $packageSha] = $this->packageFixture();
        $article = $this->draftArticle();

        $this->artisan('seo-ops:gaokao-v5-publish-gate-repair', [
            '--package' => $package,
            '--confirm-package-sha256' => $packageSha,
            '--article' => (string) $article->id,
            '--revision-id' => (string) $article->workingRevision?->id,
            '--translation-group-id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            '--expected-zh-slug' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
            '--reviewed-by' => '7',
            '--artifact-dir' => storage_path('framework/testing/gaokao-publish-gate-blocked'),
            '--execute' => true,
            '--confirm-repair' => 'wrong',
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('confirmation_mismatch')
            ->assertExitCode(1);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function packageFixture(): array
    {
        $root = storage_path('framework/testing/gaokao-v5-package-'.StrRandom::next());
        File::ensureDirectoryExists($root.'/cms');
        File::ensureDirectoryExists($root.'/contracts');

        File::put($root.'/FAQ.zh-CN.json', json_encode([
            ['question' => '选专业父母不同意怎么办？', 'answer' => '先把担心和抗拒写进同一张表。'],
            ['question' => '父母让报热门专业怎么办？', 'answer' => '先把热门拆成课程、任务和行业周期。'],
            ['question' => '谁说了算？', 'answer' => '让每个专业接受同一套证据检查。'],
            ['question' => '孩子不喜欢怎么办？', 'answer' => '先说明抗拒的是课程、任务还是环境。'],
            ['question' => '霍兰德能帮选专业吗？', 'answer' => '它只能提出验证问题，不能替代官方信息。'],
            ['question' => '位次出来后怎么筛？', 'answer' => '先查位次、选科、招生计划和限制。'],
            ['question' => '就业稳定怎么比较？', 'answer' => '拆成毕业去向、行业周期和学习成本。'],
            ['question' => 'FermatMind 是官网吗？', 'answer' => '不是，正式填报以官方信息为准。'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        File::put($root.'/cms/CMS_FIELDS_zh-CN_gaokao-major-choice-parent-conflict-riasec-course-checklist.json', json_encode([
            'tag_suggestions' => ['高考志愿', '选专业', '霍兰德职业兴趣测试', 'RIASEC', 'MBTI', '家庭沟通', '专业验证清单'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        File::put($root.'/contracts/DYNAMIC_CTA_CONTRACT.json', json_encode([
            'primary' => '/zh/tests/holland-career-interest-test-riasec',
            'secondary' => '/zh/tests/mbti-personality-test-16-personality-types',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        File::put($root.'/contracts/INTERNAL_LINK_PLAN.json', json_encode([
            'links' => [
                ['href' => '/zh/tests/holland-career-interest-test-riasec', 'anchor' => '霍兰德职业兴趣测试', 'purpose' => 'primary CTA'],
                ['href' => '/zh/tests/mbti-personality-test-16-personality-types', 'anchor' => 'MBTI 测试', 'purpose' => 'secondary CTA'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [$root, $this->packageSha256($root)];
    }

    private function draftArticle(): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'gaokao-major-choice',
            'name' => '高考志愿',
            'is_active' => true,
        ]);
        $body = "## 选专业父母不同意怎么办\n\n正文。\n\n## 常见问题\n\n### Q\n\nA.";
        $bodyHash = hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));

        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            'title' => '选专业父母不同意怎么办',
            'excerpt' => '摘要',
            'content_md' => $body,
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/gaokao/hero_1600x900.jpg',
            'cover_image_alt' => '高考志愿决策地图',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => '选专业父母不同意怎么办',
            'excerpt' => '摘要',
            'content_md' => $body,
            'seo_title' => '选专业父母不同意怎么办？高考志愿三张清单 | FermatMind',
            'seo_description' => '高考志愿选专业时父母不同意，先别争谁对。',
        ]);
        $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => '选专业父母不同意怎么办？高考志愿三张清单 | FermatMind',
            'seo_description' => '高考志愿选专业时父母不同意，先别争谁对。',
            'canonical_url' => 'https://fermatmind.com/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist',
            'og_title' => '选专业父母不同意怎么办',
            'og_description' => '高考志愿选专业时父母不同意。',
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/gaokao/og_1200x630.jpg',
            'robots' => 'noindex,nofollow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'schema_hold' => true,
                    'hreflang_hold' => true,
                    'search_submission_allowed' => false,
                    'sensitivity_level' => 'career_sensitive',
                ],
            ],
            'is_indexable' => false,
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => 'zh-CN',
            'title' => '选专业父母不同意怎么办',
            'content_track' => 'Scenario Tool Page',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'review_pending',
            'claim_result_json' => ['status' => 'passed', 'matches' => []],
            'exactness_json' => ['status' => 'passed', 'body_hash' => $bodyHash],
            'references_json' => ['status' => 'operator_review_required'],
            'media_json' => ['status' => 'complete'],
            'graph_json' => ['status' => 'operator_review_required'],
            'answer_surface_json' => ['status' => 'visible_only'],
            'body_hash' => $bodyHash,
            'heading_sequence_json' => ['1:选专业父母不同意怎么办', '2:常见问题'],
            'references_count' => 0,
        ]);

        return $article->fresh(['workingRevision', 'seoMeta', 'tags']) ?? $article;
    }

    private function packageSha256(string $packageRoot): string
    {
        $files = collect(File::allFiles($packageRoot))
            ->filter(static fn ($file): bool => $file->isFile())
            ->map(static fn ($file): string => $file->getPathname())
            ->sort()
            ->values();

        $hashInput = '';
        foreach ($files as $file) {
            $relative = ltrim(str_replace($packageRoot, '', $file), '/');
            $hashInput .= $relative."\0".hash_file('sha256', $file)."\n";
        }

        return hash('sha256', $hashInput);
    }
}

final class StrRandom
{
    public static function next(): string
    {
        return bin2hex(random_bytes(4));
    }
}

final class ArticleTagCount
{
    public static function forArticle(int $articleId): int
    {
        return (int) DB::table('article_tag_map')->where('article_id', $articleId)->count();
    }
}
