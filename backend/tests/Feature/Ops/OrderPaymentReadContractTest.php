<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class OrderPaymentReadContractTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    public function test_ops_support_separates_payment_truth_from_webhook_diagnostics(): void
    {
        $chain = $this->seedCommerceOpsChain(orgId: 72, orderNo: 'ord_ops_paid_event_failed', options: [
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'fulfilled',
            'with_grant' => false,
            'payment_event_status' => 'post_commit_failed',
            'payment_event_handle_status' => 'post_commit_failed',
        ]);
        $this->setOpsOrgContext(72);

        $record = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $chain['order_no'])
            ->first();

        $this->assertNotNull($record);

        $support = app(OrderLinkageSupport::class);
        $this->assertSame('paid', $support->paymentStatus($record)['label']);
        $this->assertSame('post_commit_failed', $support->webhookStatus($record)['label']);
        $this->assertSame('paid_no_grant', $support->unlockStatus($record)['label']);
    }

    public function test_paid_success_filter_only_uses_payment_state(): void
    {
        $paid = $this->seedCommerceOpsChain(orgId: 74, orderNo: 'ord_ops_paid_truth', options: [
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'pending',
        ]);
        $fulfilledButUnpaid = $this->seedCommerceOpsChain(orgId: 74, orderNo: 'ord_ops_fulfilled_but_failed', options: [
            'payment_state' => 'failed',
            'grant_state' => 'not_started',
            'status' => 'fulfilled',
            'with_grant' => false,
            'payment_event_status' => 'processed',
        ]);
        $this->setOpsOrgContext(74);

        $support = app(OrderLinkageSupport::class);
        $query = $support->query();
        $support->applyPaidSuccessFilter($query, true);
        $orderNos = $query->pluck('order_no')->all();

        $this->assertContains($paid['order_no'], $orderNos);
        $this->assertNotContains($fulfilledButUnpaid['order_no'], $orderNos);
    }

    public function test_ops_payment_truth_does_not_fallback_from_lifecycle_status(): void
    {
        $chain = $this->seedCommerceOpsChain(orgId: 75, orderNo: 'ord_ops_fulfilled_failed_payment_state', options: [
            'payment_state' => 'failed',
            'grant_state' => 'not_started',
            'status' => 'fulfilled',
            'with_grant' => false,
            'payment_event_status' => 'processed',
        ]);
        $this->setOpsOrgContext(75);

        $record = app(OrderLinkageSupport::class)
            ->query()
            ->where('orders.order_no', $chain['order_no'])
            ->first();

        $this->assertNotNull($record);

        $support = app(OrderLinkageSupport::class);
        $this->assertSame('failed', $support->paymentStatus($record)['label']);
        $this->assertSame('pending', $support->unlockStatus($record)['label']);
        $this->assertSame('clear', $support->primaryException($record)['label']);
    }
}
