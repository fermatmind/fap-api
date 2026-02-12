<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IntegrationsWebhookPayloadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_payload_returns_413_and_does_not_write_ingestion_tables(): void
    {
        config(['integrations.webhook_max_payload_bytes' => 262144]);

        $rawBody = json_encode([
            'event_id' => 'evt_payload_limit_413',
            'external_user_id' => 'ext_payload_limit_413',
            'recorded_at' => '2026-02-12T00:00:00Z',
            'samples' => [],
            'blob' => str_repeat('a', 262145),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(262144, strlen($rawBody));

        $response = $this->call(
            'POST',
            '/api/v0.2/webhooks/mock',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $rawBody,
        );

        $response->assertStatus(413)->assertJson([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
        ]);
        $response->assertJsonPath('details.max_bytes', 262144);
        $response->assertJsonPath('details.len_bytes', strlen($rawBody));

        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }
}
