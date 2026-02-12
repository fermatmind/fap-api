<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthzExposureTest extends TestCase
{
    public function test_public_ip_is_blocked_outside_local_and_testing(): void
    {
        $this->forceEnvironment('production');
        config(['healthz.allowed_ips' => ['127.0.0.1/32']]);

        $server = ['REMOTE_ADDR' => '8.8.8.8'];

        $this->withServerVariables($server)
            ->getJson('/api/healthz')
            ->assertStatus(404);

        $this->withServerVariables($server)
            ->getJson('/api/v0.2/healthz')
            ->assertStatus(404);
    }

    public function test_allowlist_ip_gets_minimal_healthz_payload(): void
    {
        $this->forceEnvironment('production');
        config([
            'healthz.allowed_ips' => ['127.0.0.1/32'],
            'healthz.verbose' => false,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/healthz');

        $response->assertStatus(200);
        $response->assertJsonStructure(['ok', 'service', 'version', 'time']);
        $response->assertJsonMissingPath('deps');
        $response->assertJsonMissingPath('path');
        $response->assertJsonMissingPath('base_path');
        $response->assertJsonMissingPath('default_path');
        $response->assertJsonMissingPath('driver');
        $response->assertJsonMissingPath('message');
        $this->assertSame(['ok', 'service', 'version', 'time'], array_keys($response->json()));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/v0.2/healthz')
            ->assertStatus(200);
    }

    private function forceEnvironment(string $env): void
    {
        $this->app->detectEnvironment(static fn () => $env);
        $this->app->instance('env', $env);
    }
}
