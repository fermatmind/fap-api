<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\AdminUser;
use App\Models\Article;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class IntentRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_self_canonical_intent_registry_entry(): void
    {
        $author = AdminUser::query()->create([
            'name' => 'intent_author',
            'email' => 'intent_author@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $article = Article::query()->create([
            'org_id' => 91,
            'slug' => 'mbti-careers-guide',
            'locale' => 'en',
            'title' => 'MBTI careers guide',
            'excerpt' => 'Excerpt',
            'content_md' => "# Intro\nBody",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $author->id,
        ]);

        $state = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'mbti careers guide',
            'canonical_target' => 'https://example.test/en/articles/mbti-careers-guide',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $author->id,
        ];

        ContentGovernanceService::sync($article, $state);
        $registry = IntentRegistryService::sync($article, $state);

        $this->assertNotNull($registry);
        $this->assertSame('mbti careers guide', $registry->primary_query);
        $this->assertSame(Article::class, $registry->canonical_governable_type);
        $this->assertSame((int) $article->id, (int) $registry->canonical_governable_id);
    }

    public function test_similarity_guard_blocks_duplicate_intent_without_exception(): void
    {
        $author = AdminUser::query()->create([
            'name' => 'intent_guard_author',
            'email' => 'intent_guard_author@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $existing = Article::query()->create([
            'org_id' => 92,
            'slug' => 'infp-majors-guide',
            'locale' => 'en',
            'title' => 'INFP major selection guide',
            'excerpt' => 'Excerpt',
            'content_md' => "# INFP majors\nBest majors for INFP students",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $author->id,
        ]);

        $state = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'infp best majors',
            'canonical_target' => 'https://example.test/en/articles/infp-majors-guide',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $author->id,
        ];

        ContentGovernanceService::sync($existing, $state);
        IntentRegistryService::sync($existing, $state);

        $this->expectException(InvalidArgumentException::class);
        IntentRegistryService::assertNoConflict(
            Article::class,
            [
                ...$state,
                'primary_query' => 'best majors for infp',
            ],
            [
                'title' => 'Best majors for INFP',
                'slug' => 'best-majors-for-infp',
                'content_md' => "# Best majors for INFP\nA close variant",
            ],
            92,
        );
    }

    public function test_sync_can_point_registry_to_existing_canonical_record_when_canonical_target_matches(): void
    {
        $author = AdminUser::query()->create([
            'name' => 'canonical_author',
            'email' => 'canonical_author@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $canonical = Article::query()->create([
            'org_id' => 93,
            'slug' => 'infj-best-jobs',
            'locale' => 'en',
            'title' => 'INFJ best jobs',
            'excerpt' => 'Excerpt',
            'content_md' => "# INFJ jobs\nBody",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $author->id,
        ]);

        $duplicate = Article::query()->create([
            'org_id' => 93,
            'slug' => 'best-jobs-for-infj-2026',
            'locale' => 'en',
            'title' => 'Best jobs for INFJ in 2026',
            'excerpt' => 'Excerpt',
            'content_md' => "# Best jobs for INFJ\nBody",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $author->id,
        ]);

        $canonicalState = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'infj best jobs',
            'canonical_target' => 'https://example.test/en/articles/infj-best-jobs',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $author->id,
        ];

        ContentGovernanceService::sync($canonical, $canonicalState);
        IntentRegistryService::sync($canonical, $canonicalState);

        $duplicateState = [
            ...$canonicalState,
            'primary_query' => 'best jobs for infj',
            'canonical_target' => '/en/articles/infj-best-jobs',
        ];

        $registry = IntentRegistryService::sync($duplicate, $duplicateState);

        $this->assertNotNull($registry);
        $this->assertSame(IntentRegistryService::RESOLUTION_MERGE_TO_CANONICAL, $registry->resolution_strategy);
        $this->assertSame(Article::class, $registry->canonical_governable_type);
        $this->assertSame((int) $canonical->id, (int) $registry->canonical_governable_id);
    }
}
