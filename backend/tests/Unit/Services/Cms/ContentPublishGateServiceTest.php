<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\DataPage;
use App\Models\DataPageSeoMeta;
use App\Models\TopicProfile;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\ContentPublishGateService;
use App\Services\Ops\SeoQualityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContentPublishGateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_missing_release_gate_fields_for_article_without_governance(): void
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'gate-missing-article',
            'locale' => 'en',
            'title' => 'Gate Missing Article',
            'excerpt' => 'Gate Missing excerpt',
            'content_md' => 'Body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Gate Missing SEO',
            'seo_description' => 'Gate Missing Description',
            'canonical_url' => 'https://example.test/en/articles/gate-missing-article',
            'og_title' => 'Gate Missing OG',
            'og_description' => 'Gate Missing OG Description',
            'og_image_url' => 'https://example.test/images/gate-missing.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $missing = ContentPublishGateService::missing('article', $article->fresh('seoMeta'));

        $this->assertContains('governance', $missing);
        $this->assertContains('reviewer', $missing);
        $this->assertContains('cta stage', $missing);
        $this->assertContains('minimum internal links', $missing);
    }

    public function test_it_accepts_topic_hub_when_required_bindings_and_links_exist(): void
    {
        config(['app.frontend_url' => 'https://example.test']);

        $author = AdminUser::query()->create([
            'name' => 'Topic Author',
            'email' => 'topic-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $reviewer = AdminUser::query()->create([
            'name' => 'Topic Reviewer',
            'email' => 'topic-reviewer@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);

        $topic = TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti-careers',
            'slug' => 'mbti-careers',
            'locale' => 'en',
            'title' => 'MBTI Careers',
            'excerpt' => 'Topic excerpt',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_by_admin_user_id' => (int) $author->id,
            'updated_by_admin_user_id' => (int) $author->id,
        ]);

        TopicProfileSection::query()->create([
            'profile_id' => (int) $topic->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Read more at /en/topics/mbti and /en/articles/careers.',
            'body_html' => '<p>Read more.</p>',
            'sort_order' => 0,
            'is_enabled' => true,
        ]);

        TopicProfileSeoMeta::query()->create([
            'profile_id' => (int) $topic->id,
            'seo_title' => 'MBTI Careers SEO',
            'seo_description' => 'MBTI Careers Description',
            'canonical_url' => 'https://example.test/en/topics/mbti-careers',
            'og_title' => 'MBTI Careers OG',
            'og_description' => 'MBTI Careers OG Description',
            'og_image_url' => 'https://example.test/images/mbti-careers.png',
            'twitter_title' => 'MBTI Careers Twitter',
            'twitter_description' => 'MBTI Careers Twitter Description',
            'twitter_image_url' => 'https://example.test/images/mbti-careers-twitter.png',
            'robots' => 'index,follow',
        ]);

        ContentGovernanceService::sync($topic, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_HUB,
            'primary_query' => 'mbti careers',
            'canonical_target' => 'https://example.test/en/topics/mbti-careers',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_DISCOVER,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
        ]);

        $missing = ContentPublishGateService::missing('topic', $topic->fresh(['seoMeta', 'governance', 'sections']));

        $this->assertSame([], $missing);
    }

    public function test_it_reports_schema_consistency_when_protected_override_keys_exist_in_stored_seo_meta(): void
    {
        config(['app.frontend_url' => 'https://example.test']);

        $author = AdminUser::query()->create([
            'name' => 'Schema Author',
            'email' => 'schema-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $reviewer = AdminUser::query()->create([
            'name' => 'Schema Reviewer',
            'email' => 'schema-reviewer@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'author_admin_user_id' => (int) $author->id,
            'slug' => 'schema-gate-article',
            'locale' => 'en',
            'title' => 'Schema Gate Article',
            'excerpt' => 'Visible article summary.',
            'content_md' => '# Body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Schema Gate Article | FermatMind',
            'seo_description' => 'SEO description that differs from visible summary.',
            'canonical_url' => 'https://example.test/en/articles/schema-gate-article',
            'og_title' => 'Schema Gate Article',
            'og_description' => 'Schema Gate Description',
            'og_image_url' => 'https://example.test/images/schema-gate.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $seoMeta = ArticleSeoMeta::query()->where('article_id', (int) $article->id)->firstOrFail();
        DB::table('article_seo_meta')
            ->where('id', (int) $seoMeta->id)
            ->update([
                'schema_json' => json_encode(['@type' => 'WebPage', 'publisher' => ['name' => 'Bad Publisher']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        ContentGovernanceService::sync($article, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'schema gate article',
            'canonical_target' => 'https://example.test/en/articles/schema-gate-article',
            'hub_ref' => 'topics/mbti',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_DISCOVER,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
        ]);

        $missing = ContentPublishGateService::missing('article', $article->fresh(['seoMeta', 'governance']));

        $this->assertContains('schema consistency', $missing);
    }

    public function test_it_requires_a_passing_citation_qa_for_data_pages(): void
    {
        config(['app.frontend_url' => 'https://example.test']);

        $author = AdminUser::query()->create([
            'name' => 'Data Author',
            'email' => 'data-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $reviewer = AdminUser::query()->create([
            'name' => 'Data Reviewer',
            'email' => 'data-reviewer@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);

        $page = DataPage::query()->create([
            'org_id' => 0,
            'data_code' => 'student-career-survey-2026',
            'slug' => 'student-career-survey-2026',
            'locale' => 'en',
            'title' => 'Student Career Survey 2026',
            'excerpt' => 'Aggregate career preference findings.',
            'body_md' => 'See /en/topics/careers, /en/tests/mbti-personality-test-16-personality-types, and /en/methods/fermat-facet-matrix.',
            'sample_size_label' => 'n=4,200',
            'time_window_label' => 'Jan 2025 to Dec 2025',
            'methodology_md' => 'Aggregate survey sample across universities. Results are for groups, not individuals.',
            'limitations_md' => 'Aggregate patterns only. Not for individual diagnosis.',
            'summary_statement_md' => 'Career certainty increased across the aggregate sample during the study window.',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'created_by_admin_user_id' => (int) $author->id,
            'updated_by_admin_user_id' => (int) $author->id,
        ]);

        DataPageSeoMeta::query()->create([
            'data_page_id' => (int) $page->id,
            'seo_title' => 'Student Career Survey 2026',
            'seo_description' => 'Aggregate career preference findings.',
            'canonical_url' => 'https://example.test/en/data/student-career-survey-2026',
            'og_title' => 'Student Career Survey 2026',
            'og_description' => 'Aggregate career preference findings.',
            'og_image_url' => 'https://example.test/images/student-career-survey.png',
            'twitter_title' => 'Student Career Survey 2026',
            'twitter_description' => 'Aggregate career preference findings.',
            'twitter_image_url' => 'https://example.test/images/student-career-survey.png',
            'robots' => 'index,follow',
        ]);

        ContentGovernanceService::sync($page, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_DATA,
            'primary_query' => 'student career survey',
            'canonical_target' => 'https://example.test/en/data/student-career-survey-2026',
            'hub_ref' => 'topics/careers',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_DISCOVER,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
        ]);

        $missingBeforeAudit = ContentPublishGateService::missing('data', $page->fresh(['seoMeta', 'governance']));
        $this->assertContains('citation qa', $missingBeforeAudit);

        app(SeoQualityAuditService::class)->runCitationQa($page->fresh(['seoMeta', 'governance']), (int) $reviewer->id);

        $missingAfterAudit = ContentPublishGateService::missing('data', $page->fresh(['seoMeta', 'governance']));
        $this->assertNotContains('citation qa', $missingAfterAudit);
    }
}
