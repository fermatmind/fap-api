<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\IntentRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleCmsWriteAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cms_write_is_rejected(): void
    {
        $response = $this->postJson('/api/v0.5/cms/articles', $this->articlePayload());

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'UNAUTHORIZED');
    }

    public function test_admin_without_content_write_permission_is_rejected(): void
    {
        $admin = $this->createAdminWithPermissions([]);

        $response = $this->withSession(['ops_org_id' => 11])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', $this->articlePayload());

        $response->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_admin_with_content_write_permission_can_create_article_in_trusted_org_scope(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $response = $this->withSession(['ops_org_id' => 12])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', array_merge($this->articlePayload(), [
                'org_id' => 999,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.org_id', 12);

        $this->assertDatabaseHas('articles', [
            'title' => 'CMS write auth test',
            'org_id' => 12,
        ]);

        $this->assertDatabaseMissing('articles', [
            'title' => 'CMS write auth test',
            'org_id' => 999,
        ]);
    }

    public function test_admin_owner_permission_can_write_articles(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OWNER]);

        $response = $this->withSession(['ops_org_id' => 13])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', $this->articlePayload('owner-can-write'));

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.org_id', 13);
    }

    public function test_legacy_publish_permission_can_still_create_article_in_trusted_org_scope(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_PUBLISH]);

        $response = $this->withSession(['ops_org_id' => 14])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', $this->articlePayload('legacy-publish-can-write'));

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.org_id', 14);
    }

    public function test_admin_with_content_release_permission_cannot_publish_article_before_gate_is_satisfied(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_RELEASE]);
        $article = Article::query()->create([
            'org_id' => 21,
            'slug' => 'release-target',
            'locale' => 'en',
            'title' => 'Release target',
            'content_md' => 'Initial content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $response = $this->withSession(['ops_org_id' => 21])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles/'.$article->id.'/publish');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');
    }

    public function test_admin_with_content_release_permission_can_publish_article_after_gate_is_satisfied(): void
    {
        $releaseAdmin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_RELEASE]);
        $owner = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $reviewer = $this->createAdminWithPermissions([PermissionNames::ADMIN_APPROVAL_REVIEW]);

        $article = Article::query()->create([
            'org_id' => 31,
            'slug' => 'release-ready-target',
            'locale' => 'en',
            'title' => 'Release ready target',
            'excerpt' => 'Release-ready excerpt',
            'content_md' => 'Release-ready body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'author_admin_user_id' => (int) $owner->id,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 31,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Release ready target SEO',
            'seo_description' => 'Release-ready description',
            'canonical_url' => 'https://example.test/en/articles/release-ready-target',
            'og_title' => 'Release ready target OG',
            'og_description' => 'Release-ready OG description',
            'og_image_url' => 'https://example.test/images/release-ready-target.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        ContentGovernanceService::sync($article, [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'release ready target',
            'canonical_target' => 'https://example.test/en/articles/release-ready-target',
            'hub_ref' => 'topics/mbti',
            'test_binding' => 'tests/mbti-personality-test-16-personality-types',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
            'author_admin_user_id' => (int) $owner->id,
            'reviewer_admin_user_id' => (int) $reviewer->id,
            'publish_gate_state' => EditorialReviewAudit::STATE_READY,
        ]);

        $this->setOpsContext(31, $owner, '/ops/editorial-review');
        EditorialReviewAudit::assignOwner((int) $owner->id, 'article', $article);

        $this->setOpsContext(31, $reviewer, '/ops/editorial-review');
        EditorialReviewAudit::assignReviewer((int) $reviewer->id, 'article', $article);

        $this->setOpsContext(31, $owner, '/ops/editorial-review');
        EditorialReviewAudit::submit('article', $article);

        $this->setOpsContext(31, $reviewer, '/ops/editorial-review');
        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, 'article', $article);

        $response = $this->withSession(['ops_org_id' => 31])
            ->actingAs($releaseAdmin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles/'.$article->id.'/publish');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.status', 'published')
            ->assertJsonPath('article.is_public', true);
    }

    public function test_admin_with_content_release_permission_cannot_create_article(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_RELEASE]);

        $response = $this->withSession(['ops_org_id' => 15])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', $this->articlePayload('release-cannot-write'));

        $response->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_article_create_is_blocked_when_primary_query_conflicts_without_exception(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $existing = Article::query()->create([
            'org_id' => 16,
            'slug' => 'infp-majors-guide',
            'locale' => 'en',
            'title' => 'INFP major selection guide',
            'content_md' => "# INFP majors\nGuide body",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $state = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'infp best majors',
            'canonical_target' => 'https://example.test/en/articles/infp-majors-guide',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
        ];

        ContentGovernanceService::sync($existing, $state);
        IntentRegistryService::sync($existing, $state);

        $response = $this->withSession(['ops_org_id' => 16])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', [
                ...$this->articlePayload('best-majors-for-infp'),
                'title' => 'Best majors for INFP',
                'content_md' => "# Best majors for INFP\nVariant body",
                'governance' => [
                    ...$state,
                    'primary_query' => 'best majors for infp',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');
    }

    public function test_article_create_can_request_intent_exception_and_registry_is_written(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $existing = Article::query()->create([
            'org_id' => 17,
            'slug' => 'infj-jobs-guide',
            'locale' => 'en',
            'title' => 'INFJ jobs guide',
            'content_md' => "# INFJ jobs\nGuide body",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $state = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'infj best jobs',
            'canonical_target' => 'https://example.test/en/articles/infj-jobs-guide',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
        ];

        ContentGovernanceService::sync($existing, $state);
        IntentRegistryService::sync($existing, $state);

        $response = $this->withSession(['ops_org_id' => 17])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', [
                ...$this->articlePayload('infj-best-jobs-2026'),
                'title' => 'Best jobs for INFJ in 2026',
                'content_md' => "# Best jobs for INFJ\nException body",
                'governance' => [
                    ...$state,
                    'primary_query' => 'best jobs for infj',
                    'intent_exception_requested' => true,
                    'intent_exception_reason' => 'This page targets a time-bound annual evidence set with a different recommendation matrix.',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true);

        $createdId = (int) $response->json('article.id');
        $this->assertDatabaseHas('intent_registry', [
            'governable_type' => Article::class,
            'governable_id' => $createdId,
            'resolution_strategy' => IntentRegistryService::RESOLUTION_EXCEPTION_REQUESTED,
        ]);

        $registry = IntentRegistry::query()
            ->withoutGlobalScopes()
            ->where('governable_type', Article::class)
            ->where('governable_id', $createdId)
            ->first();

        $this->assertSame(
            'This page targets a time-bound annual evidence set with a different recommendation matrix.',
            $registry?->exception_reason
        );
    }

    public function test_article_update_can_merge_into_existing_canonical_target_without_exception(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $canonical = Article::query()->create([
            'org_id' => 18,
            'slug' => 'infp-best-majors',
            'locale' => 'en',
            'title' => 'INFP best majors',
            'content_md' => "# INFP majors\nCanonical body",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $duplicate = Article::query()->create([
            'org_id' => 18,
            'slug' => 'best-majors-for-infp-2026',
            'locale' => 'en',
            'title' => 'Best majors for INFP in 2026',
            'content_md' => "# Best majors\nDuplicate body",
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $canonicalState = [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'primary_query' => 'infp best majors',
            'canonical_target' => 'https://example.test/en/articles/infp-best-majors',
            'hub_ref' => 'topics/mbti-careers',
            'test_binding' => 'tests/mbti',
            'method_binding' => 'methods/fermat-facet-matrix',
            'cta_stage' => ContentGovernanceService::CTA_STAGE_COMPARE,
        ];

        ContentGovernanceService::sync($canonical, $canonicalState);
        IntentRegistryService::sync($canonical, $canonicalState);

        $response = $this->withSession(['ops_org_id' => 18])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/cms/articles/'.$duplicate->id, [
                'title' => 'Best majors for INFP in 2026',
                'content_md' => "# Best majors\nDuplicate body",
                'governance' => [
                    ...$canonicalState,
                    'primary_query' => 'best majors for infp',
                    'canonical_target' => '/en/articles/infp-best-majors',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('intent_registry', [
            'governable_type' => Article::class,
            'governable_id' => (int) $duplicate->id,
            'canonical_governable_type' => Article::class,
            'canonical_governable_id' => (int) $canonical->id,
            'resolution_strategy' => IntentRegistryService::RESOLUTION_MERGE_TO_CANONICAL,
        ]);
    }

    public function test_cross_org_article_update_is_rejected(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $article = Article::query()->create([
            'org_id' => 21,
            'slug' => 'cross-org-target',
            'locale' => 'en',
            'title' => 'Cross org target',
            'content_md' => 'Initial content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $response = $this->withSession(['ops_org_id' => 22])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/cms/articles/'.$article->id, [
                'title' => 'Should not update',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Cross org target',
            'org_id' => 21,
        ]);
    }

    public function test_article_update_cannot_override_release_managed_fields(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $article = Article::query()->create([
            'org_id' => 23,
            'slug' => 'release-managed-update-target',
            'locale' => 'en',
            'title' => 'Release managed update target',
            'content_md' => 'Initial content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
        ]);

        $response = $this->withSession(['ops_org_id' => 23])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/cms/articles/'.$article->id, [
                'title' => 'Attempted publish bypass',
                'status' => 'published',
                'is_public' => true,
                'published_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Release managed update target',
            'status' => 'draft',
            'is_public' => false,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function articlePayload(string $slug = 'cms-write-auth-test'): array
    {
        return [
            'title' => 'CMS write auth test',
            'slug' => $slug,
            'locale' => 'en',
            'content_md' => 'Hello from the CMS write auth test.',
        ];
    }

    private function setOpsContext(int $orgId, AdminUser $admin, string $path, string $method = 'POST'): void
    {
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create($path, $method));

        $context = app(OrgContext::class);
        $context->set($orgId, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
    }
}
