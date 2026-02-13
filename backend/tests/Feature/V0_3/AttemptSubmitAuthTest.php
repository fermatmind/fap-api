<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AttemptSubmitAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_requires_fm_token(): void
    {
        $response = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => '00000000-0000-0000-0000-000000000000',
            'answers' => [],
            'duration_ms' => 1,
        ]);

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHENTICATED',
        ]);
    }
}
