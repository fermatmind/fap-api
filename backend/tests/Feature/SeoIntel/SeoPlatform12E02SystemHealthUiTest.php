<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoPlatform12E02SystemHealthUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.seo_intel', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.scheduler_enabled', false);
        config()->set('seo_council.mission_execution_enabled', false);
        config()->set('seo_council.model_runtime_enabled', false);
        config()->set('seo_council.tool_broker_enabled', false);
        config()->set('seo_council.notification_dispatch_enabled', false);
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();

        $storage = require database_path('migrations/seo_intel/2026_09_04_010000_create_seo_council_scheduler_storage.php');
        $storage->up();
        $fencing = require database_path('migrations/seo_intel/2026_09_04_020000_expand_seo_council_scheduler_fencing.php');
        $fencing->up();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');

        parent::tearDown();
    }

    public function test_disabled_empty_runtime_is_distinct_from_missing_evidence(): void
    {
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot();
        $items = collect($snapshot['items'])->keyBy('component');

        $this->assertSame('READY', $snapshot['status']);
        $this->assertSame('DISABLED', $items['scheduler']['state']);
        $this->assertSame('VALID_ZERO', $items['lease_backlog']['state']);
        $this->assertSame('UNAVAILABLE', $items['data_freshness']['state']);
        $this->assertSame('UNAVAILABLE', $items['policy_drift']['state']);
        $this->assertSame('VALID_ZERO', $items['cost']['state']);
        $this->assertSame('DISABLED', $items['notification_transport']['state']);
        $this->assertSame('READY', $items['write_guards']['state']);
        $this->assertTrue($snapshot['read_only']);
        $this->assertFalse($snapshot['execution_allowed']);
        $this->assertFalse($snapshot['write_allowed']);
    }

    public function test_hold_and_stale_backlog_states_are_rendered_without_actions(): void
    {
        $this->insertDelivery('HELD', now()->utc());
        $held = app(Platform12SystemHealthReadService::class)->snapshot();
        $this->assertSame('HOLD', $held['status']);

        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->delete();
        $this->insertDelivery('PLANNED', now()->utc()->subDays(2));
        $stale = app(Platform12SystemHealthReadService::class)->snapshot();
        $staleItems = collect($stale['items'])->keyBy('component');
        $this->assertSame('STALE', $staleItems['lease_backlog']['state']);
        $this->assertSame('STALE', $staleItems['data_freshness']['state']);

        $html = view('filament.ops.components.ops-system-health-workspace', ['snapshot' => $stale])->render();
        $this->assertStringContainsString('data-scheduler-activation="disabled"', $html);
        $this->assertStringContainsString('data-component-state="STALE"', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('wire:click', $html);
    }

    public function test_query_failure_fails_to_unavailable_without_mutating_runtime_flags(): void
    {
        DB::purge('seo_intel');
        config()->set('database.connections.seo_intel.driver', 'unavailable');

        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot();

        $this->assertSame('UNAVAILABLE', $snapshot['status']);
        $this->assertNull($snapshot['pagination']['total']);
        $this->assertSame([], $snapshot['items']);
        $this->assertFalse((bool) config('seo_council.scheduler_enabled'));

        config()->set('database.connections.seo_intel.driver', 'sqlite');
        DB::purge('seo_intel');
    }

    public function test_page_access_covers_owner_ops_read_and_unauthorized_users(): void
    {
        $owner = $this->adminWithPermission(PermissionNames::ADMIN_OWNER);
        $opsReader = $this->adminWithPermission(PermissionNames::ADMIN_OPS_READ);
        $unauthorized = $this->adminWithPermission(null);

        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoOperationsPage::canAccess());
        $this->actingAs($opsReader, (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoOperationsPage::canAccess());
        $this->actingAs($unauthorized, (string) config('admin.guard', 'admin'));
        $this->assertFalse(SeoOperationsPage::canAccess());

        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));
        $this->assertStringContainsString('ops-system-health-workspace', $workspace);
        $this->assertSame(['overview', 'performance', 'technical', 'url-truth', 'content', 'automation'], SeoOperationsPage::workspaceKeys());
    }

    private function insertDelivery(string $status, \DateTimeInterface $updatedAt): void
    {
        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->insert([
            'delivery_id' => hash('sha256', $status.$updatedAt->format(DATE_ATOM)),
            'slot_key' => 'daily:system-health',
            'scheduled_for' => $updatedAt->format('Y-m-d H:i:s'),
            'catalog_version' => 'seo.platform12.mission_catalog.v1',
            'catalog_hash' => str_repeat('a', 64),
            'mission_id' => 'system-health-fixture',
            'mission_request_hash' => str_repeat('b', 64),
            'mission_request_json' => '{}',
            'idempotency_key' => 'system-health:'.strtolower($status),
            'attempt' => 1,
            'status' => $status,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function adminWithPermission(?string $permissionName): AdminUser
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
        if ($permissionName !== null) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
