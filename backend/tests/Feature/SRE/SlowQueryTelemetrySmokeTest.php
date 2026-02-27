<?php

declare(strict_types=1);

namespace Tests\Feature\SRE;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
}
