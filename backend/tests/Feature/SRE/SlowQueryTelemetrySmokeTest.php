<?php

declare(strict_types=1);

namespace Tests\Feature\SRE;

use App\Support\Career\CareerVerifyOnlyRequestAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class SlowQueryTelemetrySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_slow_query_logs_structured_fields_with_request_context(): void
    {
        config()->set('fap.observability.slow_query_log_enabled', true);
        config()->set('fap.observability.slow_query_ms', 0);

        $request = Request::create('/api/v0.3/sre/slow-query-smoke', 'GET');
        $request->attributes->set('org_id', 321);
        $request->attributes->set('request_id', 'req_slow_query_smoke_1');
        $this->app->instance('request', $request);

        Log::spy();

        DB::select('select 1 as ok');

        Log::shouldHaveReceived('warning')
            ->atLeast()
            ->once()
            ->withArgs(function ($message, $context): bool {
                if ($message !== 'SLOW_QUERY_DETECTED') {
                    return false;
                }

                $this->assertIsArray($context);
                $this->assertSame(321, (int) ($context['org_id'] ?? -1));
                $this->assertSame('api/v0.3/sre/slow-query-smoke', (string) ($context['route'] ?? ''));
                $this->assertSame('req_slow_query_smoke_1', (string) ($context['request_id'] ?? ''));
                $this->assertIsNumeric($context['sql_ms'] ?? null);
                $this->assertGreaterThanOrEqual(0, (float) ($context['sql_ms'] ?? -1));
                $this->assertIsString($context['sql'] ?? null);
                $this->assertNotSame('', (string) ($context['connection'] ?? ''));

                return true;
            });
    }

    public function test_valid_signed_career_verify_only_request_does_not_write_slow_query_log(): void
    {
        config()->set('fap.observability.slow_query_log_enabled', true);
        config()->set('fap.observability.slow_query_ms', 0);
        config()->set('app.key', 'slow-query-verify-only-test-key');

        Log::shouldReceive('warning')->never();

        foreach ([
            '/api/v0.5/career/directory?locale=en',
            '/api/v0.5/career/jobs/software-developers?locale=en',
        ] as $uri) {
            $request = $this->requestWithHeaders($uri, $this->verifyOnlyHeaders($uri));
            self::assertTrue(app(CareerVerifyOnlyRequestAuthorizer::class)->isAuthorized($request));
            $this->app->instance('request', $request);
            self::assertSame($request, request());

            DB::select('select 1 as ok');
        }
    }

    public function test_unsigned_expired_forged_and_non_career_requests_keep_slow_query_logging(): void
    {
        config()->set('fap.observability.slow_query_log_enabled', true);
        config()->set('fap.observability.slow_query_ms', 0);
        config()->set('app.key', 'slow-query-verify-only-test-key');

        $cases = [
            ['/api/v0.5/career/directory?locale=en', []],
            ['/api/v0.5/career/directory?locale=en', $this->verifyOnlyHeaders('/api/v0.5/career/directory?locale=en', time() - 121)],
            ['/api/v0.5/career/jobs/software-developers?locale=en', [
                CareerVerifyOnlyRequestAuthorizer::MARKER_HEADER => '1',
                CareerVerifyOnlyRequestAuthorizer::TIMESTAMP_HEADER => (string) time(),
                CareerVerifyOnlyRequestAuthorizer::SIGNATURE_HEADER => str_repeat('0', 64),
            ]],
            ['/api/v0.3/sre/slow-query-smoke', $this->verifyOnlyHeaders('/api/v0.3/sre/slow-query-smoke')],
        ];

        foreach ($cases as [$uri, $headers]) {
            $this->app->instance('request', $this->requestWithHeaders($uri, $headers));
            Log::spy();
            DB::select('select 1 as ok');

            Log::shouldHaveReceived('warning')
                ->atLeast()
                ->once()
                ->with('SLOW_QUERY_DETECTED', Mockery::type('array'));
        }
    }

    /** @return array<string, string> */
    private function verifyOnlyHeaders(string $uri, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $timestampString = (string) $timestamp;

        return [
            CareerVerifyOnlyRequestAuthorizer::MARKER_HEADER => '1',
            CareerVerifyOnlyRequestAuthorizer::TIMESTAMP_HEADER => $timestampString,
            CareerVerifyOnlyRequestAuthorizer::SIGNATURE_HEADER => hash_hmac(
                'sha256',
                CareerVerifyOnlyRequestAuthorizer::signaturePayload($uri, $timestampString),
                (string) config('app.key'),
            ),
        ];
    }

    /** @param array<string, string> $headers */
    private function requestWithHeaders(string $uri, array $headers): Request
    {
        $request = Request::create($uri, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
