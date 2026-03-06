<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\AdminUser;
use App\Models\Article;
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

    public function test_admin_without_publish_permission_is_rejected(): void
    {
        $admin = $this->createAdminWithPermissions([]);

        $response = $this->withSession(['ops_org_id' => 11])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/cms/articles', $this->articlePayload());

        $response->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_admin_with_publish_permission_can_create_article_in_trusted_org_scope(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_PUBLISH]);

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

    public function test_cross_org_article_update_is_rejected(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_PUBLISH]);
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

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_' . Str::lower(Str::random(6)),
            'email' => 'admin_' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_' . Str::lower(Str::random(10)),
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
