<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpsEntryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_host_root_redirects_to_ops_when_host_matches_ops_prefix(): void
    {
        config()->set('admin.panel_enabled', true);
        config()->set('ops.allowed_host', '');
        config()->set('admin.url', '');

        $this->get('http://ops.example.test/')
            ->assertRedirect('/ops');
    }

    public function test_ops_host_root_redirects_to_ops_when_admin_url_host_matches_request_host(): void
    {
        config()->set('admin.panel_enabled', true);
        config()->set('ops.allowed_host', '');
        config()->set('admin.url', 'https://secure-ops.example.test/ops');

        $this->get('http://secure-ops.example.test/')
            ->assertRedirect('/ops');
    }

    public function test_non_ops_host_keeps_welcome_page_contract(): void
    {
        config()->set('admin.panel_enabled', true);
        config()->set('ops.allowed_host', '');
        config()->set('admin.url', '');

        $this->get('http://www.example.test/')
            ->assertOk();
    }
}
