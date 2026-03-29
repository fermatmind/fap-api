<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthGuestTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_token_can_be_issued_with_body_anon_id(): void
    {
        $anonId = 'anon_guest_body_001';

        $response = $this->postJson('/api/v0.3/auth/guest', [
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(200)->assertJson([
            'ok' => true,
            'anon_id' => $anonId,
        ]);

        $token = (string) $response->json('fm_token');
        $this->assertMatchesRegularExpression('/^fm_[0-9a-fA-F-]{36}$/', $token);
        $this->assertSame($token, (string) $response->json('token'));
        $this->assertSame($token, (string) $response->json('auth_token'));

        $legacyRow = DB::table('fm_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $this->assertNull($legacyRow);

        $authRow = DB::table('auth_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $this->assertNotNull($authRow);
        $this->assertSame($anonId, (string) ($authRow->anon_id ?? ''));
    }

    public function test_guest_token_uses_transport_anon_id_when_body_is_missing(): void
    {
        $anonId = 'anon_guest_header_001';

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/auth/guest', []);

        $response->assertStatus(200)->assertJson([
            'ok' => true,
            'anon_id' => $anonId,
        ]);
    }

    public function test_guest_token_generates_anon_id_when_missing(): void
    {
        $response = $this->postJson('/api/v0.3/auth/guest', []);
        $response->assertStatus(200)->assertJson([
            'ok' => true,
        ]);

        $anonId = (string) $response->json('anon_id');
        $this->assertNotSame('', $anonId);
        $this->assertMatchesRegularExpression('/^anon_[0-9a-fA-F-]{36}$/', $anonId);
    }

    public function test_guest_token_does_not_use_numeric_body_anon_id(): void
    {
        $response = $this->postJson('/api/v0.3/auth/guest', [
            'anon_id' => '1',
        ]);

        $response->assertStatus(200)->assertJson([
            'ok' => true,
        ]);

        $anonId = (string) $response->json('anon_id');
        $this->assertMatchesRegularExpression('/^anon_[0-9a-fA-F-]{36}$/', $anonId);

        $token = (string) $response->json('fm_token');
        $authRow = DB::table('auth_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $this->assertNotNull($authRow);
        $this->assertNull($authRow->user_id);
        $this->assertSame($anonId, (string) ($authRow->anon_id ?? ''));
    }

    public function test_guest_token_rejects_invalid_anon_id_payload(): void
    {
        $response = $this->postJson('/api/v0.3/auth/guest', [
            'anon_id' => str_repeat('a', 129),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'ok',
            'error_code',
            'message',
            'details',
            'request_id',
        ]);
    }
}
