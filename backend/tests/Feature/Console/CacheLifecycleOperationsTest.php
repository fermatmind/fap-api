<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ops\CacheLifecycleAlerts;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class CacheLifecycleOperationsTest extends TestCase
{
    private string $originalStorage;

    private string $testStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->testStorage = sys_get_temp_dir().'/cache-ops-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->testStorage);
        $this->app->useStoragePath($this->testStorage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->testStorage);
        parent::tearDown();
    }

    public function test_direct_email_retries_failed_delivery_then_deduplicates_fault_and_recovery(): void
    {
        config(['ops.cache_lifecycle.mail_recipient' => 'ops@example.test', 'mail.from.address' => 'sender@example.test']);
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $calls = 0;
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->andReturnUsing(function (array $config) use ($mailer, &$calls) {
            $this->assertSame(10, $config['timeout']);
            if (++$calls === 1) {
                throw new \RuntimeException('simulated SMTP unavailable');
            }

            return $mailer;
        });
        $this->app->instance(MailManager::class, $manager);
        $alerts = new CacheLifecycleAlerts;
        for ($i = 0; $i < 3; $i++) {
            $alerts->observe('llms_refresh', false);
        }
        $this->assertSame(1, $calls);
        $state = json_decode(file_get_contents(storage_path('app/ops/cache-lifecycle/llms_refresh.json')), true);
        $this->assertTrue($state['delivery_failed']);
        $this->assertFalse($state['fault_notified'] ?? false);
        $alerts->observe('llms_refresh', false);
        $alerts->observe('llms_refresh', false);
        $this->assertSame(2, $calls);
        $alerts->observe('llms_refresh', true);
        $alerts->observe('llms_refresh', true);
        $this->assertSame(3, $calls);
        $this->assertCount(2, $mailer->getSymfonyTransport()->messages());
    }

    public function test_urgent_failure_does_not_wait_for_three_samples(): void
    {
        config(['ops.cache_lifecycle.mail_recipient' => 'ops@example.test', 'mail.from.address' => 'sender@example.test']);
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);
        (new CacheLifecycleAlerts)->observe('redis_capacity', false, true);
        $this->assertCount(1, $mailer->getSymfonyTransport()->messages());
    }

    public function test_readonly_health_detects_missing_stale_and_failed_jobs_without_writing(): void
    {
        $alerts = new CacheLifecycleAlerts;
        $this->assertFalse($alerts->health()['ok']);
        $this->assertDirectoryDoesNotExist(storage_path('app/ops/cache-lifecycle'));
        foreach (['career_retention', 'sitemap_refresh', 'llms_refresh'] as $component) {
            $alerts->observe($component, true);
        }
        $this->assertTrue($alerts->health()['ok']);
        $path = storage_path('app/ops/cache-lifecycle/llms_refresh.json');
        $state = json_decode(file_get_contents($path), true);
        $state['observed_at'] = time() - 3600;
        file_put_contents($path, json_encode($state));
        $this->assertFalse($alerts->health()['ok']);
    }

    public function test_refresh_signs_current_authority_and_requires_complete_acknowledgement(): void
    {
        $generator = Mockery::mock(\App\Services\SEO\SitemapGenerator::class);
        $generator->shouldReceive('generateSitemapUrls')->twice()->andReturn([
            ['slug' => 'static-index:zh', 'loc' => 'https://fermatmind.com/zh', 'lastmod' => '2026-09-08'],
        ]);
        $this->app->instance(\App\Services\SEO\SitemapGenerator::class, $generator);
        $secret = str_repeat('s', 32);
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => $secret]);
        Http::fakeSequence()->push(['ok' => true, 'operation' => 'refresh_llms_full', 'status' => 'complete', 'bytes' => 1000])
            ->push(['ok' => true, 'revalidated_paths' => []]);
        $this->artisan('seo:refresh-llms-full-cache')->assertSuccessful();
        $this->artisan('seo:refresh-llms-full-cache')->assertFailed();
        Http::assertSent(function ($request) use ($secret): bool {
            $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
            $nonce = $request->header('X-FM-Content-Release-Nonce')[0];

            return $request['operation'] === 'refresh_llms_full' && strlen($request['source_fingerprint']) === 64
                && $request->header('X-FM-Content-Release-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), $secret);
        });
    }

    public function test_unconfigured_refresh_fails_visibly_without_network_or_cache_invalidation(): void
    {
        Http::preventStrayRequests();
        config(['ops.content_release_observability.hmac_revalidation_url' => '']);
        $this->artisan('seo:refresh-llms-full-cache')->assertFailed();
        Http::assertNothingSent();
        $state = json_decode(file_get_contents(storage_path('app/ops/cache-lifecycle/llms_refresh.json')), true);
        $this->assertFalse($state['healthy']);
    }
}
