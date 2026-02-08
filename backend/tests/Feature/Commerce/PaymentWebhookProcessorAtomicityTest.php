<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\BenefitWalletService;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PaymentWebhookProcessorAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_atomic_rollback_then_retry_can_process_same_event(): void
    {
        (new Pr19CommerceSeeder())->run();

        $orderNo = 'ord_atomic_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stub',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $payload = [
            'provider_event_id' => 'evt_atomic_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_atomic_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        $walletService = Mockery::mock(BenefitWalletService::class)->makePartial();
        $callCount = 0;
        $walletService
            ->shouldReceive('topUp')
            ->twice()
            ->andReturnUsing(function (
                int $orgId,
                string $benefitCode,
                int $delta,
                string $idempotencyKey,
                array $meta = []
            ) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('simulated_atomicity_failure');
                }

                $real = new BenefitWalletService();
                return $real->topUp($orgId, $benefitCode, $delta, $idempotencyKey, $meta);
            });
        $this->app->instance(BenefitWalletService::class, $walletService);

        $first = $this->postJson('/api/v0.3/webhooks/payment/stub', $payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(500);

        $this->assertSame(0, DB::table('payment_events')
            ->where('provider', 'stub')
            ->where('provider_event_id', 'evt_atomic_1')
            ->count());

        $second = $this->postJson('/api/v0.3/webhooks/payment/stub', $payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'duplicate' => false,
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'stub')
            ->where('provider_event_id', 'evt_atomic_1')
            ->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
    }
}
