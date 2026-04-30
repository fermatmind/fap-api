<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\AdminUser;
use App\Models\InterpretationGuide;
use App\Models\LandingSurface;
use App\Models\MediaAsset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportArticle;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalCmsRouteAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_cms_read_routes_reject_unauthenticated_requests(): void
    {
        foreach ($this->internalReadUris() as $uri) {
            $response = $this->getJson($uri);

            $this->assertSame(401, $response->getStatusCode(), $uri);
            $response->assertJsonPath('ok', false)
                ->assertJsonPath('error_code', 'UNAUTHORIZED');
        }
    }

    public function test_internal_cms_write_routes_reject_unauthenticated_requests(): void
    {
        foreach ($this->internalWriteRequests() as $request) {
            $response = $this->json($request['method'], $request['uri'], $request['payload']);

            $this->assertSame(401, $response->getStatusCode(), $request['method'].' '.$request['uri']);
            $response->assertJsonPath('ok', false)
                ->assertJsonPath('error_code', 'UNAUTHORIZED');
        }
    }

    public function test_internal_cms_upload_route_rejects_unauthenticated_requests(): void
    {
        $response = $this->postJson('/api/v0.5/internal/media-assets/security-upload/upload', [
            'alt' => 'Security upload regression',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'UNAUTHORIZED');
    }

    public function test_public_cms_routes_remain_public(): void
    {
        $this->getJson('/api/v0.5/support/articles?locale=en')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->getJson('/api/v0.5/media-assets')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_content_read_admin_can_access_internal_cms_read_route(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);

        $response = $this->withSession(['ops_org_id' => 41])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/media-assets');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items', []);
    }

    public function test_content_read_admin_cannot_write_internal_cms_route(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);

        $response = $this->withSession(['ops_org_id' => 42])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/media-assets/read-only-admin', [
                'status' => MediaAsset::STATUS_DRAFT,
                'is_public' => false,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_content_write_admin_can_update_internal_media_metadata(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $response = $this->withSession(['ops_org_id' => 43])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/media-assets/write-admin-asset', [
                'status' => MediaAsset::STATUS_DRAFT,
                'is_public' => false,
                'alt' => 'Security regression asset',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'write-admin-asset')
            ->assertJsonPath('asset.status', MediaAsset::STATUS_DRAFT)
            ->assertJsonPath('asset.is_public', false);

        $this->assertDatabaseHas('media_assets', [
            'asset_key' => 'write-admin-asset',
            'status' => MediaAsset::STATUS_DRAFT,
            'is_public' => false,
        ]);
    }

    /**
     * @return list<string>
     */
    private function internalReadUris(): array
    {
        return [
            '/api/v0.5/internal/support-articles?locale=en',
            '/api/v0.5/internal/support-articles/security-regression?locale=en',
            '/api/v0.5/internal/interpretation-guides?locale=en',
            '/api/v0.5/internal/interpretation-guides/security-regression?locale=en',
            '/api/v0.5/internal/content-pages?locale=en',
            '/api/v0.5/internal/landing-surfaces',
            '/api/v0.5/internal/media-assets',
        ];
    }

    /**
     * @return list<array{method:string,uri:string,payload:array<string,mixed>}>
     */
    private function internalWriteRequests(): array
    {
        return [
            [
                'method' => 'PUT',
                'uri' => '/api/v0.5/internal/support-articles/security-regression',
                'payload' => [
                    'title' => 'Security regression',
                    'support_category' => 'troubleshooting',
                    'support_intent' => 'contact_support',
                    'locale' => 'en',
                    'status' => SupportArticle::STATUS_DRAFT,
                    'review_state' => SupportArticle::REVIEW_DRAFT,
                    'body_md' => 'Security regression content.',
                ],
            ],
            [
                'method' => 'PUT',
                'uri' => '/api/v0.5/internal/interpretation-guides/security-regression',
                'payload' => [
                    'title' => 'Security regression',
                    'test_family' => 'general',
                    'result_context' => 'how_to_read',
                    'locale' => 'en',
                    'status' => InterpretationGuide::STATUS_DRAFT,
                    'review_state' => InterpretationGuide::REVIEW_DRAFT,
                    'body_md' => 'Security regression content.',
                ],
            ],
            [
                'method' => 'PUT',
                'uri' => '/api/v0.5/internal/content-pages/security-regression',
                'payload' => [
                    'title' => 'Security regression',
                    'kind' => 'help',
                    'template' => 'help',
                    'animation_profile' => 'none',
                    'locale' => 'en',
                    'is_public' => false,
                    'is_indexable' => false,
                    'content_md' => 'Security regression content.',
                ],
            ],
            [
                'method' => 'PUT',
                'uri' => '/api/v0.5/internal/landing-surfaces/security-regression',
                'payload' => [
                    'locale' => 'en',
                    'status' => LandingSurface::STATUS_DRAFT,
                    'is_public' => false,
                    'is_indexable' => false,
                ],
            ],
            [
                'method' => 'PUT',
                'uri' => '/api/v0.5/internal/media-assets/security-regression',
                'payload' => [
                    'status' => MediaAsset::STATUS_DRAFT,
                    'is_public' => false,
                ],
            ],
        ];
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
}
