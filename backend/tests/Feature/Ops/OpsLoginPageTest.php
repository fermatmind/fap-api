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
            ->assertSee('wire:submit="authenticate"', false)
            ->assertSee('novalidate', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee("\$wire.set('data.email', emailInput.value)", false)
            ->assertSee("\$wire.set('data.password', passwordInput.value)", false)
            ->assertSee('window.__opsLivewirePageExpiredRecoveryHookInstalled', false)
            ->assertSee("const autoRefreshStorageKeyPrefix = 'ops-livewire-page-expired-at:'", false)
            ->assertSee("window.Livewire?.hook('request'", false)
            ->assertSee("if (status !== 419 || ! pathname.startsWith('/ops'))", false)
            ->assertSee('window.location.reload()', false);
    }
}
