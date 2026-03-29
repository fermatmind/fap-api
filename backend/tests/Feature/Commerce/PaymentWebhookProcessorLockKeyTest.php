<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Services\Commerce\SkuCatalog;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class PaymentWebhookProcessorLockKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_uses_provider_org_event_scoped_lock_key_and_logs_contention_budget(): void
    {
        config()->set('services.payment_webhook.lock_ttl_seconds', 10);
        config()->set('services.payment_webhook.lock_block_seconds', 5);
        config()->set('services.payment_webhook.lock_contention_budget_ms', 2000);

        Schema::shouldReceive('hasTable')->andReturn(true);
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'PAYMENT_WEBHOOK_LOCK_CONTENTION'
                    && (string) ($context['provider'] ?? '') === 'billing'
                    && (int) ($context['org_id'] ?? 0) === 23
                    && (string) ($context['provider_event_id'] ?? '') === 'evt_lock_key'
                    && (int) ($context['contention_budget_ms'] ?? 0) === 2000
                    && is_bool($context['budget_exceeded'] ?? null);
            });

        $lock = Mockery::mock();
        $lock
            ->shouldReceive('block')
            ->once()
            ->with(5, Mockery::type('callable'))
            ->andThrow(new LockTimeoutException('lock timeout'));

        Cache::shouldReceive('lock')
            ->once()
            ->with('webhook_pay:billing:org_23:evt_lock_key', 10)
            ->andReturn($lock);

        $processor = new PaymentWebhookProcessor(
            Mockery::mock(OrderManager::class),
            Mockery::mock(SkuCatalog::class),
            Mockery::mock(BenefitWalletService::class),
            Mockery::mock(EntitlementManager::class),
            Mockery::mock(ReportSnapshotStore::class),
            new EventRecorder(new ExperimentAssigner),
        );

        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_lock_key',
            'order_no' => 'ORD-LOCK-KEY',
        ], 23);

        $this->assertFalse($result['ok']);
        $this->assertSame(500, $result['status']);
        $this->assertSame('WEBHOOK_BUSY', $result['error_code']);
        $this->assertArrayNotHasKey('error', $result);
    }
}
