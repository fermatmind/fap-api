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

    public function test_handle_uses_provider_scoped_lock_key_and_configured_timing(): void
    {
        config()->set('services.payment_webhook.lock_ttl_seconds', 10);
        config()->set('services.payment_webhook.lock_block_seconds', 5);

        Schema::shouldReceive('hasTable')->andReturn(true);

        $lock = Mockery::mock();
        $lock
            ->shouldReceive('block')
            ->once()
            ->with(5, Mockery::type('callable'))
            ->andThrow(new LockTimeoutException('lock timeout'));

        Cache::shouldReceive('lock')
            ->once()
            ->with('webhook_pay:stub:evt_lock_key', 10)
            ->andReturn($lock);

        $processor = new PaymentWebhookProcessor(
            Mockery::mock(OrderManager::class),
            Mockery::mock(SkuCatalog::class),
            Mockery::mock(BenefitWalletService::class),
            Mockery::mock(EntitlementManager::class),
            Mockery::mock(ReportSnapshotStore::class),
            new EventRecorder(new ExperimentAssigner()),
        );

        $result = $processor->handle('stub', [
            'provider_event_id' => 'evt_lock_key',
            'order_no' => 'ORD-LOCK-KEY',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(500, $result['status']);
        $this->assertSame('WEBHOOK_BUSY', $result['error']);
    }
}
