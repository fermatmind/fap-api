<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Livewire\Filament\Ops\Livewire\CurrentOrgSwitcher;
use Livewire\Livewire;
use Tests\TestCase;

final class CurrentOrgSwitcherComponentTest extends TestCase
{
    public function test_current_org_switcher_component_can_boot(): void
    {
        Livewire::test(CurrentOrgSwitcher::class)->assertOk();
        Livewire::test('filament.ops.livewire.current-org-switcher')->assertOk();
    }
}
