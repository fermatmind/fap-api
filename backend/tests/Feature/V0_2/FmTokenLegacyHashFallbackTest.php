<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FmTokenLegacyHashFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_row_without_token_hash_is_accepted_and_self_healed(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy-hash@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => null,
            'user_id' => $userId,
            'anon_id' => 'legacy_anon',
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
            'meta_json' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v0.2/me/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('user_id', (string) $userId);

        $this->assertSame(
            hash('sha256', $token),
            DB::table('fm_tokens')->where('token', $token)->value('token_hash')
        );
    }
}
