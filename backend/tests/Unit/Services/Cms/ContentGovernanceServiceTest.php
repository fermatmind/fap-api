<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ContentGovernance;
use App\Services\Cms\ContentGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentGovernanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_default_state_for_article_and_topic_models(): void
    {
        $articleAuthor = AdminUser::query()->create([
            'name' => 'Article Author',
            'email' => 'article-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'governed-article',
            'locale' => 'en',
            'title' => 'Governed Article',
            'excerpt' => 'Governed excerpt',
            'content_md' => 'Body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $articleAuthor->id,
        ]);

        $articleState = ContentGovernanceService::stateFromRecord($article);
        $topicDefaultState = ContentGovernanceService::defaultStateFor(\App\Models\TopicProfile::class, (int) $articleAuthor->id);

        $this->assertSame(ContentGovernanceService::PAGE_TYPE_GUIDE, $articleState['page_type']);
        $this->assertSame(ContentGovernanceService::CTA_STAGE_COMPARE, $articleState['cta_stage']);
        $this->assertSame((int) $articleAuthor->id, $articleState['author_admin_user_id']);
        $this->assertSame(ContentGovernanceService::PUBLISH_GATE_DRAFT, $articleState['publish_gate_state']);

        $this->assertSame(ContentGovernanceService::PAGE_TYPE_HUB, $topicDefaultState['page_type']);
        $this->assertSame(ContentGovernanceService::CTA_STAGE_DISCOVER, $topicDefaultState['cta_stage']);
        $this->assertSame((int) $articleAuthor->id, $topicDefaultState['author_admin_user_id']);
        $this->assertSame(ContentGovernanceService::PUBLISH_GATE_DRAFT, $topicDefaultState['publish_gate_state']);
    }

    public function test_it_syncs_governance_state_into_the_unified_morph_record(): void
    {
        $author = AdminUser::query()->create([
            'name' => 'Governance Author',
            'email' => 'governance-author@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $reviewer = AdminUser::query()->create([
            'name' => 'Governance Reviewer',
            'email' => 'governance-reviewer@example.test',
            'password' => 'secret-pass',
            'is_active' => 1,
        ]);
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'canonical-governance-article',
            'locale' => 'en',
            'title' => 'Canonical Governance Article',
            'excerpt' => 'Governance excerpt',
            'content_md' => 'Body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $author->id,
        ]);

        $governance = ContentGovernanceService::sync($article, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'how to read mbti results',
            'canonical_target' => 'https://example.test/en/articles/how-to-read-mbti-results',
            'hub_ref' => 'topics/mbti',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
            'publish_gate_state' => EditorialReviewAudit::STATE_READY,
        ]);

        $this->assertInstanceOf(ContentGovernance::class, $governance);
        $this->assertDatabaseHas('content_governance', [
            'id' => (int) $governance->id,
            'org_id' => 0,
            'governable_type' => Article::class,
            'governable_id' => (int) $article->id,
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'how to read mbti results',
            'canonical_target' => 'https://example.test/en/articles/how-to-read-mbti-results',
            'hub_ref' => 'topics/mbti',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $author->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
            'publish_gate_state' => EditorialReviewAudit::STATE_READY,
        ]);
    }
}
