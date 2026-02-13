<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Http\Middleware\FmTokenAuth;
use App\Services\Legacy\LegacyShareFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class ShareControllerObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_returns_404_when_share_is_missing(): void
    {
        $response = $this->postJson('/api/v0.2/shares/missing-share-001/click', []);

        $response->assertStatus(404);
    }

    public function test_click_unexpected_exception_returns_500_and_logs_coordinates(): void
    {
        $shareId = 'shareobs123456';

        Log::spy();
        $this->mock(LegacyShareFlowService::class, function (MockInterface $mock) use ($shareId): void {
            $mock->shouldReceive('clickAndComposeReport')
                ->once()
                ->with($shareId, \Mockery::type('array'), \Mockery::type('array'))
                ->andThrow(new RuntimeException('db connection lost'));
        });

        $response = $this->withHeader('X-Org-Id', '123')
            ->postJson("/api/v0.2/shares/{$shareId}/click", []);

        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($shareId): bool {
                return $message === 'share_flow_failed'
                    && (string) ($context['action'] ?? '') === 'click'
                    && (string) ($context['share_id'] ?? '') === $shareId
                    && isset($context['request_id'])
                    && is_string($context['request_id'])
                    && trim($context['request_id']) !== ''
                    && ($context['exception'] ?? null) instanceof RuntimeException;
        });
    }

    public function test_get_share_unexpected_exception_returns_500_and_logs_coordinates(): void
    {
        $attemptId = (string) Str::uuid();

        Log::spy();
        $this->withoutMiddleware(FmTokenAuth::class);
        $this->mock(LegacyShareFlowService::class, function (MockInterface $mock) use ($attemptId): void {
            $mock->shouldReceive('getShareLinkForAttempt')
                ->once()
                ->with($attemptId, \Mockery::type('array'))
                ->andThrow(new RuntimeException('db timeout'));
        });

        $response = $this->withHeader('X-Org-Id', '456')
            ->getJson("/api/v0.2/attempts/{$attemptId}/share");

        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === 'share_flow_failed'
                    && (string) ($context['action'] ?? '') === 'get_share'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId
                    && isset($context['request_id'])
                    && is_string($context['request_id'])
                    && trim($context['request_id']) !== ''
                    && ($context['exception'] ?? null) instanceof RuntimeException;
        });
    }
}
