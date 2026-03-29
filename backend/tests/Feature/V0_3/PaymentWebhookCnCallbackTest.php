<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Yansongda\Pay\Pay;

use function Yansongda\Artful\filter_params;

final class PaymentWebhookCnCallbackTest extends TestCase
{
    use MockeryPHPUnitIntegration;
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

    public function test_wechatpay_invalid_callback_returns_400_invalid_signature(): void
    {
        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldNotReceive('process');
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/wechatpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"foo":"bar"}'
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_wechatpay_valid_callback_calls_processor_and_returns_success_ack(): void
    {
        $mchSecretKey = '12345678901234567890123456789012';
        config([
            'payments.providers.wechatpay.enabled' => true,
            'pay.wechat.default.mch_secret_key' => $mchSecretKey,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with(
                'wechatpay',
                Mockery::on(static function (array $payload): bool {
                    return (string) data_get($payload, 'resource.ciphertext.out_trade_no', '') === 'ord_wechat_valid'
                        && (string) data_get($payload, 'resource.ciphertext.transaction_id', '') === 'wx_txn_valid'
                        && strtoupper((string) data_get($payload, 'resource.ciphertext.trade_state', '')) === 'SUCCESS';
                }),
                true
            )
            ->andReturn([
                'ok' => true,
                'provider_event_id' => 'payment_succeeded:wx_txn_valid',
                'order_no' => 'ord_wechat_valid',
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->buildWechatEncryptedPayload($mchSecretKey, [
            'out_trade_no' => 'ord_wechat_valid',
            'transaction_id' => 'wx_txn_valid',
            'trade_state' => 'SUCCESS',
            'success_time' => '2026-02-27T00:00:00+00:00',
            'amount' => [
                'total' => 4990,
                'currency' => 'CNY',
            ],
        ]);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/wechatpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $raw
        );

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'SUCCESS');
    }

    public function test_alipay_invalid_callback_returns_400_invalid_signature(): void
    {
        config([
            'payments.providers.alipay.enabled' => true,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldNotReceive('process');
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/alipay',
            [
                'out_trade_no' => 'ord_alipay_invalid',
                'trade_status' => 'TRADE_SUCCESS',
            ],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_ACCEPT' => 'application/json',
            ]
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_alipay_auto_enabled_configuration_is_accepted_by_webhook_boundary(): void
    {
        $files = $this->createTempCertFiles(4);

        try {
            config([
                'payments.providers.alipay.enabled' => false,
                'payments.providers.alipay.auto_enable_when_configured' => true,
                'pay.alipay.default.app_id' => 'alipay_auto_enabled_001',
                'pay.alipay.default.merchant_private_key_path' => $files[0],
                'pay.alipay.default.app_public_cert_path' => $files[1],
                'pay.alipay.default.alipay_public_cert_path' => $files[2],
                'pay.alipay.default.alipay_root_cert_path' => $files[3],
            ]);

            $processor = Mockery::mock(PaymentWebhookProcessor::class);
            $processor->shouldNotReceive('process');
            $this->app->instance(PaymentWebhookProcessor::class, $processor);

            $response = $this->call(
                'POST',
                '/api/v0.3/webhooks/payment/alipay',
                [
                    'out_trade_no' => 'ord_alipay_invalid_auto_enabled',
                    'trade_status' => 'TRADE_SUCCESS',
                ],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                    'HTTP_ACCEPT' => 'application/json',
                ]
            );

            $response->assertStatus(400);
            $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
        } finally {
            $this->cleanupTempFiles($files);
        }
    }

    public function test_alipay_valid_callback_calls_processor_and_returns_success_ack(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with(
                'alipay',
                Mockery::on(static function (array $payload): bool {
                    return (string) ($payload['out_trade_no'] ?? '') === 'ord_alipay_valid'
                        && (string) ($payload['trade_no'] ?? '') === 'ali_trade_valid'
                        && strtoupper((string) ($payload['trade_status'] ?? '')) === 'TRADE_SUCCESS';
                }),
                true
            )
            ->andReturn([
                'ok' => true,
                'provider_event_id' => 'payment_succeeded:ali_trade_valid',
                'order_no' => 'ord_alipay_valid',
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $payload = [
            'out_trade_no' => 'ord_alipay_valid',
            'trade_no' => 'ali_trade_valid',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '49.90',
            'notify_time' => '2026-02-27 09:30:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/alipay',
            $payload,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_ACCEPT' => 'application/json',
            ]
        );

        $response->assertStatus(200);
        $this->assertSame('success', trim((string) $response->getContent()));
    }

    /**
     * @param  array<string,mixed>  $transaction
     */
    private function buildWechatEncryptedPayload(string $mchSecretKey, array $transaction): string
    {
        $plain = json_encode($transaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($plain)) {
            self::fail('failed to encode wechat transaction payload.');
        }

        $nonce = '123456789012';
        $associatedData = 'transaction';
        $ciphertext = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $mchSecretKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );
        if (! is_string($ciphertext) || ! is_string($tag)) {
            self::fail('failed to encrypt wechat callback payload.');
        }

        $payload = [
            'id' => 'evt_wechat_valid_001',
            'event_type' => 'TRANSACTION.SUCCESS',
            'resource_type' => 'encrypt-resource',
            'summary' => 'Payment succeeded',
            'resource' => [
                'algorithm' => 'AEAD_AES_256_GCM',
                'ciphertext' => base64_encode($ciphertext.$tag),
                'nonce' => $nonce,
                'associated_data' => $associatedData,
            ],
        ];

        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($raw)) {
            self::fail('failed to encode wechat callback payload.');
        }

        return $raw;
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

    /**
     * @return array<int, string>
     */
    private function createTempCertFiles(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; $index++) {
            $path = tempnam(sys_get_temp_dir(), 'payment_webhook_cn_');
            if (! is_string($path) || trim($path) === '') {
                self::fail('failed to create temporary cert file.');
            }

            file_put_contents($path, 'dummy-cert-'.$index);
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
