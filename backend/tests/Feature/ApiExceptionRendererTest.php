<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_exception_on_api_route_returns_json_without_accept_header(): void
    {
        Route::get('/api/__boom', static function (): void {
            throw new RuntimeException('boom');
        });

        $response = $this->get('/api/__boom');

        $response->assertStatus(500);
        $this->assertStringContainsString(
            'application/json',
            strtolower((string) $response->headers->get('Content-Type', ''))
        );
        $response->assertJson([
            'ok' => false,
            'error' => 'INTERNAL_ERROR',
            'message' => 'Internal Server Error',
        ]);
    }

    public function test_share_click_not_found_returns_json_without_accept_header(): void
    {
        $response = $this->post('/api/v0.2/shares/550e8400-e29b-41d4-a716-446655440000/click');

        $response->assertStatus(404);
        $this->assertStringContainsString(
            'application/json',
            strtolower((string) $response->headers->get('Content-Type', ''))
        );
        $response->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
            'message' => 'Not Found',
        ]);
    }
}
