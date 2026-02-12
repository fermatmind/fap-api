<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ErrorContractConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string|null> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    public function test_lookup_ticket_invalid_format_returns_unified_error_contract(): void
    {
        $response = $this->getJson('/api/v0.2/lookup/ticket/BAD');

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'INVALID_FORMAT');
        $this->assertUnifiedErrorContract($response);
    }

    public function test_lookup_order_disabled_returns_unified_error_contract(): void
    {
        $this->setEnv('LOOKUP_ORDER', '0');

        $response = $this->postJson('/api/v0.2/lookup/order', [
            'order_no' => 'ord_contract_disabled',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error_code', 'NOT_ENABLED');
        $this->assertUnifiedErrorContract($response);
    }

    public function test_validity_feedback_disabled_returns_unified_error_contract(): void
    {
        $this->setEnv('FEEDBACK_ENABLED', '0');
        $token = $this->seedFmToken('anon_contract_feedback');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/attempts/' . (string) Str::uuid() . '/feedback', [
            'score' => 5,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error_code', 'NOT_ENABLED');
        $this->assertUnifiedErrorContract($response);
    }

    public function test_v03_payment_webhook_invalid_signature_returns_unified_error_contract(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_error_contract_test',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $raw = json_encode([
            'id' => 'evt_contract_invalid_sig',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_contract_invalid_sig',
                    'metadata' => ['order_no' => 'ord_contract_invalid_sig'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($raw)) {
            self::fail('Failed to encode webhook payload.');
        }

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $raw
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
        $this->assertUnifiedErrorContract($response);
    }

    private function assertUnifiedErrorContract(TestResponse $response): void
    {
        $response->assertJsonPath('ok', false);
        $response->assertJsonMissingPath('error');

        $errorCode = (string) $response->json('error_code', '');
        $message = (string) $response->json('message', '');

        $this->assertNotSame('', trim($errorCode));
        $this->assertNotSame('', trim($message));
    }

    private function seedFmToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        $row = [
            'token' => $token,
            'anon_id' => $anonId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('fm_tokens', 'user_id')) {
            $row['user_id'] = null;
        }

        DB::table('fm_tokens')->insert($row);

        return $token;
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $current = getenv($key);
            $this->envBackup[$key] = $current === false ? null : (string) $current;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
