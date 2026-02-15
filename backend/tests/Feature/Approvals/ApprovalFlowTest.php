<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Approvals\ApprovalExecutor;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_approve_execute_and_status_progression(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_approval_flow_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
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

        $requester = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_FINANCE_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $approval = AdminApproval::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'type' => AdminApproval::TYPE_REFUND,
            'status' => AdminApproval::STATUS_PENDING,
            'requested_by_admin_user_id' => (int) $requester->id,
            'reason' => 'approval flow refund',
            'payload_json' => ['order_no' => $orderNo],
            'correlation_id' => (string) Str::uuid(),
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => (int) $requester->id,
            'action' => 'approval_requested',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => (int) $requester->id,
                'org_id' => 0,
                'reason' => 'approval flow refund',
                'correlation_id' => (string) $approval->correlation_id,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_approval_flow_1',
            'created_at' => now(),
        ]);

        $approval->update([
            'status' => AdminApproval::STATUS_APPROVED,
            'approved_by_admin_user_id' => (int) $reviewer->id,
            'approved_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => (int) $reviewer->id,
            'action' => 'approval_approved',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => (int) $reviewer->id,
                'org_id' => 0,
                'reason' => 'approval flow refund',
                'correlation_id' => (string) $approval->correlation_id,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_approval_flow_2',
            'created_at' => now(),
        ]);

        $execution = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertTrue($execution->ok);

        $approval->refresh();
        $this->assertSame(AdminApproval::STATUS_EXECUTED, (string) $approval->status);

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'approval_executed_success',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
        ]);

        $refundJob = new \App\Jobs\Commerce\RefundOrderJob(0, $orderNo, 'approval flow refund', (string) $approval->correlation_id);
        $refundJob->handle(app(OrderManager::class), app(EntitlementManager::class));

        $this->assertSame('refunded', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));

        $second = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertTrue($second->ok);
        $this->assertTrue((bool) ($second->data['idempotent'] ?? false));
    }

    public function test_failed_execution_records_error_code_and_message(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $requester = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_FINANCE_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $approval = AdminApproval::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'type' => AdminApproval::TYPE_REFUND,
            'status' => AdminApproval::STATUS_APPROVED,
            'requested_by_admin_user_id' => (int) $requester->id,
            'approved_by_admin_user_id' => (int) $requester->id,
            'approved_at' => now(),
            'reason' => 'invalid payload',
            'payload_json' => ['order_no' => ''],
            'correlation_id' => (string) Str::uuid(),
        ]);

        $result = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertFalse($result->ok);

        $approval->refresh();
        $this->assertSame(AdminApproval::STATUS_FAILED, (string) $approval->status);
        $this->assertNotNull($approval->error_code);
        $this->assertNotNull($approval->error_message);

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'approval_executed_failed',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
        ]);
    }

    public function test_execute_approved_refund_with_non_zero_org_in_queue_context(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_approval_flow_org_42';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 42,
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

        $requester = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_FINANCE_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $approval = AdminApproval::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 42,
            'type' => AdminApproval::TYPE_REFUND,
            'status' => AdminApproval::STATUS_APPROVED,
            'requested_by_admin_user_id' => (int) $requester->id,
            'approved_by_admin_user_id' => (int) $requester->id,
            'approved_at' => now(),
            'reason' => 'refund for org 42',
            'payload_json' => ['order_no' => $orderNo],
            'correlation_id' => (string) Str::uuid(),
        ]);

        // Simulate queue worker execution where no request org context is available.
        app(OrgContext::class)->set(0, null, null);

        $execution = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertTrue($execution->ok);

        $approval->refresh();
        $this->assertSame(AdminApproval::STATUS_EXECUTED, (string) $approval->status);

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 42,
            'action' => 'approval_executed_success',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
        ]);
    }

    public function test_rollback_release_approval_writes_order_no_audit_meta(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $actor = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $approval = AdminApproval::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 7,
            'type' => AdminApproval::TYPE_ROLLBACK_RELEASE,
            'status' => AdminApproval::STATUS_APPROVED,
            'requested_by_admin_user_id' => (int) $actor->id,
            'approved_by_admin_user_id' => (int) $actor->id,
            'approved_at' => now(),
            'reason' => 'rollback current release',
            'payload_json' => [
                'order_no' => 'ord_rb_1',
                'region' => 'GLOBAL',
                'locale' => 'en',
                'dir_alias' => 'default',
                'from_version_id' => (string) Str::uuid(),
                'to_version_id' => (string) Str::uuid(),
            ],
            'correlation_id' => (string) Str::uuid(),
        ]);

        $result = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertTrue($result->ok);

        $approval->refresh();
        $this->assertSame(AdminApproval::STATUS_EXECUTED, (string) $approval->status);

        $audit = DB::table('audit_logs')
            ->where('target_type', 'AdminApproval')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'content_release_rollback')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertSame('ord_rb_1', (string) ($meta['order_no'] ?? ''));
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
