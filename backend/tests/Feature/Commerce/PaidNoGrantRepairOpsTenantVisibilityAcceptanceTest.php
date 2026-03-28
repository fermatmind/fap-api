<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Filament\Ops\Resources\OrderResource\Pages\ListOrders as OpsListOrders;
use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use App\Filament\Tenant\Resources\OrderResource as TenantOrderResource;
use App\Models\Attempt;
use App\Models\Order;
use App\Models\Result;
use App\Models\TenantUser;
use App\Support\Rbac\PermissionNames;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Component;
use Filament\Infolists\Infolist;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class PaidNoGrantRepairOpsTenantVisibilityAcceptanceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_paid_no_grant_repair_converges_consistently_in_lookup_ops_and_tenant(): void
    {
        $this->seedCommerceCatalog();

        $tenant = $this->createTenantOrgUserToken();
        $this->grantScaleForOrg($tenant['org_id'], 'MBTI');

        $attemptId = $this->createMbtiAttemptWithResult(
            orgId: $tenant['org_id'],
            userId: (string) $tenant['user_id'],
            anonId: $tenant['anon_id'],
        );

        config([
            'payments.providers.billing.enabled' => true,
        ]);

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
        $checkout->assertJsonPath('attempt_id', $attemptId);
        $checkout->assertJsonPath('status', 'pending');
        $checkout->assertJsonPath('payment_state', 'pending');
        $checkout->assertJsonPath('grant_state', 'not_started');

        $orderNo = (string) $checkout->json('order_no');
        $this->assertNotSame('', $orderNo);

        $this->promoteOrderToPaidNoGrant($tenant['org_id'], $orderNo);

        $preRepairOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($preRepairOrder);
        $this->assertSame($tenant['org_id'], (int) ($preRepairOrder->org_id ?? 0));
        $this->assertSame('paid', (string) ($preRepairOrder->payment_state ?? ''));
        $this->assertSame('not_started', (string) ($preRepairOrder->grant_state ?? ''));
        $this->assertSame('paid', (string) ($preRepairOrder->status ?? ''));
        $this->assertSame(
            0,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('payment_events')
                ->where('order_no', $orderNo)
                ->count()
        );

        $preRepairOpsRecord = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $orderNo)
            ->first();

        $this->assertNotNull($preRepairOpsRecord);

        $opsSupport = app(OrderLinkageSupport::class);
        $preRepairOpsPayment = $opsSupport->paymentStatus($preRepairOpsRecord);
        $preRepairOpsUnlock = $opsSupport->unlockStatus($preRepairOpsRecord);
        $preRepairOpsWebhook = $opsSupport->webhookStatus($preRepairOpsRecord);

        $this->assertSame('paid', $preRepairOpsPayment['label']);
        $this->assertSame('paid_no_grant', $preRepairOpsUnlock['label']);
        $this->assertSame('missing', $preRepairOpsWebhook['label']);
        $this->assertNotSame($preRepairOpsPayment['label'], $preRepairOpsWebhook['label']);

        $this->bootstrapTenantContext($tenant['org_id'], $tenant['user_id']);
        $this->actingAs(TenantUser::query()->findOrFail($tenant['user_id']), (string) config('tenant.guard', 'tenant'));
        request()->attributes->set('org_id', $tenant['org_id']);
        request()->attributes->set('fm_org_id', $tenant['org_id']);
        request()->attributes->set('org_context_resolved', true);
        request()->attributes->set('org_context_kind', \App\Support\OrgContext::KIND_TENANT);

        $preRepairTenantRecord = TenantOrderResource::getEloquentQuery()
            ->where('order_no', $orderNo)
            ->first();

        $this->assertNotNull($preRepairTenantRecord);

        $tenantPayment = $this->tenantResourceMethod('paymentStatus');
        $tenantGrant = $this->tenantResourceMethod('grantStatus');
        $tenantUnlock = $this->tenantResourceMethod('unlockStatus');

        $this->assertSame('paid', $tenantPayment->invoke(null, $preRepairTenantRecord)['label']);
        $this->assertNotSame('active', $tenantGrant->invoke(null, $preRepairTenantRecord)['label']);
        $this->assertSame('missing', $tenantGrant->invoke(null, $preRepairTenantRecord)['label']);
        $this->assertSame('paid_no_grant', $tenantUnlock->invoke(null, $preRepairTenantRecord)['label']);
        $this->assertSame('paid', (string) ($preRepairTenantRecord->status ?? ''));

        $exitCode = Artisan::call('commerce:repair-paid-orders', [
            '--org_id' => $tenant['org_id'],
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $repairedOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($repairedOrder);
        $this->assertSame('paid', (string) ($repairedOrder->payment_state ?? ''));
        $this->assertSame('granted', (string) ($repairedOrder->grant_state ?? ''));
        $this->assertSame('fulfilled', (string) ($repairedOrder->status ?? ''));
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );
        $this->assertDatabaseHas('benefit_grants', [
            'org_id' => $tenant['org_id'],
            'order_no' => $orderNo,
            'attempt_id' => $attemptId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'status' => 'active',
        ]);

        $lookup = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => $tenant['email'],
        ]);

        $lookup->assertOk();
        $lookup->assertJsonPath('ok', true);
        $lookup->assertJsonPath('order_no', $orderNo);
        $lookup->assertJsonPath('attempt_id', $attemptId);
        $lookup->assertJsonPath('status', 'paid');
        $lookup->assertJsonPath('payment_state', 'paid');
        $lookup->assertJsonPath('grant_state', 'granted');

        $repairedOpsRecord = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $orderNo)
            ->first();

        $this->assertNotNull($repairedOpsRecord);

        $repairedOpsPayment = $opsSupport->paymentStatus($repairedOpsRecord);
        $repairedOpsUnlock = $opsSupport->unlockStatus($repairedOpsRecord);
        $repairedOpsWebhook = $opsSupport->webhookStatus($repairedOpsRecord);

        $this->assertSame('paid', $repairedOpsPayment['label']);
        $this->assertSame('unlocked', $repairedOpsUnlock['label']);
        $this->assertSame('missing', $repairedOpsWebhook['label']);
        $this->assertSame('fulfilled', strtolower(trim((string) ($repairedOpsRecord->status ?? ''))));
        $this->assertNotSame($repairedOpsPayment['label'], $repairedOpsWebhook['label']);

        $opsOrder = Order::query()->where('order_no', $orderNo)->firstOrFail();
        $opsAdmin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Paid No Grant Acceptance Ops Org');

        session($this->opsSession($opsAdmin, $selectedOrg));
        $this->actingAs($opsAdmin, (string) config('admin.guard', 'admin'));

        Livewire::test(OpsListOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$opsOrder])
            ->assertTableColumnExists('payment_state')
            ->assertTableColumnExists('latest_payment_status')
            ->assertTableColumnExists('unlock_status');

        $this->bootstrapTenantContext($tenant['org_id'], $tenant['user_id']);
        $this->actingAs(TenantUser::query()->findOrFail($tenant['user_id']), (string) config('tenant.guard', 'tenant'));
        request()->attributes->set('org_id', $tenant['org_id']);
        request()->attributes->set('fm_org_id', $tenant['org_id']);
        request()->attributes->set('org_context_resolved', true);
        request()->attributes->set('org_context_kind', \App\Support\OrgContext::KIND_TENANT);

        $repairedTenantRecord = TenantOrderResource::getEloquentQuery()
            ->where('order_no', $orderNo)
            ->first();

        $this->assertNotNull($repairedTenantRecord);
        $this->assertSame('paid', $tenantPayment->invoke(null, $repairedTenantRecord)['label']);
        $this->assertSame('active', $tenantGrant->invoke(null, $repairedTenantRecord)['label']);
        $this->assertSame('unlocked', $tenantUnlock->invoke(null, $repairedTenantRecord)['label']);
        $this->assertSame('fulfilled', (string) ($repairedTenantRecord->status ?? ''));

        $infolist = TenantOrderResource::infolist(Infolist::make()->record($repairedTenantRecord));
        $componentNames = array_map(
            static fn (Component $component): string => (string) $component->getName(),
            array_values(array_filter(
                $infolist->getFlatComponents(),
                static fn ($component): bool => $component instanceof Component && method_exists($component, 'getName')
            ))
        );

        $this->assertContains('payment_state', $componentNames);
        $this->assertContains('latest_benefit_status', $componentNames);
        $this->assertContains('unlock_status', $componentNames);
        $this->assertContains('status', $componentNames);
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
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Paid No Grant Tenant User',
            'email' => 'paid-no-grant-repair-tenant@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Paid No Grant Tenant Org',
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

        $anonId = 'anon_paid_no_grant_'.$userId;
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
            'email' => 'paid-no-grant-repair-tenant@example.com',
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
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function promoteOrderToPaidNoGrant(int $orgId, string $orderNo): void
    {
        $timestamp = now()->subMinutes(10);

        DB::table('orders')
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->update([
                'status' => 'paid',
                'payment_state' => 'paid',
                'grant_state' => 'not_started',
                'paid_at' => $timestamp,
                'fulfilled_at' => null,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_attempts')
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->update([
                'state' => 'paid',
                'callback_received_at' => $timestamp,
                'verified_at' => $timestamp,
                'finalized_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
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
