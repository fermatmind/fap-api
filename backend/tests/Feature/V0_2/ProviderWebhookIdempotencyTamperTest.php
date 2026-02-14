<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ProviderWebhookIdempotencyTamperTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_event_identity_with_different_payload_returns_conflict(): void
    {
        config([
            'services.integrations.webhook_tolerance_seconds' => 300,
            'services.integrations.providers.mock.webhook_secret' => 'mock_secret_tamper',
        ]);

        $eventId = 'evt_tamper_identity_1';
        $recordedAt = '2026-02-07T00:00:00Z';

        $first = $this->postSignedMockWebhook([
            'event_id' => $eventId,
            'external_user_id' => 'ext_tamper_1',
            'recorded_at' => $recordedAt,
            'samples' => [],
        ], 'mock_secret_tamper');
        $first->assertStatus(200);
        $first->assertJsonPath('ok', true);

        $second = $this->postSignedMockWebhook([
            'event_id' => $eventId,
            'external_user_id' => 'ext_tamper_1',
            'recorded_at' => $recordedAt,
            'samples' => [
                [
                    'domain' => 'sleep',
                    'recorded_at' => '2026-02-06T23:59:00Z',
                    'value' => ['minutes' => 421],
                ],
            ],
        ], 'mock_secret_tamper');

        $second->assertStatus(409);
        $second->assertJsonPath('ok', false);
        $second->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, DB::table('idempotency_keys')
            ->where('provider', 'mock')
            ->where('external_id', $eventId)
            ->count());
    }

    private function postSignedMockWebhook(array $payload, string $secret): TestResponse
    {
        $timestamp = time();
        $raw = $this->encodePayload($payload);
        $signature = hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);

        return $this->call(
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
            $raw,
        );
    }

    private function encodePayload(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        return $raw;
    }
}
