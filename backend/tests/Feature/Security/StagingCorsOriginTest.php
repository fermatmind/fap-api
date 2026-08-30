<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Env;
use Tests\TestCase;

final class StagingCorsOriginTest extends TestCase
{
    public function test_runtime_frontend_origin_is_limited_to_the_fermatmind_allowlist(): void
    {
        $repository = Env::getRepository();
        $original = $repository->get('FRONTEND_URL');

        try {
            $repository->set('FRONTEND_URL', 'https://staging.fermatmind.com');
            $staging = require config_path('cors.php');
            $this->assertContains('https://staging.fermatmind.com', $staging['allowed_origins']);

            $repository->set('FRONTEND_URL', 'https://untrusted.example');
            $untrusted = require config_path('cors.php');
            $this->assertNotContains('https://untrusted.example', $untrusted['allowed_origins']);
            $this->assertSame(
                ['https://www.fermatmind.com', 'https://fermatmind.com'],
                array_values(array_unique($untrusted['allowed_origins']))
            );
        } finally {
            if ($original === null) {
                $repository->clear('FRONTEND_URL');
            } else {
                $repository->set('FRONTEND_URL', $original);
            }
        }
    }
}
