<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QuestionsNo500FallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_endpoint_does_not_500_when_legacy_registry_is_unavailable(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        (new ScaleRegistrySeeder)->run();

        Schema::dropIfExists('scales_registry');

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&region=GLOBAL');

        $this->assertNotSame(500, $response->getStatusCode());

        if ($response->getStatusCode() >= 400) {
            $response->assertJsonStructure([
                'ok',
                'error_code',
                'message',
                'request_id',
            ]);
        } else {
            $response->assertStatus(200);
            $this->assertIsArray((array) data_get($response->json(), 'questions.items', []));
        }
    }
}
