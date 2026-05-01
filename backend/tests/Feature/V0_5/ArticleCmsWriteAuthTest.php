<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_admin_with_content_release_permission_can_publish_article(): void
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

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.status', 'published')
            ->assertJsonPath('article.is_public', true);

        $article->refresh();
        $this->assertNotNull($article->published_revision_id);
        $this->assertSame($article->working_revision_id, $article->published_revision_id);
        $this->assertSame(
            ArticleTranslationRevision::STATUS_PUBLISHED,
            $article->publishedRevision?->revision_status
        );
        $this->assertNotNull($article->publishedRevision?->published_at);
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

    public function test_content_writer_cannot_publish_article_through_generic_update(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $article = Article::query()->create([
            'org_id' => 31,
            'slug' => 'generic-update-publish-target',
            'locale' => 'en',
            'title' => 'Generic update publish target',
            'content_md' => 'Initial content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $response = $this->withSession(['ops_org_id' => 31])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/cms/articles/'.$article->id, [
                'title' => 'Generic edit only',
                'status' => 'published',
                'is_public' => true,
                'published_at' => now()->toIso8601String(),
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.title', 'Generic edit only')
            ->assertJsonPath('article.status', 'draft')
            ->assertJsonPath('article.is_public', false)
            ->assertJsonPath('article.published_at', null)
            ->assertJsonPath('article.scheduled_at', null);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertNull($article->published_at);
        $this->assertNull($article->scheduled_at);
    }

    public function test_cms_article_create_rejects_foreign_category_and_tags(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $foreignCategory = ArticleCategory::query()->create([
            'org_id' => 41,
            'slug' => 'foreign-category',
            'name' => 'Foreign Category',
            'is_active' => true,
        ]);
        $foreignTag = ArticleTag::query()->create([
            'org_id' => 41,
            'slug' => 'foreign-tag',
            'name' => 'Foreign Tag',
            'is_active' => true,
        ]);

        $categoryResponse = $this->withSession(['ops_org_id' => 42])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', array_merge($this->articlePayload('foreign-category'), [
                'category_id' => $foreignCategory->id,
            ]));

        $categoryResponse->assertStatus(422)
            ->assertJsonPath('ok', false);

        $tagResponse = $this->withSession(['ops_org_id' => 42])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', array_merge($this->articlePayload('foreign-tag'), [
                'tags' => [$foreignTag->id],
            ]));

        $tagResponse->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_cms_article_update_rejects_foreign_category_and_preserves_existing_scope(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $localCategory = ArticleCategory::query()->create([
            'org_id' => 51,
            'slug' => 'local-category',
            'name' => 'Local Category',
            'is_active' => true,
        ]);
        $foreignCategory = ArticleCategory::query()->create([
            'org_id' => 52,
            'slug' => 'foreign-category',
            'name' => 'Foreign Category',
            'is_active' => true,
        ]);
        $article = Article::query()->create([
            'org_id' => 51,
            'category_id' => $localCategory->id,
            'slug' => 'category-scope-target',
            'locale' => 'en',
            'title' => 'Category scope target',
            'content_md' => 'Initial content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $response = $this->withSession(['ops_org_id' => 51])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/cms/articles/'.$article->id, [
                'title' => 'Should not change category',
                'category_id' => $foreignCategory->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'category_id' => $localCategory->id,
            'title' => 'Category scope target',
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
}
