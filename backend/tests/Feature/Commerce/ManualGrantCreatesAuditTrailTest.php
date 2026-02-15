<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\ManualGrantBenefitAction;
use App\Models\AdminUser;
use App\Models\Attempt;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualGrantCreatesAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_grant_writes_benefit_and_audit_with_correlation(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = (string) Str::uuid();
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_manual_grant',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
        ]);

        $orderNo = 'ord_manual_grant_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_manual_grant',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'paid',
            'provider' => 'billing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $result = app(ManualGrantBenefitAction::class)
            ->execute($actor, 0, $orderNo, 'manual remediation', 'corr-manual-1');

        $this->assertTrue($result->ok);
        $this->assertDatabaseHas('benefit_grants', [
            'org_id' => 0,
            'order_no' => $orderNo,
            'attempt_id' => $attemptId,
            'status' => 'active',
        ]);

        $audit = DB::table('audit_logs')
            ->where('org_id', 0)
            ->where('action', 'manual_grant_benefit')
            ->where('target_type', 'Order')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertSame('manual remediation', $meta['reason'] ?? null);
        $this->assertSame($orderNo, $meta['order_no'] ?? null);
        $this->assertSame('corr-manual-1', $meta['correlation_id'] ?? null);
        $this->assertSame((int) $actor->id, (int) ($meta['actor'] ?? 0));
    }

    public function test_manual_grant_denied_without_permission(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = (string) Str::uuid();
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_manual_grant_deny',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
        ]);

        $orderNo = 'ord_manual_grant_deny_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'anon_id' => 'anon_manual_grant_deny',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'currency' => 'CNY',
            'status' => 'paid',
            'provider' => 'billing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);

        $result = app(ManualGrantBenefitAction::class)
            ->execute($actor, 0, $orderNo, 'should fail', 'corr-manual-deny-1');

        $this->assertFalse($result->ok);
        $this->assertSame('FORBIDDEN', $result->code);
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
