<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReplaySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_requires_fm_token_auth(): void
    {
        $batchId = (string) Str::uuid();
        $this->seedBatch($batchId, 'mock', 1001);

        $response = $this->postJson("/api/v0.2/integrations/mock/replay/{$batchId}");

        $response->assertStatus(401)->assertJson([
            'ok' => false,
        ]);
    }

    public function test_replay_denies_non_owner_without_privileged_role(): void
    {
        $batchId = (string) Str::uuid();
        $this->seedBatch($batchId, 'mock', 1001);

        $token = $this->seedUserAndToken(1002, 'public');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/integrations/mock/replay/{$batchId}");

        $response->assertStatus(404)->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_replay_rejects_provider_mismatch_for_same_batch(): void
    {
        $batchId = (string) Str::uuid();
        $this->seedBatch($batchId, 'mock', 1001);

        $token = $this->seedUserAndToken(1001, 'public');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/integrations/google_fit/replay/{$batchId}");

        $response->assertStatus(404)->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);

        $batch = DB::table('ingest_batches')->where('id', $batchId)->first();
        $this->assertNotNull($batch);
        $this->assertSame('received', (string) $batch->status);
    }

    public function test_replay_rejects_anon_only_token_without_user_identity(): void
    {
        $batchId = (string) Str::uuid();
        $this->seedBatch($batchId, 'mock', 1001);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => 'replay-anon-only',
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/integrations/mock/replay/{$batchId}");

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => 'missing_identity',
        ]);

        $batch = DB::table('ingest_batches')->where('id', $batchId)->first();
        $this->assertNotNull($batch);
        $this->assertSame('received', (string) $batch->status);
    }

    public function test_replay_is_idempotent_on_repeated_calls_for_same_batch(): void
    {
        $batchId = (string) Str::uuid();
        $this->seedBatch($batchId, 'mock', 1001);
        $this->seedSleepSample($batchId, 1001, '2026-02-01 08:00:00');

        $token = $this->seedUserAndToken(1001, 'public');

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/integrations/mock/replay/{$batchId}");

        $first->assertStatus(200)->assertJson([
            'ok' => true,
        ]);
        $this->assertSame(1, (int) $first->json('inserted'));

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/integrations/mock/replay/{$batchId}");

        $second->assertStatus(200)->assertJson([
            'ok' => true,
            'inserted' => 0,
        ]);

        $this->assertSame(2, DB::table('sleep_samples')->where('ingest_batch_id', $batchId)->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    private function seedUserAndToken(int $userId, string $role): string
    {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "replay-user-{$userId}",
            'email' => "replay-user-{$userId}@example.com",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => "replay-anon-{$userId}",
            'org_id' => 0,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedBatch(string $batchId, string $provider, int $userId): void
    {
        DB::table('ingest_batches')->insert([
            'id' => $batchId,
            'provider' => $provider,
            'user_id' => $userId,
            'status' => 'received',
            'created_at' => now(),
        ]);
    }

    private function seedSleepSample(string $batchId, int $userId, string $recordedAt): void
    {
        DB::table('sleep_samples')->insert([
            'user_id' => $userId,
            'source' => 'mock',
            'recorded_at' => $recordedAt,
            'value_json' => json_encode(['sleep_minutes' => 420], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'confidence' => 1.0,
            'raw_payload_hash' => hash('sha256', 'sleep-sample-'.$batchId.'-'.$recordedAt),
            'ingest_batch_id' => $batchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
