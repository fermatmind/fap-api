<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeprecatedApiVersionContractTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('deprecatedEndpoints')]
    public function test_v02_endpoint_returns_deprecated_contract(
        string $method,
        string $uri,
        array $payload = []
    ): void {
        $response = $this->json($method, $uri, $payload);

        $response->assertStatus(410);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'API_VERSION_DEPRECATED');
        $response->assertJsonMissingPath('error');

        $message = (string) $response->json('message', '');
        $this->assertNotSame('', trim($message));
    }

    public static function deprecatedEndpoints(): array
    {
        return [
            'healthz' => ['GET', '/api/v0.2/healthz'],
            'lookup ticket' => ['GET', '/api/v0.2/lookup/ticket/BAD'],
            'events' => ['POST', '/api/v0.2/events', ['event_code' => 'retired_event']],
            'integrations ingest' => ['POST', '/api/v0.2/integrations/mock/ingest', ['samples' => []]],
            'integrations revoke' => ['POST', '/api/v0.2/integrations/mock/revoke'],
            'integrations replay' => ['POST', '/api/v0.2/integrations/mock/replay/123'],
            'me profile' => ['GET', '/api/v0.2/me/profile'],
            'share click' => ['POST', '/api/v0.2/shares/abc123/click'],
        ];
    }
}
