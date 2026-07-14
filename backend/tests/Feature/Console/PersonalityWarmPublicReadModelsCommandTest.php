<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Controllers\API\V0_5\Cms\PersonalityController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

final class PersonalityWarmPublicReadModelsCommandTest extends TestCase
{
    public function test_it_warms_selected_variant_detail_and_seo_for_both_public_locales(): void
    {
        $controller = Mockery::mock(PersonalityController::class);
        $controller->shouldReceive('show')
            ->twice()
            ->withArgs(fn (Request $request, string $type): bool => $type === 'intj-a'
                && in_array($request->query('locale'), ['en', 'zh-CN'], true))
            ->andReturn($this->cachedResponse(
                ['ok' => true, 'mbti_public_projection_v1' => ['display_type' => 'INTJ-A']],
                'miss',
            ));
        $controller->shouldReceive('seo')
            ->twice()
            ->withArgs(fn (Request $request, string $type): bool => $type === 'intj-a'
                && in_array($request->query('locale'), ['en', 'zh-CN'], true))
            ->andReturn($this->cachedResponse(['meta' => ['seo_title' => 'INTJ-A']], 'fresh'));
        $this->app->instance(PersonalityController::class, $controller);

        $this->artisan('personality:warm-public-read-models', [
            '--types' => 'INTJ-A',
            '--locales' => 'en,zh-CN',
        ])->expectsOutputToContain('type=INTJ-A locale=en detail=200 detail_cache=miss seo=200 seo_cache=fresh')
            ->expectsOutputToContain('type=INTJ-A locale=zh-CN detail=200 detail_cache=miss seo=200 seo_cache=fresh')
            ->assertSuccessful();
    }

    public function test_it_fails_closed_for_invalid_types_or_locales(): void
    {
        $this->artisan('personality:warm-public-read-models', [
            '--types' => 'INVALID',
            '--locales' => 'en',
        ])->assertFailed();

        $this->artisan('personality:warm-public-read-models', [
            '--types' => 'INTJ-A',
            '--locales' => 'fr',
        ])->assertFailed();
    }

    public function test_it_enforces_the_detail_payload_budget(): void
    {
        $controller = Mockery::mock(PersonalityController::class);
        $controller->shouldReceive('show')->once()->andReturn($this->cachedResponse([
            'payload' => str_repeat('x', 530000),
        ], 'miss'));
        $controller->shouldReceive('seo')->once()->andReturn($this->cachedResponse(['meta' => []], 'fresh'));
        $this->app->instance(PersonalityController::class, $controller);

        $this->artisan('personality:warm-public-read-models', [
            '--types' => 'INTJ-A',
            '--locales' => 'en',
        ])->expectsOutputToContain('budget=fail')
            ->assertFailed();
    }

    public function test_it_rejects_stale_or_unclassified_readbacks(): void
    {
        $controller = Mockery::mock(PersonalityController::class);
        $controller->shouldReceive('show')->twice()->andReturn(
            $this->cachedResponse(['ok' => true], 'stale'),
            new JsonResponse(['ok' => true]),
        );
        $controller->shouldReceive('seo')->twice()->andReturn(
            $this->cachedResponse(['meta' => []], 'fresh'),
            $this->cachedResponse(['meta' => []], 'fresh'),
        );
        $this->app->instance(PersonalityController::class, $controller);

        $this->artisan('personality:warm-public-read-models', [
            '--types' => 'INTJ-A',
            '--locales' => 'en,zh-CN',
        ])->expectsOutputToContain('detail_cache=stale')
            ->expectsOutputToContain('detail_cache=unknown')
            ->assertFailed();
    }

    /** @param array<string, mixed> $payload */
    private function cachedResponse(array $payload, string $cacheState): JsonResponse
    {
        return (new JsonResponse($payload))->header('X-Fermat-Public-Read-Cache', $cacheState);
    }
}
