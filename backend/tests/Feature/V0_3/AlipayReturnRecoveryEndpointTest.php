<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Yansongda\Pay\Pay;

use function Yansongda\Artful\filter_params;

final class AlipayReturnRecoveryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Pay::clear();
    }

    protected function tearDown(): void
    {
        Pay::clear();

        parent::tearDown();
    }

    public function test_recover_alipay_return_returns_tokenized_wait_context_for_valid_signed_query(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'app.frontend_url' => 'https://web.example.test',
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_1');
        $payload = [
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_1',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_alipay_return_recovery_1',
            'X-Locale' => 'en',
        ])->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('order_no', $orderNo);
        $response->assertJsonPath('payment_recovery_token', fn ($value) => is_string($value) && $value !== '');
        $this->assertStringContainsString('/en/pay/wait?order_no='.$orderNo, (string) $response->json('wait_url'));
    }

    public function test_recover_alipay_return_rejects_invalid_signature(): void
    {
        config([
            'payments.providers.alipay.enabled' => true,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_2');

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_alipay_return_recovery_2',
        ])->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?out_trade_no='.$orderNo.'&trade_no=ali_trade_return_recovery_2&trade_status=TRADE_SUCCESS');

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_recover_alipay_return_rejects_order_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_3');
        $payload = [
            'out_trade_no' => 'ord_other_return_recovery',
            'trade_no' => 'ali_trade_return_recovery_3',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'ORDER_MISMATCH');
    }

    private function insertOrder(string $anonId): string
    {
        $orderNo = 'ord_alipay_recover_'.Str::random(8);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 1990,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'alipay',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 1990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        return $orderNo;
    }

    /**
     * @return array{private: string, public: string}
     */
    private function generateRsaKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            self::fail('failed to generate rsa keypair.');
        }

        $privateKey = '';
        $exported = openssl_pkey_export($resource, $privateKey);
        if ($exported !== true || trim($privateKey) === '') {
            self::fail('failed to export private key.');
        }

        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || trim((string) ($details['key'] ?? '')) === '') {
            self::fail('failed to get public key.');
        }

        return [
            'private' => $privateKey,
            'public' => (string) $details['key'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function buildAlipaySignature(array $payload, string $privateKey): string
    {
        $content = filter_params(
            $payload,
            static fn ($key, $value): bool => (string) $value !== '' && $key !== 'sign' && $key !== 'sign_type'
        )->sortKeys()->toString();

        $signature = '';
        $ok = openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($ok !== true) {
            self::fail('failed to sign alipay payload.');
        }

        return base64_encode($signature);
    }
}
