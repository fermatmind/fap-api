<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Http\Middleware\RecordCareerRuntimeSlo;
use App\Services\Career\CareerRuntimeSloService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class CareerRuntimeSloAlertingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_middleware_records_directory_latency_and_status_for_rolling_slo(): void
    {
        $middleware = new RecordCareerRuntimeSlo(app(CareerRuntimeSloService::class));
        $request = Request::create('/api/v0.5/career/directory?locale=en', 'GET');

        $response = $middleware->handle($request, static fn (): Response => new Response('{}', 503));
        $evaluation = app(CareerRuntimeSloService::class)->evaluate();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(1, $evaluation['sample_count']);
        $this->assertSame(1.0, $evaluation['api_5xx_rate']);
        $this->assertContains('career_api_5xx_rate_above_1_percent', $evaluation['alerts']);
    }

    public function test_metrics_lock_contention_never_delays_or_changes_the_api_response(): void
    {
        $lock = Cache::lock(CareerRuntimeSloService::SAMPLE_KEY.':lock', 5);
        $this->assertTrue($lock->get());

        try {
            $middleware = new RecordCareerRuntimeSlo(app(CareerRuntimeSloService::class));
            $request = Request::create('/api/v0.5/career/directory', 'GET');
            $started = hrtime(true);

            $response = $middleware->handle($request, static fn (): Response => new Response('{}', 200));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertLessThan(100, (hrtime(true) - $started) / 1_000_000);
            $this->assertSame(0, app(CareerRuntimeSloService::class)->evaluate()['sample_count']);
        } finally {
            $lock->release();
        }
    }

    public function test_evaluator_enforces_every_runtime_alert_boundary(): void
    {
        $slo = app(CareerRuntimeSloService::class);
        for ($index = 0; $index < 20; $index++) {
            $slo->record('career_directory_api', 200, $index === 19 ? 901 : 850);
        }

        $evaluation = $slo->evaluate([
            'last_rebuild_ms' => 5001,
            'false_empty' => true,
            'locale_count_mismatch' => true,
            'cache_stale' => true,
            'smoke_failed' => true,
        ]);

        $this->assertSame('alert', $evaluation['status']);
        $this->assertEqualsCanonicalizing([
            'career_api_warm_p95_above_800ms',
            'career_cache_rebuild_above_5s',
            'career_page_false_empty',
            'career_locale_count_mismatch',
            'career_cache_age_exceeded',
            'career_release_smoke_failed',
        ], $evaluation['alerts']);
    }

    public function test_scheduled_probe_checks_bilingual_pages_authority_detail_and_enumeration_surfaces(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v0.5/career/directory')) {
                return Http::response(['public_truth' => ['public_detail_indexable_count' => 1046], 'items' => [['slug' => 'software-developer']]]);
            }
            if (str_contains($url, '/api/v0.5/career/jobs/software-developer')) {
                return Http::response(['slug' => 'software-developer']);
            }
            if (str_contains($url, '/career/jobs')) {
                return Http::response('<html>1046 careers</html>');
            }

            return Http::response("/career/jobs/software-developer\n");
        });

        $exit = Artisan::call('career:runtime-slo-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('"status": "pass"', $output);
        $this->assertStringContainsString('"response_bytes"', $output);
        $this->assertStringNotContainsString('<html>', $output);
        Http::assertSentCount(8);
    }

    public function test_failed_smoke_sends_the_existing_ops_webhook_and_fails_the_check(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        config()->set('ops.alert.webhook', 'https://alerts.test/career');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');
        Http::fake(static fn (ClientRequest $request) => str_starts_with($request->url(), 'https://alerts.test/')
            ? Http::response([], 202)
            : Http::response([], 503));

        $exit = Artisan::call('career:runtime-slo-check', ['--json' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('career_release_smoke_failed', Artisan::output());
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://alerts.test/career'
            && str_contains((string) $request['text'], 'Career runtime SLO alert')
        );
    }

    private function seedReadyDirectoryCache(string $locale): void
    {
        $prefix = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale;
        Cache::forever($prefix.':active', 'test-version');
        Cache::forever($prefix.':versions:test-version', ['items' => []]);
        Cache::forever($prefix.':activated-at', now()->timestamp);
        Cache::forever($prefix.':last-rebuild-ms', 100.0);
    }
}
