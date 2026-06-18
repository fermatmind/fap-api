<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleTaxonomyHygieneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_for_production_artisan_path(): void
    {
        $this->assertArrayHasKey('articles:taxonomy-hygiene', Artisan::all());
    }

    public function test_dry_run_plans_taxonomy_hygiene_without_database_writes(): void
    {
        $this->seedTaxonomy();
        $article = $this->createPublishedArticle(52, 'college-major-choice-holland-mbti-career-test', 14);
        $this->attachTag($article, 'RIASEC');

        $exitCode = Artisan::call('articles:taxonomy-hygiene', [
            '--article-ids' => '52',
            '--expected-slugs' => 'college-major-choice-holland-mbti-career-test',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_apply_article_taxonomy_hygiene', $payload['action']);
        $this->assertSame('SEO Articles', data_get($payload, 'before.0.category.name'));
        $this->assertSame('职业决策', data_get($payload, 'after.0.category.name'));
        $this->assertSame('career_exploration', data_get($payload, 'category_plan.category_11.before.name'));
        $this->assertSame('职业探索', data_get($payload, 'category_plan.category_11.after.name'));
        $this->assertSame('RIASEC', data_get($payload, 'after.0.first_visible_tags.0.name'));

        $this->assertSame(14, (int) $article->fresh()->category_id);
        $this->assertSame('career_exploration', (string) ArticleCategory::query()->withoutGlobalScopes()->findOrFail(11)->name);
        $this->assertTrue((bool) ArticleCategory::query()->withoutGlobalScopes()->findOrFail(14)->is_active);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_taxonomy_hygiene')->count());
    }

    public function test_execute_applies_bounded_taxonomy_hygiene_without_content_or_publish_changes(): void
    {
        $this->seedTaxonomy();
        $articles = [
            $this->createPublishedArticle(40, 'riasec-holland-career-interest-test-explained', 11),
            $this->createPublishedArticle(46, 'career-interest-vs-personality-test-differences', 14),
            $this->createPublishedArticle(48, 'career-confusion-test-map', 14),
            $this->createPublishedArticle(50, 'iq-test-score-and-limits-explained', 14),
            $this->createPublishedArticle(51, 'enneagram-personality-test-explained', 14),
            $this->createPublishedArticle(52, 'college-major-choice-holland-mbti-career-test', 14),
        ];
        $contentHashes = collect($articles)->mapWithKeys(
            static fn (Article $article): array => [(int) $article->id => hash('sha256', (string) $article->content_md)]
        )->all();
        $publishedRevisionIds = collect($articles)->mapWithKeys(
            static fn (Article $article): array => [(int) $article->id => (int) $article->published_revision_id]
        )->all();

        $exitCode = Artisan::call('articles:taxonomy-hygiene', [
            '--article-ids' => '40,46,48,50,51,52',
            '--expected-slugs' => 'riasec-holland-career-interest-test-explained,career-interest-vs-personality-test-differences,career-confusion-test-map,iq-test-score-and-limits-explained,enneagram-personality-test-explained,college-major-choice-holland-mbti-career-test',
            '--execute' => true,
            '--json' => true,
            '--no-content-change' => true,
            '--no-publish' => true,
            '--no-search' => true,
            '--no-schema-hreflang' => true,
            '--no-sitemap-llms-change' => true,
            '--no-revalidation' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('applied_article_taxonomy_hygiene', $payload['action']);

        $this->assertSame('职业探索', (string) ArticleCategory::query()->withoutGlobalScopes()->findOrFail(11)->name);
        $this->assertSame('career-exploration', (string) ArticleCategory::query()->withoutGlobalScopes()->findOrFail(11)->slug);
        $this->assertFalse((bool) ArticleCategory::query()->withoutGlobalScopes()->findOrFail(14)->is_active);
        $ability = ArticleCategory::query()->withoutGlobalScopes()->where('name', '能力与认知')->firstOrFail();
        $this->assertSame('ability-cognition', (string) $ability->slug);

        $expectedCategoryNames = [
            40 => '职业探索',
            46 => '职业探索',
            48 => '职业探索',
            50 => '能力与认知',
            51 => '人格心理学',
            52 => '职业决策',
        ];
        foreach ($expectedCategoryNames as $articleId => $categoryName) {
            $fresh = Article::query()->withoutGlobalScopes()->with('category')->findOrFail($articleId);
            $this->assertSame($categoryName, (string) $fresh->category?->name);
            $this->assertSame($contentHashes[$articleId], hash('sha256', (string) $fresh->content_md));
            $this->assertSame($publishedRevisionIds[$articleId], (int) $fresh->published_revision_id);
            $this->assertSame('published', (string) $fresh->status);
            $this->assertTrue((bool) $fresh->is_public);
        }

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_taxonomy_hygiene')->first();
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame('40,46,48,50,51,52', (string) $audit->target_id);
        $this->assertSame('articles:taxonomy-hygiene', data_get($audit->meta_json, 'command'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_content_change'));
    }

    public function test_execute_requires_safety_flags_and_slug_locks(): void
    {
        $this->seedTaxonomy();
        $this->createPublishedArticle(52, 'college-major-choice-holland-mbti-career-test', 14);

        $exitCode = Artisan::call('articles:taxonomy-hygiene', [
            '--article-ids' => '52',
            '--expected-slugs' => 'wrong-slug',
            '--execute' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'expected_slug_mismatch');
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
    }

    private function seedTaxonomy(): void
    {
        DB::table('article_categories')->insert([
            ['id' => 1, 'org_id' => 0, 'slug' => 'personality-psychology', 'name' => '人格心理学', 'description' => null, 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'org_id' => 0, 'slug' => 'career-decision-making', 'name' => '职业决策', 'description' => null, 'sort_order' => 8, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'org_id' => 0, 'slug' => 'career-exploration', 'name' => 'career_exploration', 'description' => null, 'sort_order' => 11, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'org_id' => 0, 'slug' => 'seo-articles', 'name' => 'SEO Articles', 'description' => null, 'sort_order' => 14, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createPublishedArticle(int $id, string $slug, int $categoryId): Article
    {
        /** @var Article $article */
        $article = Model::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'id' => $id,
            'org_id' => 0,
            'category_id' => $categoryId,
            'author_admin_user_id' => null,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 8,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Article '.$id,
            'excerpt' => 'Excerpt '.$id,
            'content_md' => '# Article '.$id."\n\nBody.",
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => Carbon::create(2026, 6, 18, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ]));

        /** @var ArticleTranslationRevision $revision */
        $revision = Model::unguarded(fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => null,
            'seo_description' => null,
            'published_at' => $article->published_at,
        ]));

        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();

        return $article->fresh(['category', 'tags']) ?? $article;
    }

    private function attachTag(Article $article, string $name): void
    {
        /** @var ArticleTag $tag */
        $tag = Model::unguarded(fn (): ArticleTag => ArticleTag::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => Str::slug($name),
            'name' => $name,
            'is_active' => true,
        ]));

        DB::table('article_tag_map')->insert([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'tag_id' => (int) $tag->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            (array) ($payload['errors'] ?? [])
        ));
    }
}
