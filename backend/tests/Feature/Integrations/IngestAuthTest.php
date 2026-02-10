<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IngestAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_without_auth_and_without_signature_returns_401(): void
    {
        $response = $this->postJson('/api/v0.2/integrations/mock/ingest', $this->payload());

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
        ]);
    }

    public function test_ingest_with_authenticated_user_uses_auth_id_and_ignores_body_user_id(): void
    {
        config()->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v0.2/integrations/mock/ingest', $this->payload([
                'user_id' => '42',
            ]));

        $response->assertStatus(200)->assertJson(['ok' => true]);

        $batchId = (string) $response->json('batch_id');
        $batch = DB::table('ingest_batches')->where('id', $batchId)->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, (int) $batch->user_id);
        $this->assertSame($user->id, (int) ($batch->actor_user_id ?? 0));
        $this->assertSame('sanctum', (string) ($batch->auth_mode ?? ''));
        $this->assertSame(0, (int) ($batch->signature_ok ?? 0));

        $sleep = DB::table('sleep_samples')->where('ingest_batch_id', $batchId)->first();
        $this->assertNotNull($sleep);
        $this->assertSame($user->id, (int) $sleep->user_id);
    }

    public function test_ingest_with_valid_signature_and_binding_writes_bound_user_id(): void
    {
        config()->set('integrations.providers.mock.secret', 'mock_ingest_secret');

        $boundUser = User::factory()->create();
        DB::table('integration_user_bindings')->insert([
            'provider' => 'mock',
            'external_user_id' => 'ext_001',
            'user_id' => $boundUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->payload([
            'external_user_id' => 'ext_001',
            'user_id' => '42',
        ]);
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($rawBody);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", 'mock_ingest_secret');

        $response = $this->call(
            'POST',
            '/api/v0.2/integrations/mock/ingest',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_INTEGRATION_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_INTEGRATION_SIGNATURE' => $signature,
            ],
            $rawBody
        );

        $response->assertStatus(200)->assertJson(['ok' => true]);

        $batchId = (string) $response->json('batch_id');
        $batch = DB::table('ingest_batches')->where('id', $batchId)->first();

        $this->assertNotNull($batch);
        $this->assertSame($boundUser->id, (int) $batch->user_id);
        $this->assertNull($batch->actor_user_id ?? null);
        $this->assertSame('signature', (string) ($batch->auth_mode ?? ''));
        $this->assertSame(1, (int) ($batch->signature_ok ?? 0));
    }

    public function test_ingest_with_invalid_signature_returns_401(): void
    {
        config()->set('integrations.providers.mock.secret', 'mock_ingest_secret');

        $payload = $this->payload(['external_user_id' => 'ext_001']);
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($rawBody);

        $timestamp = time();
        $badSignature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", 'wrong_secret');

        $response = $this->call(
            'POST',
            '/api/v0.2/integrations/mock/ingest',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_INTEGRATION_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_INTEGRATION_SIGNATURE' => $badSignature,
            ],
            $rawBody
        );

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'meta' => [
                'range_start' => '2026-02-10T00:00:00Z',
                'range_end' => '2026-02-10T01:00:00Z',
            ],
            'samples' => [
                [
                    'domain' => 'sleep',
                    'recorded_at' => '2026-02-10T00:00:00Z',
                    'value' => ['duration_minutes' => 420],
                    'external_id' => 'sleep_001',
                ],
            ],
        ], $overrides);
    }
}
