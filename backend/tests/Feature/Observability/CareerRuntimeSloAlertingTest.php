<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Http\Middleware\RecordCareerRuntimeSlo;
use App\Services\Career\CareerJobDetailCacheCoverageService;
use App\Services\Career\CareerRuntimeSloService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
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

    public function test_verify_only_header_bypasses_directory_slo_writes(): void
    {
        $middleware = new RecordCareerRuntimeSlo(app(CareerRuntimeSloService::class));
        $request = Request::create('/api/v0.5/career/directory?locale=en', 'GET');
        $request->headers->set('X-Fermat-Career-Verify-Only', '1');

        $response = $middleware->handle($request, static fn (): Response => new Response('{}', 503));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(0, app(CareerRuntimeSloService::class)->evaluate()['sample_count']);
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
            'detail_cache_missing_count' => 3,
            'detail_cache_broken_count' => 2,
            'detail_cache_target_count' => 2000,
            'minimum_detail_target_count' => 2092,
        ]);

        $this->assertSame('alert', $evaluation['status']);
        $this->assertEqualsCanonicalizing([
            'career_api_warm_p95_above_800ms',
            'career_cache_rebuild_above_5s',
            'career_page_false_empty',
            'career_locale_count_mismatch',
            'career_cache_age_exceeded',
            'career_release_smoke_failed',
            'career_detail_cache_coverage_missing',
            'career_detail_cache_coverage_broken',
            'career_detail_cache_target_count_below_minimum',
        ], $evaluation['alerts']);
    }

    public function test_scheduled_probe_checks_bilingual_pages_authority_detail_and_enumeration_surfaces(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');
        $this->seedReadyDetailCoverage(['software-developer']);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v0.5/career/directory')) {
                return Http::response(['public_truth' => ['public_detail_indexable_count' => 1046], 'items' => [['slug' => 'software-developer']]]);
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
        $this->assertStringContainsString('"expected_target_count": 2', $output);
        $this->assertStringNotContainsString('<html>', $output);
        Http::assertSentCount(8);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.test/api/v0.5/career/jobs/software-developer?locale=en');
    }

    public function test_lightweight_scheduled_probe_skips_heavyweight_discoverability_generation_routes(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');
        $this->bindProjection(['software-developer']);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v0.5/career/directory')) {
                return Http::response(['public_truth' => ['public_detail_indexable_count' => 1046], 'items' => [['slug' => 'software-developer']]]);
            }

            return Http::response('<html>1046 careers</html>');
        });

        $exit = Artisan::call('career:runtime-slo-check', [
            '--lightweight' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('"probe_mode": "lightweight"', $output);
        $this->assertStringContainsString('"status": "not_sampled"', $output);
        $this->assertStringContainsString('"reason": "lightweight_runtime_probe"', $output);
        Http::assertSentCount(4);
        Http::assertNotSent(static fn (ClientRequest $request): bool => in_array($request->url(), [
            'https://site.test/sitemap.xml',
            'https://site.test/llms.txt',
            'https://site.test/llms-full.txt',
            'https://api.test/api/v0.5/career/jobs/software-developer?locale=en',
        ], true));
    }

    public function test_scheduled_probe_alerts_from_every_published_detail_cache_key_and_keeps_route_smoke(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');
        $this->bindProjection(['ready', 'missing']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel('ready', 'en', ['slug' => 'ready']);
        $cache->publishJobDetailReadModel('ready', 'zh-CN', ['slug' => 'ready']);
        config()->set('ops.career_runtime_slo.minimum_detail_target_count', 4);

        Http::fake(static fn (ClientRequest $request) => str_contains($request->url(), '/api/v0.5/career/directory')
            ? Http::response(['public_truth' => ['public_detail_indexable_count' => 2], 'items' => [['slug' => 'ready']]])
            : (str_contains($request->url(), '/career/jobs')
                ? Http::response('<html>2 careers</html>')
                : Http::response("/career/jobs/ready\n"))
        );

        $exit = Artisan::call('career:runtime-slo-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('"expected_target_count": 4', $output);
        $this->assertStringContainsString('"missing_count": 2', $output);
        $this->assertStringContainsString('career_detail_cache_coverage_missing', $output);
        Http::assertSentCount(8);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.test/api/v0.5/career/jobs/missing?locale=en'
            || $request->url() === 'https://api.test/api/v0.5/career/jobs/ready?locale=en');
    }

    public function test_failed_smoke_sends_the_existing_ops_webhook_and_fails_the_check(): void
    {
        config()->set('ops.career_runtime_slo.site_url', 'https://site.test');
        config()->set('ops.career_runtime_slo.api_url', 'https://api.test');
        config()->set('ops.alert.webhook', 'https://alerts.test/career');
        $this->seedReadyDirectoryCache('en');
        $this->seedReadyDirectoryCache('zh-CN');
        $this->seedReadyDetailCoverage(['software-developer']);
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

    public function test_scheduled_repair_is_opt_in_bounded_and_uses_the_existing_resume_contract(): void
    {
        $schedule = file_get_contents(base_path('bootstrap/app.php'));
        $config = file_get_contents(config_path('ops.php'));

        $this->assertIsString($schedule);
        $this->assertIsString($config);
        $this->assertStringContainsString("config('ops.career_runtime_slo.repair_missing_enabled', false)", $schedule);
        $this->assertStringContainsString('--repair-missing --locales=en,zh-CN --minimum-targets=', $schedule);
        $this->assertStringContainsString("config('ops.career_runtime_slo.minimum_detail_target_count', 2092)", $schedule);
        $this->assertStringContainsString(".' --batch-size='.\$repairBatchSize", $schedule);
        $this->assertStringContainsString('--resume-key=runtime-slo --confirm-production-write --json', $schedule);
        $this->assertStringContainsString("env('CAREER_RUNTIME_SLO_REPAIR_MISSING_ENABLED', false)", $config);
        $this->assertStringContainsString("env('CAREER_RUNTIME_SLO_REPAIR_BATCH_SIZE', 100)", $config);
        $this->assertStringContainsString("env('CAREER_RUNTIME_SLO_MINIMUM_DETAIL_TARGET_COUNT', 2092)", $config);
        $this->assertStringContainsString("preg_match('/^[1-9][0-9]*$/D', \$careerDetailMinimumTargetsRaw) !== 1", $config);
        $this->assertStringContainsString('CAREER_RUNTIME_SLO_MINIMUM_DETAIL_TARGET_COUNT must be a positive base-10 integer.', $config);
        $this->assertStringContainsString('career:runtime-slo-check --lightweight --json', $schedule);
    }

    private function seedReadyDirectoryCache(string $locale): void
    {
        $prefix = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale;
        Cache::forever($prefix.':active', 'test-version');
        Cache::forever($prefix.':versions:test-version', ['items' => []]);
        Cache::forever($prefix.':activated-at', now()->timestamp);
        Cache::forever($prefix.':last-rebuild-ms', 100.0);
    }

    /** @param list<string> $slugs */
    private function seedReadyDetailCoverage(array $slugs): void
    {
        $this->bindProjection($slugs);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $cache->publishJobDetailReadModel($slug, $locale, ['slug' => $slug]);
            }
        }
        config()->set('ops.career_runtime_slo.minimum_detail_target_count', count($slugs) * 2);
    }

    /** @param list<string> $slugs */
    private function bindProjection(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[$slug.'|'.$locale] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'runtime_publish_state' => 'published',
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                ];
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: $items),
        );
        $this->app->forgetInstance(CareerJobDetailCacheCoverageService::class);
        $this->app->forgetInstance(PublicCareerAuthorityResponseCache::class);
    }
}
