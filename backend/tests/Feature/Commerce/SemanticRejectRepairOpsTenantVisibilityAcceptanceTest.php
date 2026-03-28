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
use Tests\Concerns\SignedBillingWebhook;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class SemanticRejectRepairOpsTenantVisibilityAcceptanceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;
    use SignedBillingWebhook;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_order_not_found_semantic_reject_repair_converges_consistently_in_lookup_ops_and_tenant(): void
    {
        $this->seedCommerceCatalog();

        config([
            'payments.providers.billing.enabled' => true,
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        $orderNo = 'ord_orphan_acceptance_'.Str::lower(Str::random(8));
        $providerEventId = 'evt_orphan_acceptance_'.Str::lower(Str::random(8));

        $orphanWebhook = $this->postSignedBillingWebhook([
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_'.$orderNo,
            'amount_cents' => 199,
            'currency' => 'CNY',
            'event_type' => 'payment_succeeded',
        ]);

        $orphanWebhook->assertStatus(200);
        $orphanWebhook->assertJsonPath('ok', false);
        $orphanWebhook->assertJsonPath('rejected', true);
        $orphanWebhook->assertJsonPath('acknowledged', true);
        $orphanWebhook->assertJsonPath('reject_reason', 'ORDER_NOT_FOUND');

        $orphanEvent = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', $providerEventId)
            ->first();

        $this->assertNotNull($orphanEvent);
        $this->assertSame($orderNo, (string) ($orphanEvent->order_no ?? ''));
        $this->assertSame('orphan', (string) ($orphanEvent->status ?? ''));
        $this->assertSame('ORDER_NOT_FOUND', (string) ($orphanEvent->last_error_code ?? ''));
        $this->assertSame(
            0,
            DB::table('orders')->where('order_no', $orderNo)->count()
        );

        $tenant = $this->createTenantOrgUserToken();
        $this->grantScaleForOrg($tenant['org_id'], 'MBTI');

        $attemptId = $this->createMbtiAttemptWithResult(
            orgId: $tenant['org_id'],
            userId: (string) $tenant['user_id'],
            anonId: $tenant['anon_id'],
        );

        $this->insertPendingOrder(
            orgId: $tenant['org_id'],
            orderNo: $orderNo,
            attemptId: $attemptId,
            userId: (string) $tenant['user_id'],
            anonId: $tenant['anon_id'],
            email: $tenant['email'],
            amountCents: 199,
            currency: 'CNY',
        );

        $this->assertSame(
            1,
            DB::table('orders')->where('org_id', $tenant['org_id'])->where('order_no', $orderNo)->count()
        );
        $this->assertSame(
            0,
            DB::table('benefit_grants')->where('org_id', $tenant['org_id'])->where('order_no', $orderNo)->count()
        );

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => $tenant['org_id'],
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--include_semantic_rejects' => 1,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $repairedOrder = DB::table('orders')
            ->where('org_id', $tenant['org_id'])
            ->where('order_no', $orderNo)
            ->first();
        $repairedEvent = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', $providerEventId)
            ->first();

        $this->assertNotNull($repairedOrder);
        $this->assertNotNull($repairedEvent);
        $this->assertSame('paid', (string) ($repairedOrder->payment_state ?? ''));
        $this->assertSame('granted', (string) ($repairedOrder->grant_state ?? ''));
        $this->assertSame('fulfilled', (string) ($repairedOrder->status ?? ''));
        $this->assertSame('processed', (string) ($repairedEvent->status ?? ''));
        $this->assertSame('reprocessed', (string) ($repairedEvent->handle_status ?? ''));
        $this->assertSame($tenant['org_id'], (int) ($repairedEvent->org_id ?? 0));
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('org_id', $tenant['org_id'])
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );

        $lookup = $this->withHeaders([
            'Authorization' => 'Bearer '.$tenant['token'],
            'X-Org-Id' => (string) $tenant['org_id'],
        ])->postJson('/api/v0.3/orders/lookup', [
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

        $opsRecord = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $orderNo)
            ->first();

        $this->assertNotNull($opsRecord);

        $opsSupport = app(OrderLinkageSupport::class);
        $opsPayment = $opsSupport->paymentStatus($opsRecord);
        $opsUnlock = $opsSupport->unlockStatus($opsRecord);
        $opsWebhook = $opsSupport->webhookStatus($opsRecord);

        $this->assertSame('paid', $opsPayment['label']);
        $this->assertSame('unlocked', $opsUnlock['label']);
        $this->assertSame('processed', $opsWebhook['label']);
        $this->assertSame('fulfilled', strtolower(trim((string) ($opsRecord->status ?? ''))));
        $this->assertNotSame($opsPayment['label'], $opsWebhook['label']);

        $opsOrder = Order::query()->where('order_no', $orderNo)->firstOrFail();
        $opsAdmin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Orphan Repair Acceptance Ops Org');

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

        $tenantRecord = TenantOrderResource::getEloquentQuery()
            ->where('order_no', $orderNo)
            ->first();

        $this->assertNotNull($tenantRecord);

        $tenantPayment = $this->tenantResourceMethod('paymentStatus');
        $tenantGrant = $this->tenantResourceMethod('grantStatus');
        $tenantUnlock = $this->tenantResourceMethod('unlockStatus');

        $this->assertSame('paid', $tenantPayment->invoke(null, $tenantRecord)['label']);
        $this->assertSame('active', $tenantGrant->invoke(null, $tenantRecord)['label']);
        $this->assertSame('unlocked', $tenantUnlock->invoke(null, $tenantRecord)['label']);
        $this->assertSame('fulfilled', (string) ($tenantRecord->status ?? ''));

        $infolist = TenantOrderResource::infolist(Infolist::make()->record($tenantRecord));
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
            'name' => 'Orphan Repair Tenant User',
            'email' => 'orphan-repair-tenant@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Orphan Repair Tenant Org',
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

        $anonId = 'anon_orphan_repair_'.$userId;
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
            'email' => 'orphan-repair-tenant@example.com',
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

    private function insertPendingOrder(
        int $orgId,
        string $orderNo,
        string $attemptId,
        string $userId,
        string $anonId,
        string $email,
        int $amountCents,
        string $currency,
    ): void {
        $orderId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => $amountCents,
            'amount_total' => $amountCents,
            'amount_refunded' => 0,
            'currency' => $currency,
            'status' => 'created',
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'channel' => 'web',
            'provider_app' => null,
            'provider_order_id' => null,
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'contact_email_hash' => hash('sha256', strtolower(trim($email))),
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        DB::table('payment_attempts')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_no' => 1,
            'provider' => 'billing',
            'channel' => 'web',
            'provider_app' => null,
            'pay_scene' => 'checkout',
            'state' => 'initiated',
            'provider_trade_no' => null,
            'provider_session_ref' => null,
            'amount_expected' => $amountCents,
            'currency' => $currency,
            'external_trade_no' => null,
            'payload_meta_json' => json_encode(['source' => 'semantic-orphan-acceptance'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'verified_at' => null,
            'latest_payment_event_id' => null,
            'initiated_at' => now()->subMinutes(10),
            'provider_created_at' => null,
            'client_presented_at' => null,
            'callback_received_at' => null,
            'finalized_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta_json' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
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
