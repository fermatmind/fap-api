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

final class SeoIntelOpsSeoNativeDashboardAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    #[Test]
    public function unauthenticated_users_are_redirected_to_ops_login(): void
    {
        $this->get('/ops/seo')
            ->assertRedirectContains('/ops/login');
    }

    #[Test]
    public function owner_and_ops_read_permissions_are_allowed_by_the_page_gate(): void
    {
        foreach ([PermissionNames::ADMIN_OWNER, PermissionNames::ADMIN_OPS_READ] as $permission) {
            $admin = $this->createAdminWithPermissions([$permission]);

            $this->actingAs($admin, (string) config('admin.guard', 'admin'));
            $this->assertTrue(SeoDashboardAccessPage::canAccess());
            auth((string) config('admin.guard', 'admin'))->logout();
        }
    }

    #[Test]
    public function ops_read_admin_can_render_the_native_dashboard_route(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo')
            ->assertOk()
            ->assertSee('Native read-only SEO Engine observability dashboard')
            ->assertSee('Access boundary')
            ->assertSee('Hard stops')
            ->assertDontSee('<iframe', false);
    }

    #[Test]
    public function unrelated_admin_permissions_are_denied(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->assertFalse(SeoDashboardAccessPage::canAccess());
    }

    #[Test]
    public function no_public_dashboard_route_exists_outside_the_ops_panel(): void
    {
        $this->get('/seo')->assertNotFound();
        $this->get('/seo/dashboard')->assertNotFound();
        $this->get('/ops-seo')->assertNotFound();
    }

    #[Test]
    public function view_and_page_do_not_expose_export_sql_metabase_or_submission_controls(): void
    {
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php')));
        $page = strtolower((string) file_get_contents(app_path('Filament/Ops/Pages/SeoDashboardAccessPage.php')));
        $combined = $view."\n".$page;

        foreach ([
            'no public metabase',
            'no unrestricted sql',
            'no default exports',
            'no approve/retry/submit buttons',
            'no scheduler or collector controls',
            'cms/backend url truth remains the authority',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }

        foreach ([
            '<iframe',
            '<x-filament::button',
            '<button',
            '<form',
            'wire:click',
            'exportaction',
            'export::',
            'db::statement',
            'db::select',
            'raw sql editor',
            'metabaseembed',
            'metabase iframe',
            'searchchannelqueuewriteservice',
            'searchchannelsubmissionexecutor',
            'submitqueueitem',
            'approvequeueitem',
            'retryqueueitem',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
    }

    #[Test]
    public function generated_artifact_locks_permission_audit_and_no_export_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('ops-seo-native-dashboard-access-boundary.v1', $artifact['version'] ?? null);
        $this->assertSame('OPS-SEO-NATIVE-DASH-04', $artifact['task'] ?? null);
        $this->assertSame('/ops/seo', $artifact['surface'] ?? null);
        $this->assertContains('admin.owner', $artifact['allowed_permissions'] ?? []);
        $this->assertContains('admin.ops.read', $artifact['allowed_permissions'] ?? []);
        $this->assertContains('admin.content.read', $artifact['denied_permissions'] ?? []);
        $this->assertTrue((bool) ($artifact['unauthenticated_redirects_to_ops_login'] ?? false));
        $this->assertTrue((bool) ($artifact['admin_owner_allowed'] ?? false));
        $this->assertTrue((bool) ($artifact['admin_ops_read_allowed'] ?? false));
        $this->assertTrue((bool) ($artifact['unrelated_permission_denied'] ?? false));
        $this->assertFalse((bool) ($artifact['public_route_added'] ?? true));

        foreach ([
            'export_controls_added',
            'raw_sql_controls_added',
            'metabase_iframe_added',
            'metabase_reverse_proxy_added',
            'metabase_public_url_added',
            'submission_controls_added',
            'queue_mutation_controls_added',
            'scheduler_controls_added',
            'collector_controls_added',
            'external_search_api_call_added',
            'crawler_log_read_added',
            'production_operation_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_export_no_raw_sql_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-seo-native-dashboard-access-boundary.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-seo-native-dashboard-access-boundary.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ops-seo-native-dash-04',
            'permission audit and no-export verification',
            'admin.owner',
            'admin.ops.read',
            'unauthenticated users redirect to `/ops/login`',
            'no export controls',
            'no raw sql controls',
            'no metabase iframe',
            'no queue approval, retry, or submit controls',
            'no scheduler or collector controls',
            'next task: `crawler-log-observability-train-01`',
            '"next_task": "crawler-log-observability-train-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
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
        $path = base_path('docs/seo/generated/ops-seo-native-dashboard-access-boundary.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
