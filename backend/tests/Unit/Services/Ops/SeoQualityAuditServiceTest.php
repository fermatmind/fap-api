<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\DataPage;
use App\Models\DataPageSeoMeta;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use App\Services\Ops\SeoQualityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoQualityAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_citation_qa_records_failed_and_passed_states(): void
    {
        $service = app(SeoQualityAuditService::class);

        $failedPage = DataPage::query()->create([
            'org_id' => 0,
            'data_code' => 'citation-fail',
            'slug' => 'citation-fail',
            'locale' => 'en',
            'title' => 'Citation Fail',
            'excerpt' => 'Missing evidence details.',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $failedAudit = $service->runCitationQa($failedPage);
        $this->assertSame('failed', $failedAudit->status);
        $this->assertSame(5, count((array) $failedAudit->findings_json));

        $passedPage = DataPage::query()->create([
            'org_id' => 0,
            'data_code' => 'citation-pass',
            'slug' => 'citation-pass',
            'locale' => 'en',
            'title' => 'Citation Pass',
            'excerpt' => 'Aggregate evidence page.',
            'body_md' => 'Read the aggregate dataset summary.',
            'sample_size_label' => 'n=1,024',
            'time_window_label' => '2025',
            'methodology_md' => 'Aggregate sample only. Not intended for individual diagnosis.',
            'limitations_md' => 'Applies to the sampled group only and should not be read as an individual-level prediction.',
            'summary_statement_md' => 'Aggregate confidence increased across the sample.',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $passedAudit = $service->runCitationQa($passedPage);
        $this->assertSame('passed', $passedAudit->status);
        $this->assertTrue($service->hasPassingCitationQa($passedPage));
    }

    public function test_run_monthly_patrol_tracks_cannibalization_and_seo_findings(): void
    {
        config(['app.frontend_url' => 'https://example.test']);

        $author = AdminUser::query()->create([
            'name' => 'Patrol Author',
            'email' => 'patrol-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $reviewer = AdminUser::query()->create([
            'name' => 'Patrol Reviewer',
            'email' => 'patrol-reviewer@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);

        $articleA = Article::query()->create([
            'org_id' => 11,
            'author_admin_user_id' => (int) $author->id,
            'slug' => 'career-fit-a',
            'locale' => 'en',
            'title' => 'Career Fit A',
            'excerpt' => 'Visible article summary.',
            'content_md' => '# Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $articleB = Article::query()->create([
            'org_id' => 11,
            'author_admin_user_id' => (int) $author->id,
            'slug' => 'career-fit-b',
            'locale' => 'en',
            'title' => 'Career Fit B',
            'excerpt' => 'Visible article summary.',
            'content_md' => '# Body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 11,
            'article_id' => (int) $articleA->id,
            'locale' => 'en',
            'seo_title' => 'Career Fit A',
            'seo_description' => 'Visible article summary.',
            'canonical_url' => 'https://example.test/en/articles/wrong-career-fit-a',
            'og_title' => 'Career Fit A',
            'og_description' => 'Visible article summary.',
            'og_image_url' => 'https://example.test/images/career-fit-a.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
        $articleSeoMeta = ArticleSeoMeta::query()->where('article_id', (int) $articleA->id)->firstOrFail();
        DB::table('article_seo_meta')
            ->where('id', (int) $articleSeoMeta->id)
            ->update([
                'schema_json' => json_encode(['@type' => 'WebPage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        ContentGovernanceService::sync($articleA, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'career fit',
            'canonical_target' => 'https://example.test/en/articles/career-fit-a',
            'hub_ref' => 'topics/careers',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_DISCOVER,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
        ]);
        ContentGovernanceService::sync($articleB, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'career fit',
            'canonical_target' => 'https://example.test/en/articles/career-fit-b',
            'hub_ref' => 'topics/careers',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_DISCOVER,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
        ]);

        app(IntentRegistryService::class)->sync($articleA, ContentGovernanceService::stateFromRecord($articleA));
        app(IntentRegistryService::class)->sync($articleB, ContentGovernanceService::stateFromRecord($articleB));

        $dataPage = DataPage::query()->create([
            'org_id' => 0,
            'data_code' => 'backlog-data',
            'slug' => 'backlog-data',
            'locale' => 'en',
            'title' => 'Backlog Data',
            'excerpt' => 'Awaiting citation QA.',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
        ]);

        DataPageSeoMeta::query()->create([
            'data_page_id' => (int) $dataPage->id,
            'seo_title' => 'Backlog Data',
            'seo_description' => 'Awaiting citation QA.',
            'canonical_url' => 'https://example.test/en/data/backlog-data',
            'og_title' => 'Backlog Data',
            'og_description' => 'Awaiting citation QA.',
            'og_image_url' => 'https://example.test/images/backlog-data.png',
            'twitter_title' => 'Backlog Data',
            'twitter_description' => 'Awaiting citation QA.',
            'twitter_image_url' => 'https://example.test/images/backlog-data.png',
            'robots' => 'index,follow',
        ]);

        $audit = app(SeoQualityAuditService::class)->runMonthlyPatrol([11], (int) $reviewer->id);

        $this->assertSame('warning', $audit->status);
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit->summary_json, 'canonical_issue_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit->summary_json, 'schema_issue_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit->summary_json, 'sitemap_issue_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit->summary_json, 'cannibalization_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit->summary_json, 'citation_backlog_count'));
    }
}
