<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\Compensation\Gateways\WaffoLifecycleGateway;
use App\Services\Payments\PaymentProviderRegistry;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WaffoMbtiTestIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $signingKey = '';

    private string $keyPath;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->signingKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyPath = tempnam(sys_get_temp_dir(), 'waffo-test-');
        file_put_contents($this->keyPath, $this->signingKey);
        chmod($this->keyPath, 0600);
        config()->set('payments.providers.waffo.enabled', true);
        config()->set('payments.waffo', ['environment' => 'test', 'merchant_id' => 'MER_test', 'store_id' => 'STO_test',
            'product_id' => 'PROD_fixture', 'private_key_path' => $this->keyPath, 'webhook_public_key' => $this->publicKey]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPath) && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        parent::tearDown();
    }

    private function prepare(): array
    {
        $this->seedScales();
        $row = (array) DB::table('skus')->where('sku', 'MBTI_REPORT_FULL_199')->first();
        unset($row['id']);
        $row['sku'] = 'MBTI_REPORT_FULL_USD_199_TEST';
        $row['currency'] = 'USD';
        DB::table('skus')->insert($row);
        foreach (['sku' => $row['sku'], 'upgrade_sku' => $row['sku'], 'currency' => 'USD'] as $key => $value) {
            config()->set('freemium_locale_policy.scales.MBTI.locales.zh-CN.'.$key, $value);
        }
        config()->set('report_unlock.mbti_access_mode', 'paid_unlock');
        $attempt = $this->createAttemptWithResult('anon_waffo_fixture');
        $token = $this->issueAnonToken('anon_waffo_fixture');
        Http::fake(['https://api.waffo.ai/v1/actions/checkout/create-session' => Http::sequence()->push(['data' => [
            'sessionId' => 'cs_fixture', 'checkoutUrl' => 'https://pancake.waffo.ai/store/fixture/checkout/cs_fixture?env=test',
        ]])->push(['data' => ['sessionId' => 'cs_renewed', 'checkoutUrl' => 'https://pancake.waffo.ai/store/fixture/checkout/cs_renewed']])->push([], 503)]);

        return [$attempt, $token];
    }

    private function checkout(string $attempt, string $token): string
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Fap-Locale' => 'zh-CN'])
            ->postJson('/api/v0.3/orders/checkout', ['provider' => 'waffo', 'sku' => 'MBTI_REPORT_FULL_USD_199_TEST', 'attempt_id' => $attempt])
            ->assertOk()->assertJsonPath('provider', 'waffo')->assertJsonPath('pay.type', 'redirect')->json('order_no');
    }

    private function event(string $order, string $attempt, string $type = 'order.completed'): array
    {
        return ['id' => 'PAY_fixture', 'eventId' => 'PAY_fixture', 'timestamp' => now()->toIso8601String(),
            'eventType' => $type, 'mode' => 'test', 'storeId' => 'STO_test', 'data' => [
                'orderId' => 'ORD_fixture', 'paymentId' => 'PAY_fixture', 'orderStatus' => 'completed', 'paymentStatus' => 'succeeded',
                'currency' => 'USD', 'chargedAmount' => '1.99', 'amount' => '1.99', 'refundedAmount' => '1.99',
                'originalPayment' => ['total' => '1.99'], 'orderMerchantExternalId' => $order,
                'orderMetadata' => ['fap_order_no' => $order, 'fap_attempt_id' => $attempt, 'fap_product_id' => 'PROD_fixture'],
            ]];
    }

    private function deliver(array $payload, ?int $timestamp = null, bool $tamper = false)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= (int) floor(microtime(true) * 1000);
        openssl_sign($timestamp.'.'.$raw, $sig, $this->signingKey, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/api/v0.3/webhooks/payment/waffo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WAFFO_SIGNATURE' => 't='.$timestamp.',v1='.base64_encode($sig),
        ], $raw.($tamper ? ' ' : ''));
    }

    public function test_checkout_signed_callback_duplicate_and_full_refund_deliver_and_revoke_one_report(): void
    {
        [$attempt, $token] = $this->prepare();
        $order = $this->checkout($attempt, $token);
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $order)->count());
        $payload = $this->event($order, $attempt);
        $this->deliver($payload)->assertOk()->assertJsonPath('ok', true);
        $this->deliver($payload)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $order)->count());
        $this->assertSame('paid', DB::table('orders')->where('order_no', $order)->value('payment_state'));
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v0.3/attempts/'.$attempt.'/report')
            ->assertOk()->assertJsonPath('locked', false);
        $refund = $this->event($order, $attempt, 'refund.succeeded');
        $refund['eventId'] = 'REF_fixture';
        $this->deliver($refund)->assertOk();
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $order)->where('status', 'active')->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v0.3/attempts/'.$attempt.'/report')
            ->assertOk()->assertJsonPath('locked', true);
    }

    public function test_invalid_signature_and_wrong_store_environment_currency_amount_do_not_grant(): void
    {
        [$attempt, $token] = $this->prepare();
        $order = $this->checkout($attempt, $token);
        $payload = $this->event($order, $attempt);
        $this->deliver($payload, tamper: true)->assertStatus(401);
        $this->deliver($payload, (int) floor(microtime(true) * 1000) - 46 * 60 * 1000)->assertStatus(401);
        foreach ([['mode', 'prod'], ['storeId', 'STO_other'], ['data.currency', 'CNY'], ['data.chargedAmount', '1.98'], ['data.chargedAmount', 1.99]] as [$path, $value]) {
            $bad = $payload;
            data_set($bad, $path, $value);
            $bad['eventId'] = Str::uuid()->toString();
            $this->deliver($bad)->assertJsonPath('ok', false);
        }
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $order)->count());
    }

    public function test_production_rejects_provider_even_with_test_enable_config(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled('waffo'));
        $this->postJson('/api/v0.3/webhooks/payment/waffo', [])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_outgoing_request_is_rsa_signed_and_preserves_fixed_price_without_buyer_pii(): void
    {
        [$attempt, $token] = $this->prepare();
        $this->checkout($attempt, $token);
        Http::assertSent(function ($request) {
            $t = $request->header('X-Timestamp')[0];
            $canonical = "POST\n/v1/actions/checkout/create-session\n".$t."\n".base64_encode(hash('sha256', $request->body(), true));
            $this->assertSame(1, openssl_verify($canonical, base64_decode($request->header('X-Signature')[0]), $this->publicKey, OPENSSL_ALGO_SHA256));
            $this->assertSame('1.99', $request['priceSnapshot']['amount']);
            $this->assertFalse($request['allowPromotionCodes']);
            $this->assertArrayNotHasKey('buyerEmail', $request->data());
            $this->assertStringContainsString('/pay/wait?order_no=', $request['successUrl']);

            return true;
        });
    }

    public function test_partial_refund_and_failed_refund_never_revoke_access(): void
    {
        [$attempt, $token] = $this->prepare();
        $order = $this->checkout($attempt, $token);
        $this->deliver($this->event($order, $attempt))->assertOk();
        foreach (['refund.failed', 'refund.succeeded'] as $type) {
            $p = $this->event($order, $attempt, $type);
            $p['eventId'] = 'REF_'.$type;
            $p['data']['refundedAmount'] = '0.99';
            $this->deliver($p)->assertJsonPath('ok', false);
        }
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $order)->where('status', 'active')->count());
    }

    public function test_authenticated_query_validates_original_price_and_does_not_close_unknown_orders(): void
    {
        $row = ['id' => 'ORD_fixture', 'orderMerchantExternalId' => 'local-fixture', 'status' => 'completed', 'currency' => 'USD',
            'metadata' => json_encode(['fap_order_no' => 'local-fixture', 'fap_product_id' => 'PROD_fixture']), 'priceSnapshot' => ['total' => '1.99']];
        Http::fake(['https://api.waffo.ai/v1/graphql' => Http::sequence()->push(['data' => ['onetimeOrders' => [$row]]])->push(['data' => ['payments' => [['id' => 'PAY_fixture', 'orderId' => 'ORD_fixture', 'status' => 'succeeded', 'refundStatus' => 'none', 'snapshotAmountDetails' => ['total' => '1.99', 'currency' => 'USD']]]]])->push(['data' => ['onetimeOrders' => [array_replace($row, ['priceSnapshot' => ['total' => '199.00']])]]])]);
        $gateway = app(WaffoLifecycleGateway::class);
        $context = ['order_no' => 'local-fixture', 'currency' => 'USD', 'amount_expected' => 199];
        $this->assertSame('paid', $gateway->queryPaymentStatus($context)['status']);
        $this->assertFalse($gateway->closePayment($context)['supported']);
        $row['priceSnapshot']['total'] = '199.00';
        $this->assertSame('unknown', $gateway->queryPaymentStatus($context)['status']);
    }

    public function test_expired_checkout_is_renewed_and_platform_failure_does_not_grant(): void
    {
        [$attempt, $token] = $this->prepare();
        $order = $this->checkout($attempt, $token);
        $lookup = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v0.3/orders/lookup', ['order_no' => $order]);
        $lookup()->assertOk()->assertJsonPath('pay.type', 'redirect');
        Http::assertSentCount(1);
        $this->travel(31)->minutes();
        $lookup()->assertOk()->assertJsonPath('pay.value', 'https://pancake.waffo.ai/store/fixture/checkout/cs_renewed');
        Http::assertSentCount(2);
        $this->travel(31)->minutes();
        $lookup()->assertOk()->assertJsonPath('pay', null);
        Http::assertSentCount(3);
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $order)->count());
    }

    public function test_refunded_platform_payment_cannot_be_reconciled_as_paid(): void
    {
        $row = ['id' => 'ORD_fixture', 'orderMerchantExternalId' => 'local-fixture', 'status' => 'completed', 'currency' => 'USD',
            'metadata' => ['fap_order_no' => 'local-fixture', 'fap_product_id' => 'PROD_fixture'], 'priceSnapshot' => ['total' => '1.99']];
        Http::fake(['https://api.waffo.ai/v1/graphql' => Http::sequence()->push(['data' => ['onetimeOrders' => [$row]]])
            ->push(['data' => ['payments' => [['id' => 'PAY_fixture', 'orderId' => 'ORD_fixture', 'status' => 'succeeded', 'refundStatus' => 'refunded', 'snapshotAmountDetails' => ['total' => '1.99', 'currency' => 'USD']]]]])]);
        $this->assertSame('unknown', app(WaffoLifecycleGateway::class)->queryPaymentStatus(['order_no' => 'local-fixture', 'currency' => 'USD', 'amount_expected' => 199])['status']);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
