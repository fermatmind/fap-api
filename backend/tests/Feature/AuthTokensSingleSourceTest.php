<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\FmTokenAuth;
use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthTokensSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fm_token_auth_rejects_legacy_only_token_row(): void
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('fm_tokens')->insert([
            'token' => 'retired_'.$tokenHash,
            'token_hash' => $tokenHash,
            'anon_id' => 'anon-legacy-only',
            'user_id' => null,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'meta_json' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/fm-token-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenAuth)->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_fm_token_service_writes_auth_tokens_only(): void
    {
        $issued = app(FmTokenService::class)->issueForUser('9101', [
            'anon_id' => 'anon-9101',
            'org_id' => 2,
            'role' => 'member',
        ]);

        $token = (string) ($issued['token'] ?? '');
        $this->assertNotSame('', $token);

        $tokenHash = hash('sha256', $token);
        $authRow = DB::table('auth_tokens')
            ->where('token_hash', $tokenHash)
            ->first();
        $legacyRow = DB::table('fm_tokens')
            ->where('token_hash', $tokenHash)
            ->first();

        $this->assertNotNull($authRow);
        $this->assertNull($legacyRow);
        $this->assertSame('anon-9101', (string) ($authRow->anon_id ?? ''));
        $this->assertSame(2, (int) ($authRow->org_id ?? 0));
        $this->assertSame('member', (string) ($authRow->role ?? ''));
    }

    public function test_fm_token_auth_accepts_auth_tokens_without_legacy_row(): void
    {
        $userId = 9102;
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'user_9102',
            'email' => 'user_9102@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('auth_tokens')->insert([
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'anon_id' => 'anon-9102',
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/_security/fm-token-auth', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = (new FmTokenAuth)->handle($request, static function (Request $req) {
            return response()->json([
                'ok' => true,
                'fm_user_id' => (string) $req->attributes->get('fm_user_id', ''),
                'anon_id' => (string) $req->attributes->get('anon_id', ''),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame((string) $userId, (string) ($payload['fm_user_id'] ?? ''));
        $this->assertSame('anon-9102', (string) ($payload['anon_id'] ?? ''));
        $this->assertNull(DB::table('fm_tokens')->where('token_hash', $tokenHash)->first());
    }
}
