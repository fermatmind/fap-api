<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Pages\SeoDashboardAccessPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoRouteShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    #[Test]
    public function ops_seo_route_requires_existing_ops_auth(): void
    {
        $this->get('/ops/seo')
            ->assertRedirectContains('/ops/login');
    }

    #[Test]
    public function ops_read_admin_can_render_static_seo_dashboard_access_shell(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo?locale=en')
            ->assertOk()
            ->assertSee('SEO Intelligence Access')
            ->assertSee('SEO Dash access')
            ->assertSee('SEO Intelligence MVP - URL Truth &amp; Issue Queue', false)
            ->assertSee('URL Truth rows')
            ->assertSee('Entity mappings')
            ->assertSee('Issue queue rows')
            ->assertSee('Verified cards')
            ->assertSee('Private only')
            ->assertSee('seo_intel')
            ->assertSee('seo_intel_metabase_readonly')
            ->assertSee('Workbench')
            ->assertSee('bastion')
            ->assertSee('VPN')
            ->assertSee('No public Metabase')
            ->assertDontSee('<iframe', false)
            ->assertDontSee('business DB source connected');
    }

    #[Test]
    public function page_access_uses_owner_or_ops_read_permissions_only(): void
    {
        $this->actingAs($this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]), (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoDashboardAccessPage::canAccess());

        $this->actingAs($this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
        ]), (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoDashboardAccessPage::canAccess());

        $this->actingAs($this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]), (string) config('admin.guard', 'admin'));
        $this->assertFalse(SeoDashboardAccessPage::canAccess());
    }

    #[Test]
    public function page_methods_return_static_status_without_runtime_queries_or_metabase_calls(): void
    {
        $page = app(SeoDashboardAccessPage::class);

        $this->assertSame([
            [
                'label' => 'URL Truth rows',
                'value' => '7',
                'hint' => 'Verified `seo_urls` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Entity mappings',
                'value' => '7',
                'hint' => 'Verified `seo_url_entities` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Issue queue rows',
                'value' => '5',
                'hint' => 'Verified `seo_issue_queue` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Verified cards',
                'value' => '10',
                'hint' => 'Metabase dashboard card count verified before this route shell PR.',
            ],
        ], $page->statusCards());

        $this->assertCount(4, $page->boundaryCards());
        $this->assertCount(4, $page->accessSteps());
    }

    #[Test]
    public function generated_artifact_locks_route_shell_boundary(): void
    {
        $artifact = $this->artifact();
        $route = $artifact['route'] ?? [];
        $metabase = $artifact['metabase_boundary'] ?? [];

        $this->assertSame('ops-portal-seo-route-shell.v1', $artifact['version'] ?? null);
        $this->assertSame('OPS-PORTAL-SEO-03', $artifact['task'] ?? null);
        $this->assertSame('/ops/seo', $route['path'] ?? null);
        $this->assertSame('fap-api', $route['owner_repo'] ?? null);
        $this->assertTrue((bool) ($route['auth_required'] ?? false));
        $this->assertContains('admin.owner', $route['allowed_permissions'] ?? []);
        $this->assertContains('admin.ops.read', $route['allowed_permissions'] ?? []);
        $this->assertTrue((bool) ($route['route_shell_added'] ?? false));
        $this->assertFalse((bool) ($route['runtime_live_db_query_added'] ?? true));
        $this->assertFalse((bool) ($route['metabase_api_call_added'] ?? true));
        $this->assertTrue((bool) ($metabase['private_only'] ?? false));
        $this->assertFalse((bool) ($metabase['iframe_added'] ?? true));
        $this->assertFalse((bool) ($metabase['reverse_proxy_added'] ?? true));
        $this->assertFalse((bool) ($metabase['public_url_added'] ?? true));
        $this->assertFalse((bool) ($metabase['dns_cdn_openresty_nginx_changed'] ?? true));
    }

    #[Test]
    public function docs_and_view_forbid_public_metabase_sources_and_future_runtime_operations(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-portal-seo-route-shell.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-portal-seo-route-shell.v1.json')));
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php')));
        $lang = strtolower((string) file_get_contents(lang_path('en/ops.php')));
        $combined = $doc."\n".$artifactJson."\n".$view."\n".$lang;

        foreach ([
            'ops-portal-seo-03',
            '/ops/seo',
            'seo intelligence mvp - url truth & issue queue',
            'no iframe',
            'no reverse proxy',
            'no public url',
            'no public sharing',
            'no anonymous links',
            'no public embeds',
            'no business db',
            'tencent rds',
            'node2 local db',
            'no unrestricted sql',
            'next task: `ops-portal-seo-04`',
            '"next_task": "ops-portal-seo-04"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }

        foreach ([
            'production_operations_performed_in_this_pr',
            'metabase_operation_performed_in_this_pr',
            'network_change_performed_in_this_pr',
            'env_edit_in_this_pr',
            'deploy_performed_in_this_pr',
            'scheduler_enabled_in_this_pr',
            'collector_write_performed_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'production_crawler_log_read',
            'research_publish_in_this_pr',
            'pseo_generation_in_this_pr',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($this->artifact()[$flag] ?? true), $flag.' must remain false');
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

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-portal-seo-route-shell.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
