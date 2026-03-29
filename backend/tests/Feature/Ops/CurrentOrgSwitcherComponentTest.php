<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Livewire\Filament\Ops\Livewire\CurrentOrgSwitcher;
use App\Models\Organization;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CurrentOrgSwitcherComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_org_switcher_component_can_boot(): void
    {
        Livewire::test(CurrentOrgSwitcher::class)->assertOk();
        Livewire::test('filament.ops.livewire.current-org-switcher')->assertOk();
    }

    public function test_current_org_switcher_reads_org_context_and_clearing_selection_returns_to_select_org(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Switcher Org',
            'owner_user_id' => 1,
            'status' => 'active',
            'domain' => 'switcher.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        session(['ops_org_id' => (int) $organization->id]);

        $context = app(OrgContext::class);
        $context->set((int) $organization->id, 1, 'admin', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        Livewire::test(CurrentOrgSwitcher::class)
            ->assertOk()
            ->assertSet('orgId', (int) $organization->id)
            ->assertSee($organization->name)
            ->call('goSelectOrg')
            ->assertRedirect('/ops/select-org');

        $this->assertNull(session('ops_org_id'));
    }
}
