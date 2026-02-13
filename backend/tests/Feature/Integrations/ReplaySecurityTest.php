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
}
