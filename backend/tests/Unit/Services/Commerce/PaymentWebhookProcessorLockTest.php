<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

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

final class PaymentWebhookProcessorLockTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_returns_webhook_busy_when_lock_times_out(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);

        $lock = new class
        {
            public function block(int $seconds, callable $callback): mixed
            {
                throw new LockTimeoutException('lock timeout');
            }
        };

        Cache::shouldReceive('lock')
            ->once()
            ->with('webhook_pay:billing:evt_lock', 10)
            ->andReturn($lock);

        $processor = new PaymentWebhookProcessor(
            Mockery::mock(OrderManager::class),
            Mockery::mock(SkuCatalog::class),
            Mockery::mock(BenefitWalletService::class),
            Mockery::mock(EntitlementManager::class),
            Mockery::mock(ReportSnapshotStore::class),
            new EventRecorder(new ExperimentAssigner()),
        );

        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_lock',
            'order_no' => 'ORD-LOCK',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(500, $result['status']);
        $this->assertSame('WEBHOOK_BUSY', $result['error']);
        $this->assertSame('WEBHOOK_BUSY', $result['error_code']);
    }
}
