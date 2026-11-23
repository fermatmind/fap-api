<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\ExecuteApprovalJob;
use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Models\CareerRefundRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\ApprovalExecutor;
use App\Services\Auth\AdminTotpService;
use App\Services\Commerce\CareerRefundWorkflow;
use App\Services\Commerce\Checkout\AlipayCheckoutService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CareerRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true]);
    }

    public function test_owner_application_withdrawal_and_duplicate_protection(): void
    {
        [$order, $token] = $this->order();
        $url = '/api/v0.5/career-assessment/orders/'.$order.'/refund';
        $this->getJson($url)->assertUnauthorized();
        [, $other] = $this->order();
        $this->withToken($other)->getJson($url)->assertNotFound();
        $this->withToken($other)->postJson($url, ['reason_code' => 'other', 'acknowledged' => true])->assertNotFound();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('can_request', true);
        $this->withToken($token)->postJson($url, ['reason_code' => 'other'])->assertUnprocessable();
        $body = ['reason_code' => 'not_as_expected', 'detail' => 'Please refund this report.', 'acknowledged' => true];
        $this->withToken($token)->postJson($url, $body)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->withToken($token)->postJson($url, $body)->assertOk()->assertJsonPath('can_request', false);
        $this->assertDatabaseCount('career_refund_requests', 1);
        $this->assertDatabaseHas('orders', ['order_no' => $order, 'payment_state' => 'paid']);
        $this->withToken($token)->deleteJson($url)->assertOk()->assertJsonPath('request.status', 'withdrawn');
        $this->withToken($token)->postJson($url, $body)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertDatabaseCount('career_refund_requests', 1);
    }

    public function test_customer_view_uses_verified_refund_amount_and_prevents_repeat_application(): void
    {
        [$order, $token] = $this->order();
        $url = '/api/v0.5/career-assessment/orders/'.$order.'/refund';
        DB::table('orders')->where('order_no', $order)->update(['refund_amount_cents' => 297]);
        $this->withToken($token)->getJson($url)->assertOk()
            ->assertJsonPath('amount_refunded', 297)->assertJsonPath('can_request', false);
        $this->withToken($token)->postJson($url, ['reason_code' => 'other', 'acknowledged' => true])->assertConflict();
        DB::table('orders')->where('order_no', $order)->update(['payment_state' => 'refunded', 'status' => 'refunded']);
        $this->withToken($token)->getJson($url)->assertOk()
            ->assertJsonPath('payment_state', 'refunded')->assertJsonPath('amount_refunded', 297);
        $this->assertDatabaseCount('career_refund_requests', 0);
    }

    public function test_batch_requires_owner_permissions_mfa_and_exact_pending_selection(): void
    {
        Queue::fake();
        [$number] = $this->order();
        $row = $this->apply($number);
        $admin = $this->admin();
        $request = Request::create('/ops/career-refunds', 'POST');
        $attributes = ['fm_org_id' => 42, 'org_context_kind' => 'tenant', 'org_context_resolved' => true];
        $request->attributes->add($attributes);
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);
        $org = app(OrgContext::class);
        $org->set(42, null, 'admin', null, OrgContext::KIND_TENANT);
        $service = app(CareerRefundWorkflow::class);
        try {
            $service->review([$row->id], $admin, 'wrong', 'approve');
            $this->fail('MFA must fail closed.');
        } catch (\App\Services\Approvals\HighRiskApprovalValidationException) {
            $this->assertSame('pending', $row->fresh()->status);
        }
        $this->assertDatabaseCount('admin_approvals', 0);
        $this->app->instance(AdminTotpService::class, \Mockery::mock(AdminTotpService::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        }));
        $service->review([$row->id], $admin, '123456', 'approve');
        $this->assertSame($attributes, $request->attributes->all());
        $this->assertSame(42, $org->orgId());
        $this->assertSame('approved', $row->fresh()->status);
        $approval = AdminApproval::withoutGlobalScopes()->findOrFail($row->fresh()->approval_id);
        $this->assertSame('APPROVED', $approval->status);
        $this->assertNotEmpty($approval->payload_json['_approval_execution_authorization']);
        Queue::assertPushed(ExecuteApprovalJob::class, 1);
        $this->assertDatabaseHas('orders', ['order_no' => $number, 'payment_state' => 'paid']);
    }

    public function test_bulk_rejection_records_reason_without_queuing_money(): void
    {
        Queue::fake();
        [$one] = $this->order();
        [$two] = $this->order();
        $a = $this->apply($one);
        $b = $this->apply($two);
        $admin = $this->admin();
        $this->app->instance(AdminTotpService::class, \Mockery::mock(AdminTotpService::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        }));
        app(CareerRefundWorkflow::class)->review([$a->id, $b->id], $admin, '123456', 'reject', 'Please contact support with the missing purchase details.');
        $this->assertSame('rejected', $a->fresh()->status);
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertDatabaseCount('admin_approvals', 2);
        $this->assertSame(2, AdminApproval::withoutGlobalScopes()->where('status', 'REJECTED')->count());
        Queue::assertNothingPushed();
    }

    public function test_approved_refund_executes_once_and_channel_timeout_is_reconciled_not_resent(): void
    {
        Queue::fake();
        [$number] = $this->order();
        $row = $this->apply($number);
        $admin = $this->admin();
        $this->app->instance(AdminTotpService::class, \Mockery::mock(AdminTotpService::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        }));
        app(CareerRefundWorkflow::class)->review([$row->id], $admin, '123456', 'approve');
        $sdk = \Mockery::mock(AlipayCheckoutService::class);
        $sdk->shouldReceive('requestFullRefund')->once()->andThrow(new \RuntimeException('network timeout'));
        $sdk->shouldReceive('queryRefund')->once()->andReturn(['code' => '10000', 'refund_status' => 'REFUND_SUCCESS',
            'out_trade_no' => $number, 'trade_no' => 'trade_'.$number,
            'out_request_no' => 'career94-'.substr(hash('sha256', $number), 0, 32), 'total_amount' => '2.99', 'refund_amount' => '2.99']);
        $this->app->instance(AlipayCheckoutService::class, $sdk);
        $result = app(ApprovalExecutor::class)->execute($row->fresh()->approval_id);
        $this->assertTrue($result->ok);
        (new \App\Jobs\Commerce\ProcessCareerRefundRequestJob($row->fresh()->approval_id))->handle(app(CareerRefundWorkflow::class));
        $this->assertSame('attention', $row->fresh()->status);
        app(CareerRefundWorkflow::class)->reconcile($row->fresh());
        $this->assertSame('succeeded', $row->fresh()->status);
        $this->assertDatabaseHas('orders', ['order_no' => $number, 'payment_state' => 'refunded']);
        app(CareerRefundWorkflow::class)->reconcile($row->fresh());
    }

    public function test_batch_runs_independently_after_authorization_and_never_refunds_success_twice(): void
    {
        Queue::fake();
        [$good] = $this->order();
        [$bad] = $this->order();
        $a = $this->apply($good);
        $b = $this->apply($bad);
        $admin = $this->admin();
        $this->app->instance(AdminTotpService::class, \Mockery::mock(AdminTotpService::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        }));
        $batch = app(CareerRefundWorkflow::class)->review([$a->id, $b->id], $admin, '123456', 'approve');
        $this->assertSame($batch, $a->fresh()->batch_id);
        $this->assertSame($batch, $b->fresh()->batch_id);
        foreach ([$a, $b] as $row) {
            $this->assertTrue(app(ApprovalExecutor::class)->execute($row->fresh()->approval_id)->ok);
        }
        $sdk = \Mockery::mock(AlipayCheckoutService::class);
        $sdk->shouldReceive('requestFullRefund')->twice()->andReturnUsing(function ($number, $trade) use ($bad): array {
            if ($number === $bad) {
                throw new \RuntimeException('provider timeout');
            }

            return ['code' => '10000', 'out_trade_no' => $number, 'trade_no' => $trade, 'fund_change' => 'Y', 'refund_fee' => '2.99'];
        });
        $this->app->instance(AlipayCheckoutService::class, $sdk);
        $this->travel(20)->minutes();
        foreach ([$a, $b, $a] as $row) {
            (new \App\Jobs\Commerce\ProcessCareerRefundRequestJob($row->fresh()->approval_id))->handle(app(CareerRefundWorkflow::class));
        }
        $this->assertSame('succeeded', $a->fresh()->status);
        $this->assertSame('attention', $b->fresh()->status);
        $this->assertDatabaseHas('orders', ['order_no' => $good, 'payment_state' => 'refunded']);
        $this->assertDatabaseHas('orders', ['order_no' => $bad, 'payment_state' => 'paid']);
    }

    public function test_review_uses_only_public_site_orders_and_restores_selected_tenant_context(): void
    {
        Queue::fake();
        [$number] = $this->order();
        $row = $this->apply($number);
        $admin = $this->admin();
        $this->app->instance(AdminTotpService::class, \Mockery::mock(AdminTotpService::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        }));
        app(\App\Support\OrgContext::class)->set(12, null, 'owner', null, \App\Support\OrgContext::KIND_TENANT);
        request()->attributes->add(['org_id' => 12, 'fm_org_id' => 12, 'org_context_kind' => 'tenant']);
        app(CareerRefundWorkflow::class)->review([$row->id], $admin, '123456', 'approve');
        $this->assertSame('approved', $row->fresh()->status);
        $this->assertSame(12, app(\App\Support\OrgContext::class)->orgId());
        $this->assertSame(12, request()->attributes->get('fm_org_id'));
        $this->assertSame(0, AdminApproval::withoutGlobalScopes()->findOrFail($row->fresh()->approval_id)->org_id);
    }

    private function apply(string $number): CareerRefundRequest
    {
        $order = DB::table('orders')->where('order_no', $number)->first();
        app(CareerRefundWorkflow::class)->request($number, $order->user_id, ['reason_code' => 'other']);

        return CareerRefundRequest::where('order_no', $number)->firstOrFail();
    }

    private function order(): array
    {
        $user = User::factory()->create();
        $token = 'fm_'.Str::uuid();
        DB::table('auth_tokens')->insert(['token_hash' => hash('sha256', $token), 'user_id' => $user->id,
            'anon_id' => (string) Str::uuid(), 'org_id' => 0, 'role' => 'public', 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now()]);
        $number = 'ord_'.Str::uuid();
        DB::table('orders')->insert(['id' => (string) Str::uuid(), 'order_no' => $number, 'org_id' => 0,
            'user_id' => $user->id, 'anon_id' => (string) Str::uuid(), 'sku' => 'CAREER94_REPORT_CNY_299',
            'item_sku' => 'CAREER94_REPORT_CNY_299', 'quantity' => 1, 'target_attempt_id' => (string) Str::uuid(),
            'currency' => 'CNY', 'amount_cents' => 299, 'amount_total' => 299, 'amount_refunded' => 0,
            'provider' => 'alipay', 'provider_trade_no' => 'trade_'.$number, 'status' => 'paid', 'payment_state' => 'paid',
            'created_at' => now(), 'updated_at' => now()]);

        return [$number, $token];
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::create(['name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => 'fixture-password',
            'is_active' => 1, 'totp_secret' => 'FIXTURE', 'totp_enabled_at' => now()]);
        $permissions = [];
        foreach ([PermissionNames::ADMIN_OPS_READ, PermissionNames::ADMIN_OPS_WRITE, PermissionNames::ADMIN_FINANCE_WRITE, PermissionNames::ADMIN_APPROVAL_REVIEW] as $name) {
            $permissions[] = Permission::firstOrCreate(['name' => $name], ['guard_name' => 'admin'])->id;
        }
        $role = Role::firstOrCreate(['name' => 'refund-test-owner'], ['guard_name' => 'admin']);
        $role->permissions()->sync($permissions);
        $admin->roles()->attach($role);
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $admin->id]);

        return $admin;
    }
}
