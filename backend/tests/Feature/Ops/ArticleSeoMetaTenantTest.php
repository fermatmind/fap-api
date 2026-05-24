<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleSeoMetaWorkspace;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ArticleSeoMetaTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_seo_meta_inherits_article_org_and_locale_when_saved_from_ops_form(): void
    {
        $request = Request::create('/ops/articles/22/edit', 'POST');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set(2, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $article = Article::query()->create([
            'org_id' => 2,
            'slug' => 'childhood-dream-job-still-shapes-career-choice',
            'locale' => 'zh-CN',
            'title' => '你小时候想做的工作，为什么还在影响你今天的职业判断？',
            'excerpt' => '童年的 dream job 并不负责预言未来。',
            'content_md' => '# Draft',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $seoMeta = ArticleSeoMeta::query()->create([
            'article_id' => (int) $article->id,
            'seo_title' => '你小时候想做的工作，为什么还在影响你今天的职业判断？',
            'seo_description' => '童年的 dream job 并不负责预言未来。',
            'canonical_url' => '/articles/childhood-dream-job-still-shapes-career-choice',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->assertSame(2, (int) $seoMeta->org_id);
        $this->assertSame('zh-CN', (string) $seoMeta->locale);
        $this->assertDatabaseHas('article_seo_meta', [
            'article_id' => (int) $article->id,
            'org_id' => 2,
            'locale' => 'zh-CN',
            'canonical_url' => '/articles/childhood-dream-job-still-shapes-career-choice',
        ]);
    }

    public function test_article_seo_meta_workspace_preserves_robots_when_payload_omits_it(): void
    {
        $article = Article::query()->create([
            'org_id' => 2,
            'slug' => 'robots-preserved-article',
            'locale' => 'en',
            'title' => 'Robots preserved article',
            'excerpt' => 'Existing robots directives should survive normal article edits.',
            'content_md' => '# Draft',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        ArticleSeoMeta::query()->create([
            'article_id' => (int) $article->id,
            'org_id' => 2,
            'locale' => 'en',
            'seo_title' => 'Old SEO title',
            'seo_description' => 'Old SEO description',
            'canonical_url' => '/articles/robots-preserved-article',
            'robots' => 'noindex,noarchive',
            'is_indexable' => true,
        ]);

        app(ArticleSeoMetaWorkspace::class)->save($article, [
            'seo_title' => 'Updated SEO title',
            'seo_description' => 'Updated SEO description',
            'canonical_url' => '/articles/robots-preserved-article',
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
        ]);

        $article->refresh();

        $this->assertSame('Updated SEO title', (string) $article->seoMeta?->seo_title);
        $this->assertSame('noindex,noarchive', (string) $article->seoMeta?->robots);
    }
}
