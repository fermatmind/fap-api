<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DeliveryRedisTopologyTest extends TestCase
{
    public function test_real_redis_preserves_owner_lock_and_atomic_cache_readback(): void
    {
        if (getenv('RUN_DELIVERY_REDIS_TOPOLOGY') !== '1') {
            $this->markTestSkipped('Requires the disposable Nightly Redis service.');
        }
        $this->assertSame('127.0.0.1', getenv('REDIS_HOST'));
        $store = Cache::store('redis');
        $key = 'nightly:topology:'.bin2hex(random_bytes(12));
        $lock = $store->lock($key.':lock', 30);
        try {
            $this->assertTrue($lock->get());
            $this->assertFalse($store->lock($key.':lock', 30)->get());
            $store->put($key, ['fence' => 1, 'value' => 'verified'], 30);
            $this->assertSame(['fence' => 1, 'value' => 'verified'], $store->get($key));
            $this->assertTrue($lock->release());
            $next = $store->lock($key.':lock', 30);
            $this->assertTrue($next->get());
            $next->release();
        } finally {
            $store->forget($key);
            $lock->release();
        }
    }
}
