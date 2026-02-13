<?php

declare(strict_types=1);

namespace Tests\Feature\SelfCheck;

use App\Services\SelfCheck\V2\SelfCheckIoV2;
use Mockery;
use Tests\TestCase;

final class SelfCheckContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_deps_structure_contract_is_stable_with_v2_enabled(): void
    {
        $this->mockDeps($this->okDeps());

        $response = $this->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'service',
            'version',
            'time',
            'deps' => ['db', 'redis', 'queue', 'cache_dirs', 'content_source', 'disk'],
        ]);
    }

    public function test_db_probe_error_code_is_stable(): void
    {
        $deps = $this->okDeps();
        $deps['db'] = ['ok' => false, 'error_code' => 'DB_UNAVAILABLE', 'message' => 'down'];
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');
        $response->assertStatus(200);
        $response->assertJsonPath('deps.db.error_code', 'DB_UNAVAILABLE');
    }

    public function test_redis_probe_error_code_is_stable(): void
    {
        $deps = $this->okDeps();
        $deps['redis'] = ['ok' => false, 'error_code' => 'REDIS_UNAVAILABLE', 'message' => 'down'];
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');
        $response->assertStatus(200);
        $response->assertJsonPath('deps.redis.error_code', 'REDIS_UNAVAILABLE');
    }

    public function test_cache_dir_probe_error_code_is_stable(): void
    {
        $deps = $this->okDeps();
        $deps['cache_dirs'] = ['ok' => false, 'error_code' => 'CACHE_DIR_NOT_WRITABLE', 'message' => 'not writable'];
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');
        $response->assertStatus(200);
        $response->assertJsonPath('deps.cache_dirs.error_code', 'CACHE_DIR_NOT_WRITABLE');
    }

    public function test_content_packages_probe_error_code_is_stable(): void
    {
        $deps = $this->okDeps();
        $deps['content_source'] = ['ok' => false, 'error_code' => 'CONTENT_SOURCE_NOT_READY', 'message' => 'missing'];
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');
        $response->assertStatus(200);
        $response->assertJsonPath('deps.content_source.error_code', 'CONTENT_SOURCE_NOT_READY');
    }

    public function test_verbose_false_hides_deps_and_sensitive_paths(): void
    {
        $this->mockDeps($this->okDeps());
        config(['healthz.verbose' => false]);

        $response = $this->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('deps');
        $response->assertJsonMissingPath('base_path');
        $response->assertJsonMissingPath('default_path');
    }

    public function test_probe_order_is_stable_for_v2_output(): void
    {
        $deps = $this->okDeps();
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');
        $response->assertStatus(200);

        $this->assertSame(
            ['db', 'redis', 'queue', 'cache_dirs', 'content_source', 'disk'],
            array_keys((array) $response->json('deps'))
        );
    }

    public function test_verbose_deps_do_not_expose_probe_message_text(): void
    {
        $deps = $this->okDeps();
        $deps['db'] = [
            'ok' => false,
            'error_code' => 'DB_UNAVAILABLE',
            'message' => 'SQLSTATE token=secret path=/var/app',
        ];
        $this->mockDeps($deps);

        $response = $this->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonPath('deps.db.error_code', 'DB_UNAVAILABLE');
        $response->assertJsonMissingPath('deps.db.message');
    }

    public function test_v2_healthz_response_is_fast_enough_for_probe_timeout_guard(): void
    {
        $this->mockDeps($this->okDeps());

        $start = microtime(true);
        $response = $this->getJson('/api/healthz');
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $response->assertStatus(200);
        $this->assertLessThan(2000, $elapsedMs);
    }

    /**
     * @param array<string,mixed> $deps
     */
    private function mockDeps(array $deps): void
    {
        config([
            'features.selfcheck_v2' => true,
            'healthz.verbose' => true,
        ]);

        $mock = Mockery::mock(SelfCheckIoV2::class);
        $mock->shouldReceive('collectDeps')
            ->andReturn($deps);

        $this->app->instance(SelfCheckIoV2::class, $mock);
    }

    /**
     * @return array<string,mixed>
     */
    private function okDeps(): array
    {
        return [
            'db' => ['ok' => true, 'error_code' => ''],
            'redis' => ['ok' => true, 'error_code' => ''],
            'queue' => ['ok' => true, 'error_code' => ''],
            'cache_dirs' => ['ok' => true, 'error_code' => ''],
            'content_source' => ['ok' => true, 'error_code' => ''],
            'disk' => ['ok' => true, 'error_code' => ''],
        ];
    }
}
