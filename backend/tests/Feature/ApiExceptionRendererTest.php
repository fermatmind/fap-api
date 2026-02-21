<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            'error_code' => 'INTERNAL_ERROR',
            'message' => 'Internal error.',
        ]);
        $decoded = json_decode((string) $response->getContent());
        $this->assertIsObject($decoded);
        $this->assertEquals((object) [], $decoded->details ?? null);
        $response->assertJsonMissingPath('error');
    }

    public function test_validation_exception_is_standardized(): void
    {
        Route::post('/api/v0.3/_test_validation', static function (Request $request): array {
            $request->validate([
                'email' => ['required', 'email'],
            ]);

            return ['ok' => true];
        })->middleware('api');

        $response = $this->post('/api/v0.3/_test_validation', []);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'application/json',
            strtolower((string) $response->headers->get('Content-Type', ''))
        );
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonMissingPath('error');

        $emailDetails = $response->json('details.email');
        $this->assertIsArray($emailDetails);
        $this->assertNotEmpty($emailDetails);

        $requestId = (string) $response->json('request_id', '');
        $this->assertNotSame('', $requestId);
    }

    public function test_abort_401_is_json(): void
    {
        Route::get('/api/v0.3/_test_abort_401', static function (): void {
            abort(401, 'nope');
        })->middleware('api');

        $response = $this->get('/api/v0.3/_test_abort_401');

        $response->assertStatus(401);
        $this->assertStringContainsString(
            'application/json',
            strtolower((string) $response->headers->get('Content-Type', ''))
        );
        $response->assertJsonPath('error_code', 'UNAUTHORIZED');
        $response->assertJsonPath('message', 'nope');
        $response->assertJsonMissingPath('error');

        $requestId = (string) $response->json('request_id', '');
        $this->assertNotSame('', $requestId);
    }
}
