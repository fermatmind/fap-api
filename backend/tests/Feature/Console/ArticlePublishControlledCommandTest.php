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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ArticlePublishControlledCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_exact_confirmation_without_publishing(): void
    {
        $article = $this->createControlledDraft();

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--dry-run' => true,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain("expected_confirmation=I explicitly approve Codex to publish article id {$article->id} after preflight passes.")
            ->expectsOutputToContain('published_article_ids=')
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertNull($article->published_revision_id);
    }

    public function test_controlled_publish_requires_acknowledged_boundary_warnings_and_exact_confirmation(): void
    {
        $this->fakeContentReleaseEndpoint();
        $article = $this->createControlledDraft([
            'claim_result_json' => [
                'status' => 'warning',
                'matches' => [
                    [
                        'field' => 'body_markdown',
                        'code' => 'claim_boundary_forbidden_phrase',
                        'text' => '最适合',
                        'boundary_context' => true,
                    ],
                ],
            ],
            'import_status' => ArticleEditorialPackageImport::STATUS_WARNING,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('claim_warning_ack_required')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--ack-claim-warning' => [(string) $article->id],
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('published_article_ids='.(string) $article->id)
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('published', (string) $article->status);
        $this->assertTrue((bool) $article->is_public);
        $this->assertTrue((bool) $article->is_indexable);
        $this->assertSame((int) $article->working_revision_id, (int) $article->published_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $article->publishedRevision?->revision_status);
        $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
        $this->assertTrue((bool) $article->seoMeta?->is_indexable);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'codex_controlled_article_publish',
            'target_type' => 'article',
            'target_id' => (string) $article->id,
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'article',
            'target_id' => (string) $article->id,
            'result' => 'success',
        ]);

        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://cache.example.test/api/content-release/revalidate'
                && in_array('/zh/articles/controlled-publish-draft', $paths, true)
                && in_array('/llms.txt', $paths, true);
        });
    }

    public function test_non_boundary_claim_warning_is_not_publishable_even_when_acknowledged(): void
    {
        $article = $this->createControlledDraft([
            'claim_result_json' => [
                'status' => 'warning',
                'matches' => [
                    [
                        'field' => 'body_markdown',
                        'code' => 'claim_boundary_forbidden_phrase',
                        'text' => '最适合',
                        'boundary_context' => false,
                    ],
                ],
            ],
            'import_status' => ArticleEditorialPackageImport::STATUS_WARNING,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--ack-claim-warning' => [(string) $article->id],
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('claim_warning_not_boundary_context')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createControlledDraft(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'career-development',
            'name' => '职业发展',
            'is_active' => true,
        ]);
        $tag = ArticleTag::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'riasec',
            'name' => 'RIASEC',
            'is_active' => true,
        ]);
        $body = "# Controlled Publish Draft\n\n## 执行摘要\n\n正文。\n\n## FAQ\n\n### Q\n\nA.";
        $bodyHash = hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
        $locale = (string) ($overrides['locale'] ?? 'zh-CN');

        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => 'controlled-publish-draft',
            'locale' => $locale,
            'title' => 'Controlled Publish Draft',
            'excerpt' => 'Controlled excerpt',
            'content_md' => $body,
            'cover_image_url' => '/storage/articles/controlled.svg',
            'cover_image_alt' => 'Controlled cover alt',
            'cover_image_width' => 1200,
            'cover_image_height' => 675,
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'Controlled Publish Draft',
            'excerpt' => 'Controlled excerpt',
            'content_md' => $body,
            'seo_title' => 'Controlled SEO Title',
            'seo_description' => 'Controlled SEO description',
        ]);
        $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => 'Controlled SEO Title',
            'seo_description' => 'Controlled SEO description',
            'canonical_url' => "https://example.test/zh/articles/{$article->slug}",
            'og_title' => 'Controlled OG Title',
            'og_description' => 'Controlled OG description',
            'og_image_url' => 'https://example.test/storage/articles/controlled.svg',
            'robots' => 'noindex,nofollow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'sensitivity_level' => (string) ($overrides['sensitivity_level'] ?? 'career_sensitive'),
                    'cta_slots' => [
                        ['slot_id' => 'cta_primary', 'href' => '/zh/tests/holland-career-interest-test-riasec'],
                    ],
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => 'Q', 'answer' => 'A'],
                        ],
                    ],
                    'target_topics' => ['mbti'],
                    'target_tests' => ['holland-career-interest-test-riasec'],
                ],
            ],
            'is_indexable' => false,
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => $locale,
            'title' => 'Controlled Publish Draft',
            'content_track' => 'evergreen_knowledge',
            'status' => (string) ($overrides['import_status'] ?? ArticleEditorialPackageImport::STATUS_IMPORTED),
            'intended_status' => 'review_pending',
            'claim_result_json' => $overrides['claim_result_json'] ?? ['status' => 'passed', 'matches' => []],
            'exactness_json' => ['status' => 'passed', 'body_hash' => $bodyHash],
            'references_json' => ['status' => 'complete', 'count' => 1],
            'media_json' => ['status' => 'complete'],
            'graph_json' => ['status' => 'complete', 'target_topics' => ['mbti']],
            'answer_surface_json' => ['status' => 'complete'],
            'body_hash' => $bodyHash,
            'heading_sequence_json' => ['1:Controlled Publish Draft', '2:执行摘要', '2:FAQ'],
            'references_count' => 1,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags']) ?? $article;
    }

    private function fakeContentReleaseEndpoint(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => true], 200),
        ]);
    }
}
