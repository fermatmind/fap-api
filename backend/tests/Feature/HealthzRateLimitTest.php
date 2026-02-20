<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Tests\TestCase;

class HealthzRateLimitTest extends TestCase
{
    public function test_healthz_returns_minimal_payload_and_request_id(): void
    {
        config([
            'healthz.allowed_ips' => ['127.0.0.1/32'],
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure(['ok', 'service', 'version', 'time']);
        $response->assertJsonMissingPath('deps');
        $response->assertJsonMissingPath('path');
        $response->assertJsonMissingPath('base_path');
        $response->assertJsonMissingPath('default_path');
        $response->assertJsonMissingPath('driver');
        $response->assertJsonMissingPath('message');
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }

    public function test_public_rate_limit_returns_retry_after(): void
    {
        config([
            'fap.rate_limits.api_public_per_minute' => 2,
            'fap.rate_limits.bypass_in_test_env' => false,
            'healthz.allowed_ips' => ['198.51.100.23/32'],
        ]);

        $server = ['REMOTE_ADDR' => '198.51.100.23'];
        $ip = Request::create('/api/healthz', 'GET', [], [], [], $server)->ip();
        app(RateLimiter::class)->clear(md5('api_public' . 'ip:' . $ip));

        $this->withServerVariables($server)->getJson('/api/healthz')->assertStatus(200);
        $this->withServerVariables($server)->getJson('/api/healthz')->assertStatus(200);

        $response = $this->withServerVariables($server)->getJson('/api/healthz');

        $response->assertStatus(429);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }
}
