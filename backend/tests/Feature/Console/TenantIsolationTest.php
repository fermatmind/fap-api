<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Exceptions\OrgContextMissingException;
use App\Filament\Ops\Resources\AuditLogResource;
use App\Models\AdminUser;
use App\Models\Attempt;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Share;
use App\Policies\AttemptPolicy;
use App\Policies\OrderPolicy;
use App\Policies\SharePolicy;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scope_hides_cross_org_records(): void
    {
        $attemptOrgA = $this->seedAttempt(1001);
        $attemptOrgB = $this->seedAttempt(1002);

        $this->setHttpContext(1001, '/api/_tenant-scope-attempts');

        $ids = Attempt::query()->orderBy('id')->pluck('id')->all();

        $this->assertContains($attemptOrgA->id, $ids);
        $this->assertNotContains($attemptOrgB->id, $ids);
    }

    public function test_org_context_missing_throws_for_strict_models(): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'seed_org_0',
            'target_type' => null,
            'target_id' => null,
            'meta_json' => json_encode(['seed' => true]),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req-0',
            'created_at' => now(),
        ]);

        $this->setHttpContext(0, '/api/_tenant-scope-fail-closed');

        $this->expectException(OrgContextMissingException::class);
        \App\Models\AuditLog::query()->count();
    }

    public function test_ops_resource_list_changes_with_org_context(): void
    {
        DB::table('audit_logs')->insert([
            [
                'org_id' => 301,
                'actor_admin_id' => null,
                'action' => 'seed_org_301',
                'target_type' => null,
                'target_id' => null,
                'meta_json' => json_encode(['org' => 301]),
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'request_id' => 'req-301',
                'created_at' => now(),
            ],
            [
                'org_id' => 302,
                'actor_admin_id' => null,
                'action' => 'seed_org_302',
                'target_type' => null,
                'target_id' => null,
                'meta_json' => json_encode(['org' => 302]),
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'request_id' => 'req-302',
                'created_at' => now(),
            ],
        ]);

        $this->setHttpContext(301, '/ops/resources/audit-logs');
        $rowsOrg301 = AuditLogResource::getEloquentQuery()
            ->get(['org_id'])
            ->pluck('org_id')
            ->all();

        $this->setHttpContext(302, '/ops/resources/audit-logs');
        $rowsOrg302 = AuditLogResource::getEloquentQuery()
            ->get(['org_id'])
            ->pluck('org_id')
            ->all();

        $this->assertSame([301], array_values(array_map('intval', $rowsOrg301)));
        $this->assertSame([302], array_values(array_map('intval', $rowsOrg302)));
    }

    public function test_policies_enforce_cross_org_and_ops_write_permission(): void
    {
        $attemptOrgA = $this->seedAttempt(501);
        $attemptOrgB = $this->seedAttempt(502);
        $orderOrgA = $this->seedOrder(501);
        $orderOrgB = $this->seedOrder(502);
        $shareOrgA = $this->seedShare($attemptOrgA);
        $shareOrgB = $this->seedShare($attemptOrgB);

        $readOnlyAdmin = $this->seedAdmin([PermissionNames::ADMIN_OPS_READ]);
        $readWriteAdmin = $this->seedAdmin([
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_OPS_WRITE,
        ]);

        $this->setHttpContext(501, '/ops/resources/policy-check');

        $attemptPolicy = new AttemptPolicy();
        $orderPolicy = new OrderPolicy();
        $sharePolicy = new SharePolicy();

        $this->assertTrue($attemptPolicy->view($readOnlyAdmin, $attemptOrgA));
        $this->assertFalse($attemptPolicy->view($readOnlyAdmin, $attemptOrgB));
        $this->assertFalse($attemptPolicy->update($readOnlyAdmin, $attemptOrgA));
        $this->assertTrue($attemptPolicy->update($readWriteAdmin, $attemptOrgA));

        $this->assertTrue($orderPolicy->view($readOnlyAdmin, $orderOrgA));
        $this->assertFalse($orderPolicy->view($readOnlyAdmin, $orderOrgB));
        $this->assertFalse($orderPolicy->delete($readOnlyAdmin, $orderOrgA));
        $this->assertTrue($orderPolicy->delete($readWriteAdmin, $orderOrgA));

        $this->assertTrue($sharePolicy->view($readOnlyAdmin, $shareOrgA));
        $this->assertFalse($sharePolicy->view($readOnlyAdmin, $shareOrgB));
        $this->assertFalse($sharePolicy->update($readOnlyAdmin, $shareOrgA));
        $this->assertTrue($sharePolicy->update($readWriteAdmin, $shareOrgA));
    }

    private function setHttpContext(int $orgId, string $path): void
    {
        $request = Request::create($path, 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set($orgId, 9001, 'admin');
        app()->instance(OrgContext::class, $context);
    }

    private function seedAttempt(int $orgId): Attempt
    {
        return Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'anon_id' => 'anon_' . $orgId,
            'user_id' => '9001',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.2.2',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => ['seed' => true],
        ]);
    }

    private function seedOrder(int $orgId): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_' . Str::uuid(),
            'provider' => 'stub',
            'status' => 'created',
            'amount_total' => 100,
            'amount_cents' => 100,
            'amount_refunded' => 0,
            'currency' => 'USD',
            'item_sku' => 'MBTI_REPORT',
            'sku' => 'MBTI_REPORT',
            'quantity' => 1,
            'user_id' => '9001',
            'anon_id' => 'anon_' . $orgId,
            'org_id' => $orgId,
        ]);
    }

    private function seedShare(Attempt $attempt): Share
    {
        return Share::create([
            'id' => (string) Str::uuid(),
            'attempt_id' => (string) $attempt->id,
            'anon_id' => (string) ($attempt->anon_id ?? ''),
            'scale_code' => (string) ($attempt->scale_code ?? 'MBTI'),
            'scale_version' => (string) ($attempt->scale_version ?? 'v0.3'),
            'content_package_version' => (string) ($attempt->content_package_version ?? 'v0.2.2'),
        ]);
    }

    /**
     * @param list<string> $permissions
     */
    private function seedAdmin(array $permissions): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'admin_' . Str::lower(Str::random(6)),
            'email' => 'admin_' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::create([
            'name' => 'role_' . Str::lower(Str::random(10)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
