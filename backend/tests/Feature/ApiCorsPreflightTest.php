<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ApiCorsPreflightTest extends TestCase
{
    public function test_options_preflight_allows_www_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v0.3/auth/guest', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.fermatmind.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://www.fermatmind.com');
        $response->assertHeader('Access-Control-Allow-Methods');

        $vary = (string) $response->headers->get('Vary', '');
        $this->assertStringContainsString('Origin', $vary);
    }

    public function test_options_preflight_does_not_allow_unknown_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v0.3/auth/guest', [], [], [], [
            'HTTP_ORIGIN' => 'https://malicious.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
