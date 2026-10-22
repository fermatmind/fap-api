<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Services\SEO\SitemapGenerator;
use App\Services\SEO\SitemapSourceCacheHandoff;
use App\Support\PublicProjectionCache as Projection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SitemapSourceCacheHandoffTest extends TestCase
{
    private string $directory;

    private string $originalRoot;

    private string $originalStorage;

    private Process $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRoot = base_path();
        $this->originalStorage = storage_path();
        $this->directory = sys_get_temp_dir().'/sitemap-handoff-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->directory);
        $socket = $this->directory.'/redis.sock';
        $this->redis = new Process(['redis-server', '--port', '0', '--unixsocket', $socket,
            '--dir', $this->directory, '--save', '', '--appendonly', 'no',
            '--maxmemory', '2147483648', '--maxmemory-policy', 'noeviction']);
        $this->redis->start();
        for ($i = 0; $i < 100 && ! file_exists($socket); $i++) {
            usleep(20000);
        }
        config(['cache.default' => 'redis', 'cache.prefix' => 'handoff-test:',
            'database.redis.options.prefix' => '', 'public_projection_cache.phase' => 'prepare',
            'database.redis.cache' => ['host' => $socket, 'port' => 0, 'password' => null, 'database' => 1],
            'database.redis.public_projection' => ['host' => $socket, 'port' => 0, 'password' => null, 'database' => 2],
            'app.frontend_url' => 'https://fermatmind.com']);
        Cache::purge('redis');
        Cache::purge('public_projection');
        $this->app->useStoragePath($this->directory.'/storage');
        foreach (['previous', 'candidate'] as $name) {
            $root = $this->root($name);
            foreach (SitemapSourceCacheHandoff::INPUT_PATHS as $input) {
                File::ensureDirectoryExists(dirname($root.'/'.$input));
                if ($input === '.env') {
                    file_put_contents($root.'/'.$input, 'APP_ENV=testing');
                } else {
                    symlink($this->originalRoot.'/'.$input, $root.'/'.$input);
                }
            }
        }
        $this->app->setBasePath($this->root('candidate'));
        $this->mock(SitemapGenerator::class)->shouldNotReceive('generateSitemapUrls');
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalRoot);
        $this->app->useStoragePath($this->originalStorage);
        $this->redis->stop();
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function root(string $name): string
    {
        return $this->directory.'/'.$name.'/backend';
    }

    private function payload(): array
    {
        return ['ok' => true, 'source' => 'backend_sitemap_generator', 'count' => 2,
            'items' => array_map(static fn (string $slug): array => [
                'loc' => 'https://fermatmind.com/en/articles/'.$slug,
                'lastmod' => '2026-10-21T00:00:00Z',
            ], ['iq-test-tool-guide', 'matrix-reasoning-rotation-vs-reflection'])];
    }

    private function seedSource(array $payload, int $ttl = 500): void
    {
        $this->app->setBasePath($this->root('previous'));
        Projection::put(SitemapSourceController::CACHE_KEY_FRESH, $payload, $ttl);
        $this->app->setBasePath($this->root('candidate'));
    }

    public function test_compatible_release_carries_both_guides_without_rebuilding_or_extending_freshness(): void
    {
        foreach (['legacy', 'mirror', 'primary', 'isolated'] as $mode) {
            Projection::writeState(['version' => 1, 'mode' => $mode]);
            $payload = $this->payload();
            $this->seedSource($payload);
            $result = app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
            self::assertSame('carried', $result['status']);
            self::assertSame($payload, Projection::get(SitemapSourceController::CACHE_KEY_FRESH));
            $store = Projection::store()->getStore();
            $ttl = $store->connection()->ttl($store->getPrefix().Projection::physicalKey(SitemapSourceController::CACHE_KEY_FRESH));
            self::assertGreaterThanOrEqual(495, $ttl);
            self::assertLessThanOrEqual(500, $ttl);
            // A late old worker cannot replace or invalidate the candidate generation.
            $this->app->setBasePath($this->root('previous'));
            Projection::forget(SitemapSourceController::CACHE_KEY_FRESH);
            Projection::put(SitemapSourceController::CACHE_KEY_FRESH, ['old_writer' => true], 600);
            $this->app->setBasePath($this->root('candidate'));
            self::assertSame($payload, Projection::get(SitemapSourceController::CACHE_KEY_FRESH));
        }
    }

    public function test_changed_authority_manifest_does_not_carry_prior_urls(): void
    {
        $this->seedSource($this->payload());
        $path = $this->root('candidate').'/content_assets/career/current/manifest.json';
        unlink($path);
        file_put_contents($path, '{"changed":true}');
        $this->expectExceptionMessage('SITEMAP_HANDOFF_INPUT_CHANGED');
        app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
    }

    public function test_environment_change_does_not_carry_previous_urls(): void
    {
        $this->seedSource($this->payload());
        file_put_contents($this->root('candidate').'/.env', 'APP_ENV=other');
        $this->expectExceptionMessage('SITEMAP_HANDOFF_INPUT_CHANGED');
        app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
    }

    public function test_missing_or_withdrawn_cache_cannot_replace_the_candidate(): void
    {
        $this->expectExceptionMessage('SITEMAP_HANDOFF_SOURCE_NOT_FRESH');
        app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
    }

    public function test_nearly_expired_source_is_not_extended_into_another_generation(): void
    {
        $this->seedSource($this->payload(), 30);
        $this->expectExceptionMessage('SITEMAP_HANDOFF_SOURCE_NOT_FRESH');
        app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
    }

    public function test_fallback_cannot_be_promoted_as_authority(): void
    {
        $this->seedSource([...$this->payload(), 'source' => 'backend_sitemap_generator_fallback']);
        try {
            app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
            self::fail('Fallback must not become candidate authority.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('authority or completeness is invalid', $error->getMessage());
            self::assertNull(Projection::get(SitemapSourceController::CACHE_KEY_FRESH));
        }
    }

    public function test_invalid_candidate_leaves_existing_candidate_intact(): void
    {
        app(SitemapSourceController::class)->storeCache($this->payload());
        $this->seedSource([...$this->payload(), 'count' => 1]);
        try {
            app(SitemapSourceCacheHandoff::class)->carry($this->root('previous'));
            self::fail('An incomplete source must not be carried.');
        } catch (\RuntimeException) {
            self::assertSame($this->payload(), Projection::get(SitemapSourceController::CACHE_KEY_FRESH));
        }
    }
}
