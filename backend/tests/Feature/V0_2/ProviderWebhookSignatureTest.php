<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProviderWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signature_with_timestamp_returns_200_and_updates_receipt(): void
    {
        config([
            'services.integrations.webhook_tolerance_seconds' => 300,
            'services.integrations.providers.mock.webhook_secret' => 'mock_secret',
        ]);

        DB::table('integrations')->insert([
            'user_id' => null,
            'provider' => 'mock',
            'external_user_id' => 'ext_mock_1',
            'status' => 'connected',
            'scopes_json' => json_encode(['mock_scope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'consent_version' => 'v0.1',
            'connected_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'event_id' => 'evt_sig_ok',
            'external_user_id' => 'ext_mock_1',
            'recorded_at' => '2026-02-07T00:00:00Z',
            'samples' => [],
        ];
        $rawBody = $this->encodePayload($payload);
        $timestamp = time();
        $signature = $this->buildTimestampSignature('mock_secret', $rawBody, $timestamp);

        $response = $this->call(
            'POST',
            '/api/v0.2/webhooks/mock',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody,
        );

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'event_id' => 'evt_sig_ok',
            'external_user_id' => 'ext_mock_1',
        ]);

        $row = DB::table('integrations')
            ->where('provider', 'mock')
            ->where('external_user_id', 'ext_mock_1')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('evt_sig_ok', (string) ($row->webhook_last_event_id ?? ''));
        $this->assertSame($timestamp, (int) ($row->webhook_last_timestamp ?? 0));
        $this->assertNotNull($row->webhook_last_received_at ?? null);
    }

    public function test_missing_timestamp_header_returns_404_when_secret_is_configured(): void
    {
        config([
            'services.integrations.webhook_tolerance_seconds' => 300,
            'services.integrations.providers.mock.webhook_secret' => 'mock_secret',
        ]);

        $payload = [
            'event_id' => 'evt_missing_timestamp',
            'external_user_id' => 'ext_mock_missing',
            'recorded_at' => '2026-02-07T00:00:00Z',
            'samples' => [],
        ];
        $rawBody = $this->encodePayload($payload);
        $signature = hash_hmac('sha256', $rawBody, 'mock_secret');

        $response = $this->call(
            'POST',
            '/api/v0.2/webhooks/mock',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody,
        );

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_expired_timestamp_returns_404_when_secret_is_configured(): void
    {
        config([
            'services.integrations.webhook_tolerance_seconds' => 300,
            'services.integrations.providers.mock.webhook_secret' => 'mock_secret',
        ]);

        $payload = [
            'event_id' => 'evt_expired_signature',
            'external_user_id' => 'ext_mock_expired',
            'recorded_at' => '2026-02-07T00:00:00Z',
            'samples' => [],
        ];
        $rawBody = $this->encodePayload($payload);
        $timestamp = time() - 301;
        $signature = $this->buildTimestampSignature('mock_secret', $rawBody, $timestamp);

        $response = $this->call(
            'POST',
            '/api/v0.2/webhooks/mock',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody,
        );

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    private function buildTimestampSignature(string $secret, string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    private function encodePayload(array $payload): string
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawBody)) {
            self::fail('json_encode payload failed.');
        }

        return $rawBody;
    }
}
