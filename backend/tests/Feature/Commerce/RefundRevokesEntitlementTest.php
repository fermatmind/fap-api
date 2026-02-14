<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\RefundOrderAction;
use App\Jobs\Commerce\RefundOrderJob;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Support\Rbac\PermissionNames;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundRevokesEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_revokes_entitlement_and_writes_audit(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_refund_1';
        $attemptId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_refund',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'fulfilled',
            'provider' => 'billing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'status' => 'active',
            'benefit_ref' => 'anon_refund',
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_FINANCE_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $result = app(RefundOrderAction::class)
            ->execute($actor, 0, $orderNo, 'customer requested refund', 'corr-refund-1');

        $this->assertTrue($result->ok);

        $job = new RefundOrderJob(0, $orderNo, 'customer requested refund', 'corr-refund-1');
        $job->handle(app(OrderManager::class), app(EntitlementManager::class));

        $this->assertSame('refunded', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame('revoked', (string) DB::table('benefit_grants')->where('order_no', $orderNo)->value('status'));

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'refund_order_requested',
            'target_type' => 'Order',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'refund_order_executed',
            'target_type' => 'Order',
        ]);
    }

    public function test_refund_rejected_for_cross_org_or_missing_permission(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_refund_denied_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 1,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'amount_cents' => 199,
            'amount_total' => 199,
            'currency' => 'CNY',
            'status' => 'fulfilled',
            'provider' => 'billing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $result = app(RefundOrderAction::class)
            ->execute($actor, 0, $orderNo, 'should fail', 'corr-refund-denied-1');

        $this->assertFalse($result->ok);
        $this->assertSame('ORDER_NOT_FOUND', $result->code);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'tester_'.Str::random(6),
            'email' => 'tester_'.Str::random(8).'@example.com',
            'password' => 'password',
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::random(8),
            'description' => 'test role',
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => $permissionName]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
