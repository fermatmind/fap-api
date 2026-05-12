<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticlePublishingRuntimeTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_fixture_articles_converge_through_public_api_seo_surface_and_jsonld(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $fixture = $this->articleFixture();
        $created = [];

        foreach ($fixture['articles'] as $articleFixture) {
            $created[] = $this->createRuntimeTruthArticle($articleFixture);
        }

        $listResponse = $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1');
        $listResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonCount(6, 'items');

        foreach ($created as [$article, $articleFixture]) {
            $detailResponse = $this->getJson('/api/v0.5/articles/'.$articleFixture['slug'].'?locale=zh-CN');
            $detailResponse->assertOk()
                ->assertJsonPath('article.title', $articleFixture['title'])
                ->assertJsonPath('article.slug', $articleFixture['slug'])
                ->assertJsonPath('article.excerpt', $articleFixture['excerpt'])
                ->assertJsonPath('article.content_md', $articleFixture['content_md'])
                ->assertJsonPath('article.cover_image_url', $articleFixture['cover_image'])
                ->assertJsonPath('article.cover_image_alt', $articleFixture['cover_image_alt'])
                ->assertJsonPath('article.category.slug', $articleFixture['category']['slug'])
                ->assertJsonPath('article.category.name', $articleFixture['category']['name'])
                ->assertJsonPath('article.author_name', $articleFixture['author'])
                ->assertJsonPath('article.status', 'published')
                ->assertJsonPath('article.locale', 'zh-CN')
                ->assertJsonPath('article.is_indexable', true)
                ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
                ->assertJsonPath('seo_surface_v1.canonical_url', $articleFixture['canonical'])
                ->assertJsonPath('seo_surface_v1.robots_policy', 'index,follow')
                ->assertJsonPath('seo_surface_v1.indexability_state', 'indexable')
                ->assertJsonPath('seo_surface_v1.sitemap_state', 'included')
                ->assertJsonPath('seo_surface_v1.llms_exposure_state', 'allow');

            $detail = $detailResponse->json();
            $this->assertNotEmpty(data_get($detail, 'article.tags'));
            $this->assertContains('Article', data_get($detail, 'seo_surface_v1.structured_data_keys', []));

            $seoResponse = $this->getJson('/api/v0.5/articles/'.$articleFixture['slug'].'/seo?locale=zh-CN');
            $seoResponse->assertOk()
                ->assertJsonPath('meta.title', $articleFixture['seo_title'])
                ->assertJsonPath('meta.description', $articleFixture['meta_description'])
                ->assertJsonPath('meta.canonical', $articleFixture['canonical'])
                ->assertJsonPath('meta.robots', 'index,follow')
                ->assertJsonPath('jsonld.@type', 'Article')
                ->assertJsonPath('jsonld.@id', $articleFixture['canonical'].'#article')
                ->assertJsonPath('jsonld.url', $articleFixture['canonical'])
                ->assertJsonPath('jsonld.mainEntityOfPage', $articleFixture['canonical'])
                ->assertJsonPath('jsonld.headline', $articleFixture['seo_title'])
                ->assertJsonPath('jsonld.description', $articleFixture['meta_description'])
                ->assertJsonPath('jsonld.image', $articleFixture['cover_image'])
                ->assertJsonPath('jsonld.author.name', $articleFixture['author'])
                ->assertJsonPath('seo_surface_v1.structured_data_keys.0', 'Article');

            $this->assertStringContainsString('References', (string) $articleFixture['content_md']);
            $this->assertSame((int) $article->published_revision_id, data_get($detail, 'article.published_revision_id'));
        }
    }

    public function test_draft_private_and_noindex_articles_do_not_silently_enter_discoverability_authority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $visible = $this->createRuntimeTruthArticle($this->articleFixture()['articles'][0])[0];
        $private = $this->createRuntimeTruthArticle(array_merge($this->articleFixture()['articles'][1], [
            'slug' => 'private-dry-run-article',
            'status' => 'draft',
            'is_indexable' => true,
        ]), isPublic: false)[0];
        $noindex = $this->createRuntimeTruthArticle(array_merge($this->articleFixture()['articles'][2], [
            'slug' => 'noindex-dry-run-article',
            'canonical' => 'https://fermatmind.com/zh/articles/noindex-dry-run-article',
            'is_indexable' => false,
        ]))[0];

        $listResponse = $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1');
        $listResponse->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $slugs = array_column($listResponse->json('items') ?? [], 'slug');
        $this->assertContains((string) $visible->slug, $slugs);
        $this->assertContains((string) $noindex->slug, $slugs);
        $this->assertNotContains((string) $private->slug, $slugs);

        $this->getJson('/api/v0.5/articles/'.$private->slug.'?locale=zh-CN')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/'.$private->slug.'/seo?locale=zh-CN')
            ->assertNotFound();

        $this->getJson('/api/v0.5/articles/'.$noindex->slug.'/seo?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('meta.robots', 'noindex,nofollow')
            ->assertJsonPath('seo_surface_v1.indexability_state', 'noindex')
            ->assertJsonPath('seo_surface_v1.sitemap_state', 'excluded')
            ->assertJsonPath('seo_surface_v1.llms_exposure_state', 'withhold');
    }

    /**
     * @return array{articles:list<array<string,mixed>>}
     */
    private function articleFixture(): array
    {
        $path = __DIR__.'/../../Fixtures/article_publishing_runtime_truth.v1.json';
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);
        $this->assertSame('article_publishing_runtime_truth.v1', $payload['version'] ?? null);
        $this->assertIsArray($payload['articles'] ?? null);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array{Article,array<string,mixed>}
     */
    private function createRuntimeTruthArticle(array $fixture, bool $isPublic = true): array
    {
        $categoryPayload = is_array($fixture['category'] ?? null) ? $fixture['category'] : [];
        $category = ArticleCategory::query()->firstOrCreate([
            'org_id' => 0,
            'slug' => (string) ($categoryPayload['slug'] ?? 'uncategorized'),
        ], [
            'name' => (string) ($categoryPayload['name'] ?? 'Uncategorized'),
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => (string) $fixture['author'],
            'slug' => (string) $fixture['slug'],
            'locale' => (string) $fixture['locale'],
            'title' => (string) $fixture['title'],
            'excerpt' => (string) $fixture['excerpt'],
            'content_md' => (string) $fixture['content_md'],
            'content_html' => null,
            'cover_image_url' => (string) $fixture['cover_image'],
            'cover_image_alt' => (string) $fixture['cover_image_alt'],
            'cover_image_width' => 1200,
            'cover_image_height' => 675,
            'cover_image_variants' => [
                'hero' => ['url' => (string) $fixture['cover_image'], 'width' => 1200, 'height' => 675],
                'card' => ['url' => (string) $fixture['cover_image'], 'width' => 1200, 'height' => 675],
                'og' => ['url' => (string) $fixture['cover_image'], 'width' => 1200, 'height' => 675],
            ],
            'status' => $isPublic ? (string) $fixture['status'] : 'draft',
            'is_public' => $isPublic,
            'is_indexable' => (bool) $fixture['is_indexable'],
            'published_at' => Carbon::create(2026, 5, 12, 8, 0, 0, 'UTC'),
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->source_version_hash,
            'title' => (string) $fixture['title'],
            'excerpt' => (string) $fixture['excerpt'],
            'content_md' => (string) $fixture['content_md'],
            'seo_title' => (string) $fixture['seo_title'],
            'seo_description' => (string) $fixture['meta_description'],
            'published_at' => Carbon::create(2026, 5, 12, 8, 0, 0, 'UTC'),
        ]);
        $article->forceFill(['published_revision_id' => $revision->id])->save();

        foreach ((array) ($fixture['tags'] ?? []) as $tagName) {
            $tagSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $tagName) ?: 'tag', '-'));
            $tag = ArticleTag::query()->firstOrCreate([
                'org_id' => 0,
                'slug' => $tagSlug,
            ], [
                'name' => (string) $tagName,
                'is_active' => true,
            ]);
            $article->tags()->syncWithoutDetaching([
                (int) $tag->id => ['org_id' => 0],
            ]);
        }

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $fixture['seo_title'],
            'seo_description' => (string) $fixture['meta_description'],
            'canonical_url' => (string) $fixture['canonical'],
            'og_title' => (string) $fixture['seo_title'],
            'og_description' => (string) $fixture['meta_description'],
            'og_image_url' => (string) $fixture['cover_image'],
            'robots' => (bool) $fixture['is_indexable'] ? 'index,follow' : 'noindex,nofollow',
            'schema_json' => [
                'image' => (string) $fixture['cover_image'],
            ],
            'is_indexable' => (bool) $fixture['is_indexable'],
        ]);

        return [$article->fresh(['publishedRevision']) ?? $article, $fixture];
    }
}
