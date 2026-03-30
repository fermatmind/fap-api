<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\CommerceKpiWidget;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class CommerceKpiExceptionSummaryTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    public function test_commerce_kpi_widget_uses_exception_oriented_summary(): void
    {
        $orgId = 75;

        $this->seedCommerceOpsChain($orgId, 'ord_paid_today_ops', [
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(15),
        ]);
        $this->seedCommerceOpsChain($orgId, 'ord_pending_ops', [
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'status' => 'pending',
            'payment_attempt_state' => 'provider_created',
            'with_grant' => false,
        ]);
        $this->seedCommerceOpsChain($orgId, 'ord_paid_no_grant_ops_widget', [
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
            'with_grant' => false,
            'paid_at' => now()->subMinutes(12),
        ]);
        $this->seedCommerceOpsChain($orgId, 'ord_compensated_ops', [
            'payment_state' => 'expired',
            'grant_state' => 'not_started',
            'status' => 'canceled',
            'with_grant' => false,
            'last_reconciled_at' => now()->subMinutes(8),
        ]);
        $this->seedCommerceOpsChain($orgId, 'ord_webhook_error_ops', [
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'status' => 'pending',
            'payment_event_status' => 'failed',
            'payment_event_handle_status' => 'failed',
            'signature_ok' => 0,
            'with_grant' => false,
        ]);

        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        $widget = app(CommerceKpiWidget::class);
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke($widget);

        $labels = array_map(fn ($stat): string => (string) $stat->getLabel(), $stats);
        $valuesByLabel = [];
        foreach ($stats as $stat) {
            $valuesByLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        $this->assertContains('Pending unresolved', $labels);
        $this->assertContains('Paid without grant', $labels);
        $this->assertContains('Compensated today', $labels);
        $this->assertSame('2', $valuesByLabel[__('ops.widgets.paid_orders_today')] ?? null);
        $this->assertSame('2', $valuesByLabel['Pending unresolved'] ?? null);
        $this->assertSame('1', $valuesByLabel['Paid without grant'] ?? null);
        $this->assertSame('1', $valuesByLabel['Compensated today'] ?? null);
    }

    public function test_commerce_kpi_widget_includes_public_realm_orders_in_selected_org_summary(): void
    {
        $orgId = 75;

        $this->seedCommerceOpsChain($orgId, 'ord_tenant_paid_today_ops', [
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(12),
        ]);

        $this->seedCommerceOpsChain(0, 'ord_public_paid_today_ops', [
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(9),
            'payment_event_status' => 'failed',
            'payment_event_handle_status' => 'failed',
            'signature_ok' => 0,
        ]);

        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        $widget = app(CommerceKpiWidget::class);
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke($widget);

        $valuesByLabel = [];
        foreach ($stats as $stat) {
            $valuesByLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        $this->assertSame('2', $valuesByLabel[__('ops.widgets.paid_orders_today')] ?? null);
        $this->assertSame('1', $valuesByLabel[__('ops.widgets.webhook_failures')] ?? null);
    }

    public function test_commerce_kpi_widget_uses_placeholder_when_no_org_is_selected(): void
    {
        $context = app(OrgContext::class);
        $context->set(0, null, null, null, OrgContext::KIND_PUBLIC);
        app()->instance(OrgContext::class, $context);

        $widget = app(CommerceKpiWidget::class);
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke($widget);

        $this->assertCount(6, $stats);
        $this->assertSame('—', (string) $stats[0]->getValue());
        $this->assertSame(__('ops.widgets.select_org_to_view_metrics'), (string) $stats[0]->getDescription());

        foreach ($stats as $stat) {
            $this->assertSame('—', (string) $stat->getValue());
            $this->assertNotSame('0', (string) $stat->getValue());
        }

        $this->assertSame(__('ops.widgets.select_org_to_view_metrics'), (string) $stats[1]->getDescription());
    }
}
