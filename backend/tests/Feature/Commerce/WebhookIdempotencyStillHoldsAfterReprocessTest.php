<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\ReprocessPaymentEventAction;
use App\Models\AdminUser;
use App\Models\Attempt;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Support\Rbac\PermissionNames;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookIdempotencyStillHoldsAfterReprocessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocess_keeps_webhook_idempotency_and_audit(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        $attemptId = (string) Str::uuid();
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_reprocess',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        $orderNo = 'ord_reprocess_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_reprocess',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'provider_order_id' => null,
            'external_trade_no' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processor = app(PaymentWebhookProcessor::class);
        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_reprocess_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_reprocess_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_reprocess', true);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(1, DB::table('benefit_grants')->where('org_id', 0)->where('order_no', $orderNo)->count());

        $eventId = (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_reprocess_1')
            ->value('id');

        $actor = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_WRITE,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $action = app(ReprocessPaymentEventAction::class);
        $actionResult = $action->execute($actor, 0, $eventId, 'ops reprocess verification', 'corr-reprocess-1');

        $this->assertTrue($actionResult->ok);
        $this->assertSame(1, DB::table('benefit_grants')->where('org_id', 0)->where('order_no', $orderNo)->count());

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'reprocess_payment_event',
            'target_type' => 'PaymentEvent',
            'target_id' => $eventId,
        ]);
    }

    public function test_reprocess_denied_without_ops_write_permission(): void
    {
        config(['queue.default' => 'sync']);
        (new Pr19CommerceSeeder)->run();

        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'provider' => 'billing',
            'provider_event_id' => 'evt_denied_1',
            'order_id' => (string) Str::uuid(),
            'order_no' => 'ord_denied_1',
            'event_type' => 'payment_succeeded',
            'payload_json' => json_encode(['provider_event_id' => 'evt_denied_1', 'order_no' => 'ord_denied_1']),
            'signature_ok' => 1,
            'status' => 'processed',
            'attempts' => 1,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = (string) DB::table('payment_events')->where('provider_event_id', 'evt_denied_1')->value('id');
        $actor = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);

        $result = app(ReprocessPaymentEventAction::class)
            ->execute($actor, 0, $eventId, 'denied path', 'corr-denied-1');

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
