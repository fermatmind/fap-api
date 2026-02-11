<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ValidationErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_request_validation_returns_unified_json_contract_without_accept_header(): void
    {
        $response = $this->post('/api/v0.3/attempts/start', []);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'application/json',
            strtolower((string) $response->headers->get('Content-Type', ''))
        );
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonPath('message', 'The given data was invalid.');

        $details = $response->json('details.scale_code');
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);
    }

    public function test_abort_401_403_404_500_all_return_json_under_api_path(): void
    {
        Route::middleware('api')->get('/api/v0.3/__contract_abort_401', static function (): void {
            abort(401, 'unauth');
        });
        Route::middleware('api')->get('/api/v0.3/__contract_abort_403', static function (): void {
            abort(403, 'forbidden');
        });
        Route::middleware('api')->get('/api/v0.3/__contract_abort_404', static function (): void {
            abort(404, 'missing');
        });
        Route::middleware('api')->get('/api/v0.3/__contract_abort_500', static function (): void {
            abort(500, 'boom');
        });

        $cases = [
            ['/api/v0.3/__contract_abort_401', 401, 'UNAUTHENTICATED'],
            ['/api/v0.3/__contract_abort_403', 403, 'FORBIDDEN'],
            ['/api/v0.3/__contract_abort_404', 404, 'NOT_FOUND'],
            ['/api/v0.3/__contract_abort_500', 500, 'SERVER_ERROR'],
        ];

        foreach ($cases as [$uri, $status, $errorCode]) {
            $response = $this->get((string) $uri);

            $response->assertStatus((int) $status);
            $this->assertStringContainsString(
                'application/json',
                strtolower((string) $response->headers->get('Content-Type', ''))
            );
            $response->assertJsonPath('error_code', (string) $errorCode);
        }
    }
}
