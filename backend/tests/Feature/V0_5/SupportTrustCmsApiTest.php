<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupportTrustCmsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_support_articles_are_read_from_dedicated_model_and_exclude_articles(): void
    {
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'editorial-longform',
            'locale' => 'en',
            'title' => 'Editorial longform',
            'excerpt' => 'Editorial only.',
            'content_md' => 'Editorial body.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);

        SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'recover-report',
            'title' => 'Recover report',
            'summary' => 'Find a purchased report.',
            'body_md' => 'Use the order lookup flow.',
            'support_category' => 'reports',
            'support_intent' => 'recover_report',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'approved',
            'primary_cta_label' => 'Look up order',
            'primary_cta_url' => '/orders/lookup',
            'related_support_article_ids' => [],
            'related_content_page_ids' => [],
            'seo_title' => 'Recover report',
            'seo_description' => 'Recover a report.',
            'canonical_path' => '/support/recover-report',
        ]);

        $response = $this->getJson('/api/v0.5/support/articles?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'recover-report')
            ->assertJsonPath('items.0.searchable_model', 'support_articles')
            ->assertJsonPath('items.0.support_intent', 'recover_report')
            ->assertJsonPath('search_scope.excluded_models.0', 'articles');

        $this->assertStringNotContainsString('editorial-longform', (string) $response->getContent());
    }

    public function test_public_interpretation_guides_are_read_from_dedicated_model(): void
    {
        InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => 'read-score',
            'title' => 'Read your score',
            'summary' => 'Understand result score ranges.',
            'body_md' => 'Scores are descriptive, not diagnostic.',
            'test_family' => 'big_five',
            'result_context' => 'score_meaning',
            'audience' => 'report_reader',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'approved',
            'related_guide_ids' => [],
            'related_methodology_page_ids' => [],
            'seo_title' => 'Read your score',
            'seo_description' => 'Understand score ranges.',
            'canonical_path' => '/support/guides/read-score',
        ]);

        $response = $this->getJson('/api/v0.5/support/interpretation-guides/read-score?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guide.slug', 'read-score')
            ->assertJsonPath('guide.searchable_model', 'interpretation_guides')
            ->assertJsonPath('guide.test_family', 'big_five')
            ->assertJsonPath('guide.result_context', 'score_meaning');

        $aliasResponse = $this->getJson('/api/v0.5/support/guides/read-score?locale=en');

        $aliasResponse->assertOk()
            ->assertJsonPath('guide.slug', 'read-score')
            ->assertJsonPath('guide.canonical_path', '/support/guides/read-score');
    }

    public function test_public_support_and_interpretation_reads_require_approved_review_state(): void
    {
        SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'draft-review-support',
            'title' => 'Draft review support',
            'summary' => 'Not approved.',
            'body_md' => 'Not approved body.',
            'support_category' => 'orders',
            'support_intent' => 'lookup_order',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'support_review',
        ]);

        InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => 'draft-review-guide',
            'title' => 'Draft review guide',
            'summary' => 'Not approved.',
            'body_md' => 'Not approved body.',
            'test_family' => 'general',
            'result_context' => 'how_to_read',
            'audience' => 'general',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'content_review',
        ]);

        $this->getJson('/api/v0.5/support/articles/draft-review-support?locale=en')
            ->assertNotFound();

        $this->getJson('/api/v0.5/support/guides/draft-review-guide?locale=en')
            ->assertNotFound();
    }

    public function test_content_pages_support_methodology_boundary_review_fields(): void
    {
        ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/methodology',
            'kind' => 'policy',
            'page_type' => 'methodology',
            'title' => 'Methodology',
            'summary' => 'How the tests are designed.',
            'template' => 'policy',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'science_review',
            'owner' => 'science',
            'legal_review_required' => false,
            'science_review_required' => true,
            'last_reviewed_at' => now(),
            'published_at' => now(),
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => 'Methodology body.',
            'seo_description' => 'Methodology page.',
            'canonical_path' => '/methodology',
        ]);

        $response = $this->getJson('/api/v0.5/internal/content-pages?locale=en');

        $response->assertOk()
            ->assertJsonPath('items.0.slug', 'methodology')
            ->assertJsonPath('items.0.page_type', 'methodology')
            ->assertJsonPath('items.0.review_state', 'science_review')
            ->assertJsonPath('items.0.science_review_required', true);
    }

    public function test_internal_update_accepts_review_and_publish_contracts(): void
    {
        $this->putJson('/api/v0.5/internal/support-articles/contact-support?locale=en', [
            'title' => 'Contact support',
            'summary' => 'Prepare order number, email, screenshots, and time.',
            'body_md' => 'Contact support with the right context.',
            'support_category' => 'troubleshooting',
            'support_intent' => 'contact_support',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'approved',
            'primary_cta_label' => 'Contact support',
            'primary_cta_url' => '/help/contact',
            'related_support_article_ids' => [],
            'related_content_page_ids' => [],
            'last_reviewed_at' => '2026-04-22T00:00:00Z',
            'published_at' => '2026-04-22T00:00:00Z',
            'seo_title' => 'Contact support',
            'seo_description' => 'Contact support.',
            'canonical_path' => '/support/contact-support',
        ])
            ->assertOk()
            ->assertJsonPath('article.slug', 'contact-support')
            ->assertJsonPath('article.status', 'published')
            ->assertJsonPath('article.review_state', 'approved');

        $this->assertDatabaseHas('support_articles', [
            'slug' => 'contact-support',
            'status' => 'published',
            'review_state' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'support_article',
            'reason' => 'cms_release_workspace',
            'result' => 'success',
        ]);

        $this->putJson('/api/v0.5/internal/support-articles/unapproved-publish?locale=en', [
            'title' => 'Unapproved publish',
            'summary' => 'Should fail.',
            'body_md' => 'Should fail.',
            'support_category' => 'orders',
            'support_intent' => 'lookup_order',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'support_review',
        ])->assertStatus(422)
            ->assertJsonPath('errors.review_state.0', 'scheduled or published support articles must be approved.');

        $this->putJson('/api/v0.5/internal/interpretation-guides/unapproved-publish?locale=en', [
            'title' => 'Unapproved publish',
            'summary' => 'Should fail.',
            'body_md' => 'Should fail.',
            'test_family' => 'general',
            'result_context' => 'how_to_read',
            'audience' => 'general',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'content_review',
        ])->assertStatus(422)
            ->assertJsonPath('errors.review_state.0', 'scheduled or published interpretation guides must be approved.');

        $this->putJson('/api/v0.5/internal/interpretation-guides/how-to-read?locale=en', [
            'title' => 'How to read',
            'summary' => 'Understand the guide.',
            'body_md' => 'Guide body.',
            'test_family' => 'general',
            'result_context' => 'how_to_read',
            'audience' => 'general',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'approved',
            'related_guide_ids' => [],
            'related_methodology_page_ids' => [],
            'published_at' => '2026-04-22T00:00:00Z',
            'seo_title' => 'How to read',
            'seo_description' => 'Guide description.',
            'canonical_path' => '/support/guides/how-to-read',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'interpretation_guide',
            'reason' => 'cms_release_workspace',
            'result' => 'success',
        ]);

        $this->putJson('/api/v0.5/internal/content-pages/trust?locale=en', [
            'title' => 'Trust',
            'summary' => 'Trust page.',
            'kind' => 'policy',
            'page_type' => 'trust',
            'template' => 'policy',
            'animation_profile' => 'policy',
            'locale' => 'en',
            'status' => 'published',
            'review_state' => 'approved',
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => 'Trust page body.',
            'content_html' => '',
            'seo_title' => 'Trust',
            'meta_description' => 'Trust description.',
            'seo_description' => 'Trust description.',
            'canonical_path' => '/trust',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'content_page',
            'reason' => 'cms_release_workspace',
            'result' => 'success',
        ]);
        $this->assertSame(3, AuditLog::query()->withoutGlobalScopes()->where('action', 'content_release_publish')->count());
    }
}
