<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\ScaleRegistry;
use App\Services\Cms\ArticleMaterialDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ArticlePublicFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_requires_exact_current_public_language_revision_and_known_publication_date(): void
    {
        $public = $this->article('public');
        $this->article('chinese', ['locale' => 'zh-CN']);
        $this->article('private', ['is_public' => false]);
        $this->article('draft', ['status' => 'draft']);
        $this->article('future', ['published_at' => '2100-01-01']);
        $this->article('scheduled', ['scheduled_at' => '2100-01-01']);
        $this->article('undated', ['published_at' => null]);
        $this->article('tenant', ['org_id' => 8]);
        $this->article('draft-revision', [], ['revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT]);
        $this->article('source-revision', [], ['revision_status' => ArticleTranslationRevision::STATUS_SOURCE]);
        $this->article('future-revision', [], ['published_at' => '2100-01-01']);
        $this->article('wrong-language-revision', [], ['locale' => 'zh-CN']);
        $this->article('wrong-org-revision', [], ['org_id' => 8]);
        $this->article('unknown-revision')->forceFill(['published_revision_id' => null])->saveQuietly();

        $response = $this->getJson('/api/v0.5/articles-feed?locale=en&org_id=0')->assertOk()
            ->assertJsonPath('schema_version', 'public-article-feed.v1')->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $public->id)
            ->assertJsonPath('items.0.canonical', 'https://fermatmind.com/en/articles/public');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->getJson('/api/v0.5/articles-feed?locale=zh-CN')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.canonical', 'https://fermatmind.com/zh/articles/chinese');
        $this->getJson('/api/v0.5/articles-feed?locale=fr')->assertUnprocessable();
        $this->getJson('/api/v0.5/articles-feed?locale=en&org_id=8')->assertUnprocessable();
        $this->getJson('/api/v0.5/articles-feed?locale=en&category=private')->assertUnprocessable();
    }

    public function test_working_copy_and_admin_review_clocks_do_not_change_feed_and_unpublication_is_immediate(): void
    {
        $article = $this->article('stable');
        $original = $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->json('items.0');
        $this->getJson('/api/v0.5/articles?locale=en')->assertOk(); // Populate the ordinary list cache.
        $draft = $article->publishedRevision->replicate();
        $draft->forceFill(['revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'title' => 'PRIVATE WORKING TITLE', 'content_md' => 'PRIVATE ANSWERS'])->save();
        $article->forceFill(['working_revision_id' => $draft->id, 'title' => 'PRIVATE PROJECTION',
            'excerpt' => 'PRIVATE EXCERPT', 'updated_at' => '2099-01-01'])->saveQuietly();
        $article->publishedRevision->forceFill(['reviewed_at' => now(), 'updated_at' => now()])->saveQuietly();
        $this->assertSame($original, $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->json('items.0'));
        $article->forceFill(['is_public' => false, 'status' => 'draft'])->saveQuietly();
        $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_native_material_clock_preserves_original_publication_and_unchanged_republish(): void
    {
        $article = $this->article('material');
        $service = app(ArticleMaterialDecisionService::class);
        DB::transaction(fn () => $service->recordPublished($article, $article->publishedRevision, Carbon::parse('2026-01-01')));
        $unchanged = $article->publishedRevision->replicate();
        $unchanged->forceFill(['revision_number' => 2, 'published_at' => '2026-02-01'])->save();
        $article->forceFill(['published_revision_id' => $unchanged->id])->saveQuietly();
        DB::transaction(fn () => $service->recordPublished($article->fresh(), $unchanged, Carbon::parse('2026-02-01')));
        $first = $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->json('items.0');
        $this->assertSame('2026-01-01T00:00:00+00:00', $first['published_at']);
        $this->assertSame($first['published_at'], $first['material_updated_at']);

        $changed = $unchanged->replicate();
        $changed->forceFill(['revision_number' => 3, 'title' => 'Material update', 'published_at' => '2026-03-01'])->save();
        $article->forceFill(['published_revision_id' => $changed->id])->saveQuietly();
        DB::transaction(fn () => $service->recordPublished($article->fresh(), $changed, Carbon::parse('2026-03-01')));
        $second = $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->json('items.0');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['canonical'], $second['canonical']);
        $this->assertSame($first['published_at'], $second['published_at']);
        $this->assertSame('2026-03-01T00:00:00+00:00', $second['material_updated_at']);
        $this->assertSame('Material update', $second['title']);
    }

    public function test_feed_is_bounded_and_orders_actual_revision_updates_with_stable_id_ties(): void
    {
        for ($index = 0; $index < 102; $index++) {
            $this->article('bounded-'.$index);
        }
        $older = $this->article('older-material-update', ['published_at' => '2025-01-01'], ['published_at' => '2026-03-01']);
        $response = $this->getJson('/api/v0.5/articles-feed?locale=en')->assertOk()->assertJsonCount(100, 'items');
        $this->assertSame($older->id, $response->json('items.0.id'));
        $this->assertSame('bounded-101', $response->json('items.1.slug'));
    }

    public function test_no_generic_faq_or_unrelated_mbti_and_explicit_targets_require_same_language_public_registry(): void
    {
        $article = $this->article('no-editorial-next-step');
        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en')->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks', [])
            ->assertJsonPath('landing_surface_v1.start_test_target', null);
        $public = ScaleRegistry::withoutGlobalScopes()->where('org_id', 0)->where('is_public', true)->where('is_active', true)->firstOrFail();
        $article->forceFill(['cover_image_variants' => ['editorial_package_v1' => ['cta_slots' => [
            ['key' => 'wrong_locale', 'label' => 'Wrong language', 'href' => '/zh/tests/'.$public->primary_slug],
            ['key' => 'unknown', 'label' => 'Unknown', 'href' => '/en/tests/nonexistent-controlled-test'],
            ['key' => 'actual', 'label' => 'Chosen assessment', 'href' => '/en/tests/'.$public->primary_slug],
        ]]]])->saveQuietly();
        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en')->assertOk()
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.key', 'actual')
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/'.$public->primary_slug);
        $public->forceFill(['is_public' => false])->saveQuietly();
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', $public->code)->update(['is_public' => false]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en')->assertOk()
            ->assertJsonPath('landing_surface_v1.start_test_target', null);
    }

    private function article(string $slug, array $attributes = [], array $revisionAttributes = []): Article
    {
        $article = Article::withoutGlobalScopes()->create(array_replace([
            'org_id' => 0, 'locale' => 'en', 'slug' => $slug, 'title' => 'Public title',
            'excerpt' => 'Public excerpt', 'content_md' => '## Public body', 'status' => 'published',
            'is_public' => true, 'is_indexable' => false, 'published_at' => '2026-01-01',
        ], $attributes));
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(array_replace([
            'org_id' => $article->org_id, 'article_id' => $article->id, 'source_article_id' => $article->id,
            'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale,
            'source_locale' => $article->locale, 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md,
            'published_at' => $article->published_at,
        ], $revisionAttributes));
        $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();

        return $article->fresh(['publishedRevision']);
    }
}
