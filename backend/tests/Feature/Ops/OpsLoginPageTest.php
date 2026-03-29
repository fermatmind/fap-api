<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpsLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_login_page_disables_browser_validation_and_uses_browser_friendly_autocomplete(): void
    {
        config()->set('admin.panel_enabled', true);

        $this->get('/ops/login')
            ->assertOk()
            ->assertSee('novalidate', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false);
    }
}
