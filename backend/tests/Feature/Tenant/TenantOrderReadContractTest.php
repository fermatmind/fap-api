<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Filament\Tenant\Resources\OrderResource;
use App\Models\Order;
use App\Models\TenantUser;
use App\Support\OrgContext;
use Filament\Infolists\Components\Component;
use Filament\Infolists\Infolist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class TenantOrderReadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_orders_list_uses_payment_state_and_unlock_truth(): void
    {
        $tenant = $this->createTenantUserWithOrg();
        $paidUnlocked = $this->insertOrder($tenant['org_id'], 'ord_tenant_paid_unlocked', 'fulfilled', 'paid');
        $paidNoGrant = $this->insertOrder($tenant['org_id'], 'ord_tenant_paid_no_grant', 'fulfilled', 'paid');

        $this->insertActiveGrant($tenant['org_id'], (string) $paidUnlocked->order_no);
        $this->bootstrapTenantContext($tenant['org_id'], (int) $tenant['user']->id);

        $records = OrderResource::getEloquentQuery()
            ->whereIn('order_no', [(string) $paidUnlocked->order_no, (string) $paidNoGrant->order_no])
            ->orderBy('order_no')
            ->get()
            ->keyBy('order_no');

        $this->assertArrayHasKey((string) $paidUnlocked->order_no, $records->all());
        $this->assertArrayHasKey((string) $paidNoGrant->order_no, $records->all());

        $paymentStatus = $this->resourceMethod('paymentStatus');
        $unlockStatus = $this->resourceMethod('unlockStatus');

        $this->assertSame('paid', $paymentStatus->invoke(null, $records[$paidUnlocked->order_no])['label']);
        $this->assertSame('unlocked', $unlockStatus->invoke(null, $records[$paidUnlocked->order_no])['label']);
        $this->assertSame('paid', $paymentStatus->invoke(null, $records[$paidNoGrant->order_no])['label']);
        $this->assertSame('paid_no_grant', $unlockStatus->invoke(null, $records[$paidNoGrant->order_no])['label']);
    }

    public function test_tenant_order_detail_shows_payment_grant_and_lifecycle_truths(): void
    {
        $tenant = $this->createTenantUserWithOrg();
        $order = $this->insertOrder($tenant['org_id'], 'ord_tenant_detail_paid_no_grant', 'fulfilled', 'paid');
        $this->bootstrapTenantContext($tenant['org_id'], (int) $tenant['user']->id);

        $record = OrderResource::getEloquentQuery()
            ->whereKey($order->getKey())
            ->first();

        $this->assertNotNull($record);

        $infolist = OrderResource::infolist(Infolist::make()->record($record));
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

        $paymentStatus = $this->resourceMethod('paymentStatus');
        $grantStatus = $this->resourceMethod('grantStatus');
        $unlockStatus = $this->resourceMethod('unlockStatus');

        $this->assertSame('paid', $paymentStatus->invoke(null, $record)['label']);
        $this->assertSame('missing', $grantStatus->invoke(null, $record)['label']);
        $this->assertSame('paid_no_grant', $unlockStatus->invoke(null, $record)['label']);
        $this->assertSame('fulfilled', (string) ($record->status ?? ''));
    }

    public function test_tenant_payment_truth_does_not_fallback_from_lifecycle_status(): void
    {
        $tenant = $this->createTenantUserWithOrg();
        $order = $this->insertOrder($tenant['org_id'], 'ord_tenant_fulfilled_failed_payment_state', 'fulfilled', 'failed');
        $this->bootstrapTenantContext($tenant['org_id'], (int) $tenant['user']->id);

        $record = OrderResource::getEloquentQuery()
            ->whereKey($order->getKey())
            ->first();

        $this->assertNotNull($record);

        $paymentStatus = $this->resourceMethod('paymentStatus');
        $unlockStatus = $this->resourceMethod('unlockStatus');

        $this->assertSame('failed', $paymentStatus->invoke(null, $record)['label']);
        $this->assertSame('pending', $unlockStatus->invoke(null, $record)['label']);
        $this->assertSame('fulfilled', (string) ($record->status ?? ''));
    }

    /**
     * @return array{user:TenantUser,org_id:int}
     */
    private function createTenantUserWithOrg(): array
    {
        $user = TenantUser::query()->create([
            'name' => 'Tenant Commerce User',
            'email' => 'tenant-commerce-'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Tenant Commerce Org',
            'owner_user_id' => (int) $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => (int) $user->id,
            'role' => 'owner',
            'is_active' => 1,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'org_id' => $orgId];
    }

    private function insertOrder(int $orgId, string $orderNo, string $status, ?string $paymentState): Order
    {
        $orderId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => 'anon_'.$orderNo,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 299,
            'amount_total' => 299,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => $status,
            'payment_state' => $paymentState,
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'channel' => 'web',
            'provider_app' => null,
            'provider_order_id' => null,
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'paid_at' => now()->subMinutes(5),
            'fulfilled_at' => strtolower($status) === 'fulfilled' ? now()->subMinutes(4) : null,
            'refunded_at' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(3),
        ]);

        return Order::query()->findOrFail($orderId);
    }

    private function insertActiveGrant(int $orgId, string $orderNo): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => null,
            'status' => 'active',
            'expires_at' => null,
            'benefit_type' => 'report_unlock',
            'benefit_ref' => 'grant_'.$orderNo,
            'order_no' => $orderNo,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => (string) Str::uuid(),
            'meta_json' => null,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);
    }

    private function resourceMethod(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(OrderResource::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function bootstrapTenantContext(int $orgId, int $userId): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, $userId, 'owner', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
    }
}
