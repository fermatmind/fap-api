<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResolveAnonIdMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_prefers_header_over_cookie_for_client_anon_id(): void
    {
        $response = $this->withHeader('X-Anon-Id', 'anon_header_1')
            ->withCookie('fap_anonymous_id_v1', 'anon_cookie_1')
            ->getJson('/api/v0.3/boot');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('anon_id', 'anon_header_1');
    }

    public function test_boot_uses_cookie_when_header_missing(): void
    {
        $response = $this->call(
            'GET',
            '/api/v0.3/boot',
            [],
            ['fap_anonymous_id_v1' => 'anon_cookie_only']
        );

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('anon_id', 'anon_cookie_only');
    }

    public function test_attempt_start_prefers_client_anon_id_over_payload_anon_id(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();

        $response = $this->withHeader('X-Anon-Id', 'anon_header_start')
            ->postJson('/api/v0.3/attempts/start', [
                'scale_code' => 'SIMPLE_SCORE_DEMO',
                'anon_id' => 'anon_payload_start',
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $attemptId = (string) $response->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame('anon_header_start', (string) $attempt->anon_id);
    }
}
