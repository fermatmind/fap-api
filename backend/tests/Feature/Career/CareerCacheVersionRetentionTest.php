<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\CareerCacheVersionRetention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Ulid;
use Tests\TestCase;

final class CareerCacheVersionRetentionTest extends TestCase
{
    public function test_policy_protects_references_recent_candidates_and_unknown_namespaces(): void
    {
        $policy = new CareerCacheVersionRetention;
        $old = (string) Ulid::fromString('01K00000000000000000000000');
        $active = (string) new Ulid;
        $key = 'career:public-authority:job-detail:v3:test-role:zh-CN:versions:'.$old;
        $this->assertTrue($policy->collectible($key, $active, $active, false, 259200, time()));
        $this->assertFalse($policy->collectible($key, $old, $active, false, 259200, time()));
        $this->assertFalse($policy->collectible($key, $active, $old, false, 259200, time()));
        $this->assertFalse($policy->collectible($key, $active, null, false, 259200, time()));
        $this->assertFalse($policy->collectible($key, $active, $active, true, 259200, time()));
        $this->assertFalse($policy->collectible($key, $active, $active, false, 60, time()));
        $this->assertNull($policy->identify('queues:default:'.$old));
    }

    public function test_atomic_gc_at_oom_preserves_current_rollback_pins_queue_and_restorable_backup(): void
    {
        $lookup = new Process(['sh', '-c', 'command -v redis-server']);
        $lookup->run();
        if (! $lookup->isSuccessful() || ! extension_loaded('redis')) {
            $this->markTestSkipped('Isolated Redis server and phpredis required.');
        }
        $directory = sys_get_temp_dir().'/career-retention-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory, 0700);
        $socket = $directory.'/redis.sock';
        $server = new Process([trim($lookup->getOutput()), '--port', '0', '--unixsocket', $socket, '--save', '', '--appendonly', 'no']);
        $server->start();
        $redis = new \Redis;
        $originalStorage = storage_path();
        try {
            for ($attempt = 0; $attempt < 100 && ! file_exists($socket); $attempt++) {
                usleep(20000);
            }
            $redis->connect($socket);
            config(['cache.default' => 'redis', 'cache.prefix' => 'retention-test:',
                'database.redis.cache.host' => $socket, 'database.redis.cache.port' => 0,
                'database.redis.cache.password' => null, 'database.redis.cache.database' => 0,
                'database.redis.options.prefix' => '']);
            Cache::purge('redis');
            $this->app->useStoragePath($directory.'/storage');
            $base = 'career:public-authority:job-detail:v3:test-role:zh-CN';
            $old = '01K00000000000000000000000';
            $pinned = '01K00000000000000000000001';
            $active = (string) new Ulid;
            Cache::forever($base.':active', $active);
            Cache::forever($base.':lkg', $active);
            Cache::forever($base.':versions:'.$active, ['payload' => 'current']);
            Cache::forever($base.':pins:'.$pinned, true);
            $redis->set('queues:default', 'queue-sentinel');
            foreach ([$old, $pinned] as $version) {
                $key = $base.':versions:'.$version;
                Cache::forever($key, ['payload' => 'old']);
                $physical = 'retention-test:'.$key;
                $dump = $redis->dump($physical);
                $redis->rawCommand('RESTORE', $physical, 0, $dump, 'REPLACE', 'IDLETIME', 259200);
            }
            $redis->config('SET', 'maxmemory', '2147483648');
            $this->artisan('career:prune-public-cache-versions --apply --pressure')->assertSuccessful();
            $this->assertSame([], glob(storage_path('app/private/career-cache-retention/*/plan.jsonl')));
            $this->assertNotNull(Cache::get($base.':versions:'.$old));
            // Reading the candidate resets idle time; restore its original retention age.
            $physical = 'retention-test:'.$base.':versions:'.$old;
            $redis->rawCommand('RESTORE', $physical, 0, $redis->dump($physical), 'REPLACE', 'IDLETIME', 259200);
            // The deletion-only Lua must remain usable while normal writes fail.
            $redis->config('SET', 'maxmemory', '1');
            $this->artisan('career:prune-public-cache-versions --apply')->assertSuccessful();
            $this->assertNull(Cache::get($base.':versions:'.$old));
            $this->assertSame(['payload' => 'current'], Cache::get($base.':versions:'.$active));
            $this->assertSame(['payload' => 'old'], Cache::get($base.':versions:'.$pinned));
            $this->assertSame('queue-sentinel', $redis->get('queues:default'));
            $backups = glob(storage_path('app/private/career-cache-retention/*/removed.jsonl'));
            $this->assertCount(1, $backups);
            $record = json_decode(trim(file_get_contents($backups[0])), true, flags: JSON_THROW_ON_ERROR);
            $redis->config('SET', 'maxmemory', '0');
            $redis->rawCommand('RESTORE', 'retention-test:'.$record['key'], 0, base64_decode($record['dump'], true));
            $this->assertSame(['payload' => 'old'], Cache::get($base.':versions:'.$old));
        } finally {
            $this->app->useStoragePath($originalStorage);
            $redis->close();
            $server->stop();
            File::deleteDirectory($directory);
        }
    }
}
