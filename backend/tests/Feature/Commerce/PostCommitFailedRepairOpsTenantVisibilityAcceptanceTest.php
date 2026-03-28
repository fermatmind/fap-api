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
use App\Services\Report\ReportSnapshotStore;
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
use Mockery;
use ReflectionMethod;
use Tests\Concerns\SignedBillingWebhook;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class PostCommitFailedRepairOpsTenantVisibilityAcceptanceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;
    use SignedBillingWebhook;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_post_commit_failed_repair_converges_consistently_in_lookup_ops_and_tenant(): void
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
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        $realSnapshotStore = app(ReportSnapshotStore::class);
        $snapshotStore = Mockery::mock(ReportSnapshotStore::class)->makePartial();
        $seedCalls = 0;
        $snapshotStore->shouldReceive('seedPendingSnapshot')
            ->twice()
            ->andReturnUsing(function (int $orgId, string $attemptIdArg, ?string $orderNoArg, array $meta) use (&$seedCalls, $realSnapshotStore): void {
                $seedCalls++;
                if ($seedCalls === 1) {
                    throw new \RuntimeException('simulated_post_commit_failure');
                }

                $realSnapshotStore->seedPendingSnapshot($orgId, $attemptIdArg, $orderNoArg, $meta);
            });
        $this->app->instance(ReportSnapshotStore::class, $snapshotStore);

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

        $createdOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($createdOrder);
        $this->assertSame($tenant['org_id'], (int) ($createdOrder->org_id ?? 0));

        $providerEventId = 'evt_post_commit_'.$orderNo;
        $webhook = $this->postSignedBillingWebhook([
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_post_commit_'.$orderNo,
            'amount_cents' => (int) ($createdOrder->amount_cents ?? 0),
            'currency' => (string) ($createdOrder->currency ?? 'CNY'),
        ]);

        $webhook->assertStatus(500);
        $webhook->assertJsonPath('ok', false);

        $failedOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $failedEvent = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', $providerEventId)
            ->first();

        $this->assertNotNull($failedOrder);
        $this->assertNotNull($failedEvent);
        $this->assertSame('paid', (string) ($failedOrder->payment_state ?? ''));
        $this->assertSame('granted', (string) ($failedOrder->grant_state ?? ''));
        $this->assertSame('fulfilled', (string) ($failedOrder->status ?? ''));
        $this->assertSame($tenant['org_id'], (int) ($failedEvent->org_id ?? 0));
        $this->assertSame('post_commit_failed', (string) ($failedEvent->status ?? ''));
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('report_snapshots')
                ->where('attempt_id', $attemptId)
                ->count()
        );

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => $tenant['org_id'],
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $repairedOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $repairedEvent = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', $providerEventId)
            ->first();

        $this->assertNotNull($repairedOrder);
        $this->assertNotNull($repairedEvent);
        $this->assertSame('paid', (string) ($repairedOrder->payment_state ?? ''));
        $this->assertSame('granted', (string) ($repairedOrder->grant_state ?? ''));
        $this->assertSame('fulfilled', (string) ($repairedOrder->status ?? ''));
        $this->assertSame($tenant['org_id'], (int) ($repairedEvent->org_id ?? 0));
        $this->assertSame('processed', (string) ($repairedEvent->status ?? ''));
        $this->assertSame('reprocessed', (string) ($repairedEvent->handle_status ?? ''));
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('order_no', $orderNo)
                ->where('status', 'active')
                ->count()
        );
        $this->assertSame(
            1,
            DB::table('report_snapshots')
                ->where('attempt_id', $attemptId)
                ->count()
        );

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
        $lookup->assertJsonPath('latest_payment_attempt.state', 'paid');

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
        $selectedOrg = $this->createOrganization('Post Commit Repair Ops Org');

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
            'name' => 'Post Commit Tenant User',
            'email' => 'post-commit-repair-tenant@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Post Commit Tenant Org',
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

        $anonId = 'anon_post_commit_'.$userId;
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
            'email' => 'post-commit-repair-tenant@example.com',
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
