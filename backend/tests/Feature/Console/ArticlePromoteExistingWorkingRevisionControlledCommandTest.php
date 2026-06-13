<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticlePromoteExistingWorkingRevisionControlledCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'article-4';

    private const SLUG = 'eq-test-tool-guide';

    private const CANONICAL = '/zh/articles/eq-test-tool-guide';

    public function test_dry_run_accepts_approved_existing_article_working_revision_without_writes(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $publishedRevisionId = (int) $article->published_revision_id;
        $workingRevisionId = (int) $article->working_revision_id;

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_promote_existing_working_revision', $payload['action']);
        $this->assertSame($this->expectedConfirmation((int) $article->id, $workingRevisionId), $payload['expected_confirmation']);
        $this->assertSame($workingRevisionId, (int) data_get($payload, 'plan.working_revision_id'));
        $this->assertSame($publishedRevisionId, (int) data_get($payload, 'plan.published_revision_id'));
        $this->assertSame('approved', (string) data_get($payload, 'plan.working_revision_status'));
        $this->assertContains('references_operator_review_hold', array_map(
            static fn (array $warning): string => (string) ($warning['code'] ?? ''),
            data_get($payload, 'plan.warnings', [])
        ));

        $article->refresh();
        $this->assertSame('Published EQ article', (string) $article->title);
        $this->assertSame($publishedRevisionId, (int) $article->published_revision_id);
        $this->assertSame($workingRevisionId, (int) $article->working_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, (string) $article->workingRevision?->revision_status);
    }

    public function test_execute_promotes_existing_article_working_revision_and_preserves_route_state(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $previousPublishedRevisionId = (int) $article->published_revision_id;
        $workingRevisionId = (int) $article->working_revision_id;

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--execute' => true,
            '--confirm' => $this->expectedConfirmation((int) $article->id, $workingRevisionId),
            '--preview-approved' => true,
            '--schema-hold' => true,
            '--hreflang-hold' => true,
            '--search-hold' => true,
            '--no-revalidation' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame((int) $article->id, (int) $payload['promoted_article_id']);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
            ->findOrFail((int) $article->id);

        $this->assertSame(self::SLUG, (string) $article->slug);
        $this->assertSame(self::TRANSLATION_GROUP_ID, (string) $article->translation_group_id);
        $this->assertSame('published', (string) $article->status);
        $this->assertTrue((bool) $article->is_public);
        $this->assertTrue((bool) $article->is_indexable);
        $this->assertTrue((bool) $article->sitemap_eligible);
        $this->assertTrue((bool) $article->llms_eligible);
        $this->assertSame($workingRevisionId, (int) $article->published_revision_id);
        $this->assertSame($workingRevisionId, (int) $article->working_revision_id);
        $this->assertSame('EQ测试怎么用：从分数到情绪调节的完整指南', (string) $article->title);
        $this->assertSame('更新后的 EQ 正文摘要', (string) $article->excerpt);
        $this->assertStringContainsString('## EQ 分数应该怎么理解', (string) $article->content_md);
        $this->assertNull($article->content_html);

        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $article->workingRevision?->revision_status);
        $this->assertSame($workingRevisionId, (int) $article->publishedRevision?->id);
        $this->assertNotSame($previousPublishedRevisionId, (int) $article->published_revision_id);
        $this->assertSame('EQ测试怎么用：从分数到情绪调节的完整指南 | FermatMind', (string) $article->seoMeta?->seo_title);
        $this->assertSame('更新后的 EQ SEO 描述。', (string) $article->seoMeta?->seo_description);
        $this->assertSame('https://fermatmind.com'.self::CANONICAL, (string) $article->seoMeta?->canonical_url);
        $this->assertSame('https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg', (string) $article->seoMeta?->og_image_url);
        $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
    }

    public function test_dry_run_rejects_human_review_revision(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision([
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'revision_not_editorially_approved');
    }

    public function test_execute_requires_confirmation_preview_and_downstream_hold_flags(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--execute' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'confirmation_mismatch');
        $this->assertErrorCode($payload, 'preview_approval_required');
        $this->assertErrorCode($payload, 'required_hold_flag_missing');
    }

    public function test_dry_run_rejects_published_revision_lock_mismatch(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--current-published-revision-id' => ((int) $article->published_revision_id) + 999,
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'published_revision_lock_mismatch');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandOptions(Article $article, array $overrides = []): array
    {
        return array_replace([
            '--article-id' => (int) $article->id,
            '--working-revision-id' => (int) $article->working_revision_id,
            '--current-published-revision-id' => (int) $article->published_revision_id,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--expected-slug' => self::SLUG,
            '--expected-canonical' => self::CANONICAL,
        ], $overrides);
    }

    private function expectedConfirmation(int $articleId, int $workingRevisionId): string
    {
        return "I explicitly approve Codex to promote article id {$articleId} working revision {$workingRevisionId} after preflight passes.";
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createExistingArticleWithWorkingRevision(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'emotional-intelligence'],
            ['name' => '情绪智能', 'is_active' => true]
        );
        $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'eq'],
            ['name' => 'EQ', 'is_active' => true]
        );
        $publishedBody = "## Existing EQ article\n\n旧正文。";
        $workingBody = "## EQ 分数应该怎么理解\n\nEQ 分数是情绪线索，不是能力判决。\n\n## 下一步\n\n[开始 EQ 测试](/zh/tests/eq-test-emotional-intelligence-assessment)";
        $workingStatus = (string) ($overrides['working_revision_status'] ?? ArticleTranslationRevision::STATUS_APPROVED);

        $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Published EQ article',
            'excerpt' => 'Published EQ excerpt',
            'content_md' => $publishedBody,
            'content_html' => '<p>old html</p>',
            'cover_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'EQ测试与情绪理解流程示意图',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]));
        $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

        $publishedRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Published EQ article',
            'excerpt' => 'Published EQ excerpt',
            'content_md' => $publishedBody,
            'seo_title' => 'Published EQ SEO',
            'seo_description' => 'Published EQ SEO description',
            'published_at' => now()->subDay(),
        ]);

        $workingRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 2,
            'revision_status' => $workingStatus,
            'title' => 'EQ测试怎么用：从分数到情绪调节的完整指南',
            'excerpt' => '更新后的 EQ 正文摘要',
            'content_md' => $workingBody,
            'seo_title' => 'EQ测试怎么用：从分数到情绪调节的完整指南 | FermatMind',
            'seo_description' => '更新后的 EQ SEO 描述。',
            'reviewed_by' => $overrides['reviewed_by'] ?? 7,
            'reviewed_at' => array_key_exists('reviewed_at', $overrides) ? $overrides['reviewed_at'] : now()->subMinutes(10),
            'approved_at' => array_key_exists('approved_at', $overrides) ? $overrides['approved_at'] : now()->subMinutes(5),
            'supersedes_revision_id' => (int) $publishedRevision->id,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $workingRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Published EQ SEO',
            'seo_description' => 'Published EQ SEO description',
            'canonical_url' => 'https://fermatmind.com'.self::CANONICAL,
            'og_title' => 'Published EQ OG',
            'og_description' => 'Published EQ OG description',
            'og_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg',
            'robots' => 'index,follow',
            'schema_json' => ['status' => 'existing_public_schema'],
            'is_indexable' => true,
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'title' => 'EQ测试怎么用：从分数到情绪调节的完整指南',
            'content_track' => 'seo_content_package_existing_article_update',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'working_revision_human_review',
            'validation_summary_json' => [
                'source' => 'articles:update-existing-seo-content-package',
                'operation' => 'update_existing_article_working_revision',
                'schema_hreflang_search_hold' => true,
            ],
            'claim_result_json' => ['status' => 'not_reviewed', 'matches' => []],
            'exactness_json' => [
                'status' => 'passed',
                'article_id' => (int) $article->id,
                'slug' => self::SLUG,
                'canonical_url' => self::CANONICAL,
                'body_hash' => $this->bodyHash($workingBody),
            ],
            'references_json' => ['status' => 'operator_review_required'],
            'media_json' => ['status' => 'unchanged_hold'],
            'graph_json' => ['status' => 'unchanged_hold'],
            'answer_surface_json' => ['status' => 'visible_only'],
            'body_hash' => $this->bodyHash($workingBody),
            'heading_sequence_json' => ['2:EQ 分数应该怎么理解', '2:下一步'],
            'references_count' => 0,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags']) ?? $article;
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }
}
