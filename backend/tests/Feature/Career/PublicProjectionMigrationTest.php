<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\PublicProjectionMigration;
use App\Support\PublicProjectionCache as Projection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PublicProjectionMigrationTest extends TestCase
{
    private string $directory;

    private string $originalStorage;

    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $lookup = new Process(['sh', '-c', 'command -v redis-server']);
        $lookup->mustRun();
        $this->directory = sys_get_temp_dir().'/public-redis-'.bin2hex(random_bytes(5));
        $this->originalStorage = storage_path();
        foreach (['old', 'new'] as $name) {
            $dir = $this->directory.'/'.$name;
            File::ensureDirectoryExists($dir, 0700);
            $socket = $dir.'/redis.sock';
            $server = new Process([trim($lookup->getOutput()), '--port', '0', '--unixsocket', $socket,
                '--dir', $dir, '--save', '', '--appendonly', 'yes', '--appendfsync', 'always', '--maxmemory', '2147483648', '--maxmemory-policy', 'noeviction']);
            $server->start();
            $this->servers[$name] = $server;
            for ($i = 0; $i < 100 && ! file_exists($socket); $i++) {
                usleep(20000);
            }
        }
        config(['cache.default' => 'redis', 'cache.prefix' => 'projection-test:', 'database.redis.options.prefix' => '',
            'public_projection_cache.phase' => 'prepare']);
        foreach (['cache' => 'old', 'default' => 'old', 'public_projection' => 'new'] as $connection => $server) {
            config(['database.redis.'.$connection => ['host' => $this->directory.'/'.$server.'/redis.sock', 'port' => 0,
                'password' => null, 'database' => $connection === 'default' ? 0 : 1]]);
        }
        Cache::purge('redis');
        Cache::purge('public_projection');
        $this->app->useStoragePath($this->directory.'/storage');
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        foreach ($this->servers as $server) {
            $server->stop();
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function seedProjections(): string
    {
        $base = 'career:public-authority:job-detail:v3:test-role:zh-CN';
        Projection::forever($base.':active', 'v1');
        Projection::forever($base.':lkg', 'v1');
        Projection::forever($base.':versions:v1', ['body' => '完整正文']);
        Projection::put('seo:sitemap-source:v1:fresh', ['urls' => ['/zh/career/jobs/test-role']], 600);
        Projection::put('career:public-authority:job-index:v3:en:public:pins:v1', true, 172800);
        Projection::put('career:public-authority:directory-read-model:v2:en:pins:v1', true, 172800);
        Projection::forever('career:public-authority:job-index:v3:en:public:activated-at', 1700000000);
        Cache::forever('limiter:sentinel', 3);
        Cache::forever('career:public-authority:unclassified-state', 'keep');
        Cache::forever('career:public-authority:directory-read-model:v2:en:rebuild-lock', 'keep-lock');
        Projection::forever('career:public-authority:first-wave-next-step:v1:test-role:en:active', ['inline' => 'public payload']);
        app('redis')->connection('default')->rpush('queues:default', 'untouched-task');

        return $base;
    }

    public function test_compatible_prepare_then_verified_switch_and_rollback_preserve_queue_locks_and_withdrawals(): void
    {
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        $migration->prepare();
        $this->assertSame('legacy', Projection::state()['mode']);
        $this->assertNull(Cache::store('public_projection')->get($base.':active'));
        $lock = Cache::lock('publication:sentinel', 60);
        $this->assertTrue($lock->get());
        config(['public_projection_cache.phase' => 'activate']);
        $this->assertSame('primary', $migration->activate()['status']);
        $this->assertSame(['body' => '完整正文'], Projection::get($base.':versions:v1'));
        $this->assertNull(Cache::store('public_projection')->get('limiter:sentinel'));
        $this->assertNull(Cache::store('public_projection')->get('career:public-authority:unclassified-state'));
        $this->assertNull(Cache::store('public_projection')->get('career:public-authority:directory-read-model:v2:en:rebuild-lock'));
        $this->assertSame(['inline' => 'public payload'], Projection::get('career:public-authority:first-wave-next-step:v1:test-role:en:active'));
        $this->assertTrue(Cache::store('public_projection')->get('career:public-authority:job-index:v3:en:public:pins:v1'));
        $this->assertTrue(Cache::store('public_projection')->get('career:public-authority:directory-read-model:v2:en:pins:v1'));
        $this->assertSame(Cache::get('career:public-authority:job-index:v3:en:public:activated-at'), Cache::store('public_projection')->get('career:public-authority:job-index:v3:en:public:activated-at'));
        $this->assertFalse(Projection::lock('publication:sentinel', 60)->get());
        Projection::forever($base.':versions:v2', ['body' => 'updated']);
        Projection::forever($base.':active', 'v2');
        Projection::forget('seo:sitemap-source:v1:fresh');
        $this->assertNull(Cache::get('seo:sitemap-source:v1:fresh'));
        $this->assertSame('mirror', $migration->synchronize(true)['status']);
        $this->assertSame('v2', Projection::get($base.':active'));
        $this->assertNull(Projection::get('seo:sitemap-source:v1:fresh'));
        $this->assertSame(['untouched-task'], app('redis')->connection('default')->lrange('queues:default', 0, -1));
        $this->assertSame('3', Cache::get('limiter:sentinel'));
        $lock->release();
    }

    public function test_failed_mirror_keeps_serving_payload_and_withdrawal_is_immediate(): void
    {
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        $migration->prepare();
        Projection::mutation(fn () => Projection::writeState([...Projection::state(), 'mode' => 'mirror']), true);
        [$new] = PublicProjectionMigration::connection(true);
        $new->config('SET', 'maxmemory', '1');
        try {
            Projection::forever($base.':active', 'unsafe');
            $this->fail('OOM mirror must not accept publication.');
        } catch (\RedisException) {
            $this->assertSame('v1', Projection::get($base.':active'));
            $this->assertTrue(Projection::state()['mirror_dirty']);
        }
        Projection::forget($base.':active');
        $this->assertNull(Projection::get($base.':active'));
        $new->config('SET', 'maxmemory', '2147483648');
        config(['public_projection_cache.phase' => 'activate']);
        $migration->activate();
        $this->assertNull(Projection::get($base.':active'));
    }

    public function test_retirement_requires_seven_days_two_releases_and_verified_content(): void
    {
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        config(['public_projection_cache.phase' => 'activate']);
        // Activate phase intentionally refuses missing persistent migration state.
        config(['public_projection_cache.phase' => 'prepare']);
        $migration->prepare();
        config(['public_projection_cache.phase' => 'activate']);
        $migration->activate();
        $migration->accept(str_repeat('a', 40));
        $migration->accept(str_repeat('a', 40));
        $this->assertSame('observing', $migration->retire()['status']);
        Projection::mutation(fn () => Projection::writeState([...Projection::state(), 'primary_since' => time() - 8 * 86400]));
        $this->assertSame('observing', $migration->retire()['status']);
        $migration->accept(str_repeat('b', 40));
        Cache::store('public_projection')->forever($base.':active', 'corrupt');
        try {
            $migration->retire();
            $this->fail('Unequal current/rollback content must block retirement.');
        } catch (\RuntimeException) {
            $this->assertSame('primary', Projection::state()['mode']);
        }
        Cache::store('public_projection')->forever($base.':active', 'v1');
        $this->assertSame('isolated', $migration->retire()['status']);
        $this->assertNull(Cache::get($base.':active'));
        $this->assertSame('keep', Cache::get('career:public-authority:unclassified-state'));
        $this->assertSame('keep-lock', Cache::get('career:public-authority:directory-read-model:v2:en:rebuild-lock'));
        $this->assertSame('v1', Projection::get($base.':active'));
        $this->assertSame('3', Cache::get('limiter:sentinel'));
        $this->assertSame(['untouched-task'], app('redis')->connection('default')->lrange('queues:default', 0, -1));
    }

    public function test_restart_retains_isolated_content_and_missing_state_cannot_fall_back(): void
    {
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        $migration->prepare();
        config(['public_projection_cache.phase' => 'activate']);
        $migration->activate();
        $this->servers['new']->stop();
        $this->servers['new']->start();
        for ($i = 0; $i < 100 && ! file_exists($this->directory.'/new/redis.sock'); $i++) {
            usleep(20000);
        }
        app('redis')->purge('public_projection');
        Cache::purge('public_projection');
        $this->assertSame(['body' => '完整正文'], Projection::get($base.':versions:v1'));
        $this->assertSame(['untouched-task'], app('redis')->connection('default')->lrange('queues:default', 0, -1));
        unlink(Projection::statePath());
        $this->expectException(\RuntimeException::class);
        Projection::get($base.':active');
    }

    public function test_concurrent_withdrawal_cannot_be_resurrected_by_copy(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Fork required for concurrent migration exercise.');
        }
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        $migration->prepare();
        Projection::mutation(fn () => Projection::writeState([...Projection::state(), 'mode' => 'mirror']), true);
        for ($i = 0; $i < 80; $i++) {
            Projection::forever('career:public-authority:job-detail:v3:fixture-'.$i.':en:versions:v1', str_repeat('x', 65536));
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Do not share inherited Redis sockets with the parent process.
            foreach (['cache', 'default', 'public_projection'] as $connection) {
                app('redis')->purge($connection);
            }
            Cache::purge('redis');
            Cache::purge('public_projection');
            usleep(10000);
            Projection::forget($base.':active');
            exit(0);
        }
        config(['public_projection_cache.phase' => 'activate']);
        try {
            $migration->activate();
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Concurrent publication', $error->getMessage());
        }
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNull(Projection::get($base.':active'));
        $this->assertNull(Cache::store('redis')->get($base.':active'));
        $this->assertNull(Cache::store('public_projection')->get($base.':active'));
    }

    public function test_installer_dry_check_accepts_only_loopback_bounded_public_instance(): void
    {
        config(['database.redis.public_projection.password' => str_repeat('test-only-', 4)]);
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $this->artisan('cache:public-projection config')->assertSuccessful();
        $path = storage_path('app/private/public-projection-redis.conf');
        $command = ['python3', base_path('scripts/deploy/install_public_projection_redis.py'), '--candidate', $path, '--check-only'];
        (new Process($command))->mustRun();
        $this->assertSame(0600, fileperms($path) & 0777);
        file_put_contents($path, str_replace('bind 127.0.0.1', 'bind 0.0.0.0', file_get_contents($path)));
        $invalid = new Process($command);
        $invalid->run();
        $this->assertFalse($invalid->isSuccessful());
        config(['database.redis.public_projection.password' => null]);
        $this->artisan('cache:public-projection config')->assertSuccessful();
        $production = new Process($command);
        $production->run();
        $this->assertFalse($production->isSuccessful());
        (new Process([...$command, '--staging']))->mustRun();
    }

    public function test_missing_referenced_payload_alerts_immediately_and_periodic_verification_detects_recovery(): void
    {
        $base = $this->seedProjections();
        $migration = new PublicProjectionMigration;
        $migration->prepare();
        config(['public_projection_cache.phase' => 'activate']);
        $migration->activate();
        Cache::store('public_projection')->forget($base.':versions:v1');
        $this->assertNull(Projection::get($base.':versions:v1'));
        $statePath = storage_path('app/ops/cache-lifecycle/projection_integrity.json');
        $state = json_decode(file_get_contents($statePath), true);
        $this->assertFalse($state['healthy']);
        $this->assertTrue($state['pending_fault']);
        Cache::store('public_projection')->forever($base.':versions:v1', ['body' => '完整正文']);
        $migration->retire();
        $state = json_decode(file_get_contents($statePath), true);
        $this->assertTrue($state['healthy']);
        [$target] = PublicProjectionMigration::connection(true);
        $target->flushDB(); // Disposable test instance only.
        $this->expectException(\RuntimeException::class);
        $migration->retire();
    }
}
