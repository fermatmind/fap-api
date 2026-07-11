<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\FmTokenAuth;
use App\Http\Middleware\FmTokenOptional;
use App\Http\Middleware\FmTokenOptionalAuth;
use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SystemBearerTokenHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_token_requires_issuer_audience_short_ttl_and_exact_route_scope(): void
    {
        Log::spy();
        $token = $this->insertSystemToken([
            'issuer' => 'fermatmind-internal',
            'audience' => 'fap-api',
            'route_scopes' => ['path:api/v0.3/internal/report-rebuild'],
        ]);

        $response = $this->authenticate($token, '/api/v0.3/internal/report-rebuild');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $response->getData(true)['system_actor_http_authorized']);
        Log::shouldHaveReceived('log')->withArgs(
            static fn (string $level, string $message, array $context): bool => $level === 'info'
                && $message === '[SEC] system_token_http_access'
                && ($context['authorized'] ?? false) === true
                && ! array_key_exists('token', $context)
        );
    }

    #[DataProvider('invalidSystemContracts')]
    public function test_system_token_contract_failures_are_rejected(array $meta, string $path, int $ttlSeconds = 300): void
    {
        $token = $this->insertSystemToken($meta, $ttlSeconds);

        $this->assertSame(401, $this->authenticate($token, $path)->getStatusCode());
    }

    public static function invalidSystemContracts(): array
    {
        $valid = [
            'issuer' => 'fermatmind-internal',
            'audience' => 'fap-api',
            'route_scopes' => ['path:api/v0.3/internal/report-rebuild'],
        ];

        return [
            'missing issuer' => [[...$valid, 'issuer' => ''], '/api/v0.3/internal/report-rebuild'],
            'wrong audience' => [[...$valid, 'audience' => 'other-api'], '/api/v0.3/internal/report-rebuild'],
            'wrong route' => [$valid, '/api/v0.3/internal/other'],
            'ttl exceeds maximum' => [$valid, '/api/v0.3/internal/report-rebuild', 901],
        ];
    }

    public function test_revoked_system_token_is_rejected_before_authorization(): void
    {
        $token = $this->insertSystemToken([
            'issuer' => 'fermatmind-internal',
            'audience' => 'fap-api',
            'route_scopes' => ['path:api/v0.3/internal/report-rebuild'],
        ], revoked: true);

        $this->assertSame(401, $this->authenticate($token, '/api/v0.3/internal/report-rebuild')->getStatusCode());
    }

    public function test_system_token_issuer_service_requires_explicit_identity_and_scopes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FmTokenService::class)->issueForUser('system-service', ['role' => 'system']);
    }

    public function test_system_token_issuer_service_applies_short_ttl_and_audited_claims(): void
    {
        $issued = app(FmTokenService::class)->issueForUser('system-service', [
            'role' => 'system',
            'issuer' => 'fermatmind-internal',
            'audience' => 'fap-api',
            'route_scopes' => ['route:api.v0_3.internal.report_rebuild'],
        ]);
        $row = DB::table('auth_tokens')->where('token_hash', hash('sha256', $issued['token']))->first();
        $meta = json_decode((string) $row->meta_json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertLessThanOrEqual(900, strtotime((string) $row->expires_at) - strtotime((string) $row->created_at));
        $this->assertSame('fermatmind-internal', $meta['issuer']);
        $this->assertSame('fap-api', $meta['audience']);
        $this->assertNotEmpty($meta['issued_at']);
    }

    public function test_system_role_cannot_bypass_through_optional_auth_middlewares(): void
    {
        $token = $this->insertSystemToken([
            'issuer' => 'fermatmind-internal',
            'audience' => 'fap-api',
            'route_scopes' => ['path:api/v0.3/internal/report-rebuild'],
        ]);
        $next = static fn () => response()->json(['ok' => true]);

        foreach ([new FmTokenOptional, new FmTokenOptionalAuth] as $middleware) {
            $request = Request::create('/api/v0.3/internal/report-rebuild', 'POST');
            $request->headers->set('Authorization', "Bearer {$token}");
            $this->assertSame(401, $middleware->handle($request, $next)->getStatusCode());
        }
    }

    private function insertSystemToken(array $meta, int $ttlSeconds = 300, bool $revoked = false): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => 'service-report-rebuild',
            'org_id' => 0,
            'role' => 'system',
            'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addSeconds($ttlSeconds),
            'revoked_at' => $revoked ? now() : null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function authenticate(string $token, string $path): mixed
    {
        $request = Request::create($path, 'POST');
        $request->headers->set('Authorization', "Bearer {$token}");

        return (new FmTokenAuth)->handle($request, static fn (Request $request) => response()->json([
            'ok' => true,
            'system_actor_http_authorized' => $request->attributes->get('system_actor_http_authorized'),
        ]));
    }
}
