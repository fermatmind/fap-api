<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\SchemaBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuthGuestNo500FallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_guest_falls_back_to_legacy_table_when_auth_tokens_is_unavailable(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        Schema::dropIfExists('auth_tokens');
        SchemaBaseline::clearCache();

        $response = $this->postJson('/api/v0.3/auth/guest', [
            'anon_id' => 'anon_guest_fallback_001',
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertStatus(200);

        $token = (string) $response->json('fm_token');
        $this->assertMatchesRegularExpression('/^fm_[0-9a-fA-F-]{36}$/', $token);

        $legacyRow = DB::table('fm_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $this->assertNotNull($legacyRow);
    }
}
