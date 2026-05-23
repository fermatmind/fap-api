<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\SetOpsLocale;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoRouteShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_intelligence_shell_uses_chinese_copy_for_chinese_locale(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $org = $this->createOrganization('SEO Intel Shell Org');

        $html = $this->withSession($this->opsSession($admin, $org, 'zh_CN'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo?locale=zh-CN')
            ->assertOk()
            ->content();

        foreach (['SEO智能访问', 'SEO看板访问', 'URL 真值', '搜索渠道队列', '只读模型不可用'] as $phrase) {
            $this->assertStringContainsString($phrase, $html);
        }

        $visibleHtml = $this->visibleHtml($html);

        foreach (['SEO Dash access', 'Native read-only SEO Engine observability dashboard', 'URL Truth', 'Search Channel Queue', 'live API'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $visibleHtml);
        }
    }

    public function test_seo_intelligence_shell_keeps_english_copy_for_english_locale(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $org = $this->createOrganization('SEO Intel English Shell Org');

        $html = $this->withSession($this->opsSession($admin, $org, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo?locale=en')
            ->assertOk()
            ->content();

        foreach (['SEO Intelligence Access', 'SEO Dash access', 'URL Truth', 'Search Channel Queue', 'Read model unavailable'] as $phrase) {
            $this->assertStringContainsString($phrase, $html);
        }

        $visibleHtml = $this->visibleHtml($html);

        foreach (['SEO智能访问', 'SEO看板访问', 'URL 真值', '搜索渠道队列', '只读模型不可用'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $visibleHtml);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int,ops_locale:string,ops_locale_explicit:bool}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg, string $locale): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
            SetOpsLocale::SESSION_KEY => $locale,
            SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ];
    }

    private function visibleHtml(string $html): string
    {
        return (string) preg_replace('#<script\\b[^>]*>.*?</script>#is', '', $html);
    }
}
