<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

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
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class PaymentWebhookLockBusyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_returns_webhook_busy_when_lock_is_busy(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);

        Cache::shouldReceive('lock')
            ->once()
            ->with('webhook_pay:billing:evt_busy', 10)
            ->andThrow(new LockTimeoutException('lock timeout'));

        $processor = new PaymentWebhookProcessor(
            Mockery::mock(OrderManager::class),
            Mockery::mock(SkuCatalog::class),
            Mockery::mock(BenefitWalletService::class),
            Mockery::mock(EntitlementManager::class),
            Mockery::mock(ReportSnapshotStore::class),
            new EventRecorder(new ExperimentAssigner()),
        );

        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_busy',
            'order_no' => 'ORD-BUSY',
        ], 0);

        $this->assertFalse($result['ok']);
        $this->assertSame('WEBHOOK_BUSY', $result['error_code']);
        $this->assertSame(500, $result['status']);
        $this->assertArrayNotHasKey('error', $result);
    }
}
