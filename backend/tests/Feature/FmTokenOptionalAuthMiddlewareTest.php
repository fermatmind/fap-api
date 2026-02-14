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
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => '9001',
            'anon_id' => 'anon-9001',
            'org_id' => 0,
            'role' => 'public',
            'revoked_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth())->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => '9002',
            'anon_id' => 'anon-9002',
            'org_id' => 0,
            'role' => 'public',
            'revoked_at' => null,
            'expires_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/optional-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenOptionalAuth())->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }
}
