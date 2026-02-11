<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\NormalizeApiErrorContract;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiErrorContractMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(NormalizeApiErrorContract::class)->get('/api/__pr52/error-string', static function () {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'missing',
            ], 404);
        });

        Route::middleware(NormalizeApiErrorContract::class)->get('/api/__pr52/error-object', static function () {
            return response()->json([
                'error' => [
                    'code' => 'rate_limit_public',
                    'message' => 'too many requests',
                ],
            ], 429);
        });
    }

    public function test_string_error_gets_top_level_error_code(): void
    {
        $response = $this->getJson('/api/__pr52/error-string');

        $response->assertStatus(404);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'NOT_FOUND');
        $response->assertJsonPath('message', 'missing');
        $response->assertJsonMissingPath('error');

        $requestId = (string) $response->json('request_id', '');
        $this->assertNotSame('', $requestId);

        $decoded = json_decode((string) $response->getContent());
        $this->assertIsObject($decoded);
        $this->assertEquals((object) [], $decoded->details ?? null);
    }

    public function test_object_error_gets_top_level_error_code(): void
    {
        $response = $this->getJson('/api/__pr52/error-object');

        $response->assertStatus(429);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_PUBLIC');
        $response->assertJsonPath('message', 'too many requests');
        $response->assertJsonMissingPath('error');

        $requestId = (string) $response->json('request_id', '');
        $this->assertNotSame('', $requestId);

        $decoded = json_decode((string) $response->getContent());
        $this->assertIsObject($decoded);
        $this->assertEquals((object) [], $decoded->details ?? null);
    }
}
