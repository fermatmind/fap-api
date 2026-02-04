<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthzRateLimitTest extends TestCase
{
    public function test_healthz_reports_cache_queue_and_request_id(): void
    {
        $response = $this->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('deps.cache_store.ok', true);
        $response->assertJsonPath('deps.queue.ok', true);
        $response->assertJsonPath('deps.queue.driver', 'sync');
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }

    public function test_public_rate_limit_returns_retry_after(): void
    {
        config(['fap.rate_limits.api_public_per_minute' => 2]);

        $this->getJson('/api/v0.2/health')->assertStatus(200);
        $this->getJson('/api/v0.2/health')->assertStatus(200);

        $response = $this->getJson('/api/v0.2/health');

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'error' => ['code', 'message'],
        ]);
        $response->assertJsonPath('error.code', 'RATE_LIMIT_PUBLIC');
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }
}
