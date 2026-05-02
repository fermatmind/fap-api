<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class OrderExceptionFilterTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    public function test_exception_filter_can_isolate_paid_no_grant_and_callback_missing_orders(): void
    {
        $paidNoGrant = $this->seedCommerceOpsChain(orgId: 71, orderNo: 'ord_paid_no_grant_ops', options: [
            'with_grant' => false,
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
            'payment_attempt_state' => 'paid',
        ]);
        $callbackMissing = $this->seedCommerceOpsChain(orgId: 71, orderNo: 'ord_callback_missing_ops', options: [
            'with_grant' => false,
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'status' => 'pending',
            'payment_attempt_state' => 'verified',
        ]);
        $clear = $this->seedCommerceOpsChain(orgId: 71, orderNo: 'ord_clear_ops');
        $this->setOpsOrgContext(71);

        $support = app(OrderLinkageSupport::class);

        $paidNoGrantQuery = $support->query();
        $support->applyExceptionFilter($paidNoGrantQuery, 'paid_no_grant');
        $paidNoGrantOrders = $paidNoGrantQuery->pluck('order_no')->all();

        $callbackMissingQuery = $support->query();
        $support->applyExceptionFilter($callbackMissingQuery, 'callback_missing');
        $callbackMissingOrders = $callbackMissingQuery->pluck('order_no')->all();

        $this->assertContains($paidNoGrant['order_no'], $paidNoGrantOrders);
        $this->assertNotContains($clear['order_no'], $paidNoGrantOrders);
        $this->assertContains($callbackMissing['order_no'], $callbackMissingOrders);
        $this->assertNotContains($clear['order_no'], $callbackMissingOrders);
    }
}
