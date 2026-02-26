<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\FmTokenOptionalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FmTokenOptionalAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoked_token_is_rejected(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => 9001,
            'anon_id' => 'anon-9001',
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'revoked_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth)->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => 9002,
            'anon_id' => 'anon-9002',
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'revoked_at' => null,
            'expires_at' => now()->subMinute(),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth)->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_auth_tokens_row_is_accepted_without_legacy_row(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('auth_tokens')->insert([
            'token_hash' => $tokenHash,
            'user_id' => 9010,
            'anon_id' => 'anon-9010',
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'revoked_at' => null,
            'expires_at' => now()->addDay(),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth)->handle($request, static function (Request $req) {
            return response()->json([
                'ok' => true,
                'fm_user_id' => (string) $req->attributes->get('fm_user_id', ''),
                'anon_id' => (string) $req->attributes->get('anon_id', ''),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('9010', (string) ($payload['fm_user_id'] ?? ''));
        $this->assertSame('anon-9010', (string) ($payload['anon_id'] ?? ''));
    }

    public function test_auth_tokens_lookup_takes_precedence_over_legacy_table(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('auth_tokens')->insert([
            'token_hash' => $tokenHash,
            'user_id' => 9011,
            'anon_id' => 'anon-9011',
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'revoked_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fm_tokens')->insert([
            'token' => 'retired_'.$tokenHash,
            'token_hash' => $tokenHash,
            'user_id' => '9011',
            'anon_id' => 'anon-legacy-9011',
            'org_id' => 0,
            'role' => 'public',
            'revoked_at' => null,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth)->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }
}
