<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use App\Filament\Tenant\Resources\OrderResource as TenantOrderResource;
use App\Models\Attempt;
use App\Models\Result;
use App\Models\TenantUser;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class AttemptRequiredSemanticRejectRepairOpsTenantVisibilityAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_benefit_code_not_found_repair_converges_consistently_in_lookup_ops_and_tenant(): void
    {
        $this->runPostPaidSemanticRejectScenario(
            rejectCode: 'BENEFIT_CODE_NOT_FOUND',
            beforeWebhook: function (string $orderNo, string $orderSku): void {
                DB::table('skus')
                    ->where('sku', $orderSku)
                    ->update([
                        'benefit_code' => '',
                        'updated_at' => now()->subMinutes(9),
                    ]);
            },
            repairFix: function (string $orderNo, string $attemptId, string $orderSku): void {
                DB::table('skus')
                    ->where('sku', $orderSku)
                    ->update([
                        'benefit_code' => 'MBTI_REPORT_FULL',
                        'updated_at' => now()->subMinutes(8),
                    ]);
            }
        );
    }

    public function test_attempt_required_repair_converges_consistently_in_lookup_ops_and_tenant(): void
    {
        $this->runPostPaidSemanticRejectScenario(
            rejectCode: 'ATTEMPT_REQUIRED',
            beforeWebhook: function (string $orderNo, string $orderSku): void {
                DB::table('orders')
                    ->where('order_no', $orderNo)
                    ->update([
                        'target_attempt_id' => null,
                        'updated_at' => now()->subMinutes(9),
                    ]);
            },
            repairFix: function (string $orderNo, string $attemptId, string $orderSku): void {
                DB::table('orders')
                    ->where('order_no', $orderNo)
                    ->update([
                        'target_attempt_id' => $attemptId,
                        'updated_at' => now()->subMinutes(8),
                    ]);
            }
        );
    }

    private function runPostPaidSemanticRejectScenario(
        string $rejectCode,
        callable $beforeWebhook,
        callable $repairFix
    ): void {
        $this->seedCommerceCatalog();

        config([
            'payments.providers.billing.enabled' => true,
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        $tenant = $this->createTenantOrgUserToken();
        $this->grantScaleForOrg($tenant['org_id'], 'MBTI');

        $attemptId = $this->createMbtiAttemptWithResult(
            orgId: $tenant['org_id'],
            userId: (string) $tenant['user_id'],
            anonId: $tenant['anon_id'],
        );

        $checkout = $this->withHeaders([
            'Authorization' => 'Bearer '.$tenant['token'],
            'X-Org-Id' => (string) $tenant['org_id'],
        ])->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $attemptId,
            'sku' => 'MBTI_REPORT_FULL',
            'provider' => 'billing',
            'email' => $tenant['email'],
        ]);

        $checkout->assertOk();
        $checkout->assertJsonPath('ok', true);

        $orderNo = (string) $checkout->json('order_no');
        $this->assertNotSame('', $orderNo);
        $createdOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($createdOrder);
        $orderSku = strtoupper((string) (
            $createdOrder->effective_sku
            ?? $createdOrder->sku
            ?? $createdOrder->item_sku
            ?? ''
        ));
        $this->assertNotSame('', $orderSku);

        $beforeWebhook($orderNo, $orderSku);

        $providerEventId = 'evt_'.Str::lower($rejectCode).'_'.$orderNo;
        $webhook = $this->postSignedBillingWebhook([
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_'.$orderNo,
            'amount_cents' => (int) ($createdOrder->amount_cents ?? 0),
            'currency' => (string) ($createdOrder->currency ?? 'CNY'),
            'event_type' => 'payment_succeeded',
        ]);

        $webhook->assertStatus(200);
        $webhook->assertJsonPath('ok', false);
        $webhook->assertJsonPath('rejected', true);
        $webhook->assertJsonPath('acknowledged', true);
        $webhook->assertJsonPath('reject_reason', $rejectCode);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'billing',
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => $rejectCode,
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'org_id' => $tenant['org_id'],
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
        ]);
        $this->assertSame(
            0,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );

        $preRepairLookup = $this->lookupForTenant($tenant['token'], $orderNo, $tenant['email']);
        $preRepairLookup->assertOk();
        $preRepairLookup->assertJsonPath('ok', true);
        $preRepairLookup->assertJsonPath('order_no', $orderNo);
        $preRepairLookup->assertJsonPath('status', 'paid');
        $preRepairLookup->assertJsonPath('payment_state', 'paid');
        $preRepairLookup->assertJsonPath('grant_state', 'not_started');
        $preRepairLookup->assertJsonPath('latest_payment_attempt.state', 'paid');

        $this->assertOpsState(
            orderNo: $orderNo,
            expectedPaymentStatus: 'paid',
            expectedUnlockStatus: 'paid_no_grant',
            expectedWebhookStatus: 'rejected',
            expectedRawStatus: 'paid',
        );

        $this->assertTenantState(
            tenant: $tenant,
            orderNo: $orderNo,
            expectedPaymentStatus: 'paid',
            expectedGrantStatus: null,
            expectedUnlockStatus: 'paid_no_grant',
            expectedRawStatus: 'paid',
        );

        $this->assertPaidOrderRepairDidNotResolve($tenant['org_id'], $orderNo, $providerEventId, $rejectCode);

        $repairFix($orderNo, $attemptId, $orderSku);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'billing',
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => $rejectCode,
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'org_id' => $tenant['org_id'],
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
        ]);
        $this->assertSame(
            0,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => $tenant['org_id'],
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--include_semantic_rejects' => 1,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'billing',
            'provider_event_id' => $providerEventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'org_id' => $tenant['org_id'],
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );

        $repairedLookup = $this->lookupForTenant($tenant['token'], $orderNo, $tenant['email']);
        $repairedLookup->assertOk();
        $repairedLookup->assertJsonPath('ok', true);
        $repairedLookup->assertJsonPath('order_no', $orderNo);
        $repairedLookup->assertJsonPath('status', 'paid');
        $repairedLookup->assertJsonPath('payment_state', 'paid');
        $repairedLookup->assertJsonPath('grant_state', 'granted');
        $repairedLookup->assertJsonPath('latest_payment_attempt.state', 'paid');

        $this->assertOpsState(
            orderNo: $orderNo,
            expectedPaymentStatus: 'paid',
            expectedUnlockStatus: 'unlocked',
            expectedWebhookStatus: 'processed',
            expectedRawStatus: 'fulfilled',
        );

        $this->assertTenantState(
            tenant: $tenant,
            orderNo: $orderNo,
            expectedPaymentStatus: 'paid',
            expectedGrantStatus: 'active',
            expectedUnlockStatus: 'unlocked',
            expectedRawStatus: 'fulfilled',
        );
    }

    private function assertPaidOrderRepairDidNotResolve(
        int $orgId,
        string $orderNo,
        string $providerEventId,
        string $rejectCode
    ): void {
        $exitCode = Artisan::call('commerce:repair-paid-orders', [
            '--org_id' => $orgId,
            '--order' => $orderNo,
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'billing',
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'last_error_code' => $rejectCode,
        ]);
        $this->assertSame(
            0,
            DB::table('benefit_grants')
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );
    }

    private function lookupForTenant(string $token, string $orderNo, string $email)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => $email,
        ]);
    }

    private function assertOpsState(
        string $orderNo,
        string $expectedPaymentStatus,
        string $expectedUnlockStatus,
        string $expectedWebhookStatus,
        string $expectedRawStatus
    ): void {
        $record = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $orderNo)
            ->first();

        $this->assertNotNull($record);

        $support = app(OrderLinkageSupport::class);
        $this->assertSame($expectedPaymentStatus, $support->paymentStatus($record)['label']);
        $this->assertSame($expectedUnlockStatus, $support->unlockStatus($record)['label']);
        $this->assertSame($expectedWebhookStatus, $support->webhookStatus($record)['label']);
        $this->assertSame($expectedRawStatus, strtolower(trim((string) ($record->status ?? ''))));
    }

    /**
     * @param  array{org_id:int,user_id:int,token:string,anon_id:string,email:string}  $tenant
     */
    private function assertTenantState(
        array $tenant,
        string $orderNo,
        string $expectedPaymentStatus,
        ?string $expectedGrantStatus,
        string $expectedUnlockStatus,
        string $expectedRawStatus
    ): void {
        $this->bootstrapTenantContext($tenant['org_id'], $tenant['user_id']);
        $this->actingAs(TenantUser::query()->findOrFail($tenant['user_id']), (string) config('tenant.guard', 'tenant'));
        request()->attributes->set('org_id', $tenant['org_id']);
        request()->attributes->set('fm_org_id', $tenant['org_id']);
        request()->attributes->set('org_context_resolved', true);
        request()->attributes->set('org_context_kind', \App\Support\OrgContext::KIND_TENANT);

        $record = TenantOrderResource::getEloquentQuery()
            ->where('order_no', $orderNo)
            ->first();

        $this->assertNotNull($record);

        $paymentStatus = $this->tenantResourceMethod('paymentStatus');
        $grantStatus = $this->tenantResourceMethod('grantStatus');
        $unlockStatus = $this->tenantResourceMethod('unlockStatus');

        $this->assertSame($expectedPaymentStatus, $paymentStatus->invoke(null, $record)['label']);
        if ($expectedGrantStatus === null) {
            $this->assertNotSame('active', $grantStatus->invoke(null, $record)['label']);
        } else {
            $this->assertSame($expectedGrantStatus, $grantStatus->invoke(null, $record)['label']);
        }
        $this->assertSame($expectedUnlockStatus, $unlockStatus->invoke(null, $record)['label']);
        $this->assertSame($expectedRawStatus, strtolower(trim((string) ($record->status ?? ''))));
    }

    private function seedCommerceCatalog(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    /**
     * @return array{org_id:int,user_id:int,token:string,anon_id:string,email:string}
     */
    private function createTenantOrgUserToken(): array
    {
        $email = 'attempt-required-reject-tenant@example.com';
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Attempt Required Reject Tenant User',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Attempt Required Reject Tenant Org',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'owner',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $anonId = 'anon_attempt_required_'.$userId;
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => $userId,
            'org_id' => $orgId,
            'role' => 'owner',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'org_id' => $orgId,
            'user_id' => $userId,
            'token' => $token,
            'anon_id' => $anonId,
            'email' => $email,
        ];
    }

    private function createMbtiAttemptWithResult(int $orgId, string $userId, string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::query()->create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(9),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['EI' => ['a' => 10, 'b' => 10, 'total' => 20]],
            'scores_pct' => ['EI' => 50],
            'axis_states' => ['EI' => 'clear'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function tenantResourceMethod(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(TenantOrderResource::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function bootstrapTenantContext(int $orgId, int $userId): void
    {
        $context = app(\App\Support\OrgContext::class);
        $context->set($orgId, $userId, 'owner', null, \App\Support\OrgContext::KIND_TENANT);
        app()->instance(\App\Support\OrgContext::class, $context);
    }
}
