<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Tests\TestCase;

final class MeAttemptsAuthContractTest extends TestCase
{
    public function test_me_attempts_unauthorized_contains_error_code(): void
    {
        $response = $this->getJson('/api/v0.2/me/attempts');

        $response->assertStatus(401);
        $response->assertJson([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'error_code' => 'UNAUTHORIZED',
        ]);
    }
}
