<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Livewire\Filament\Ops\Livewire\LocaleSwitcher;
use Livewire\Livewire;
use Tests\TestCase;

final class LocaleSwitcherComponentTest extends TestCase
{
    public function test_locale_switcher_component_mounts_with_current_locale(): void
    {
        app()->setLocale('en');

        Livewire::test(LocaleSwitcher::class)
            ->assertSet('locale', 'en');
    }

    public function test_set_locale_updates_session_for_supported_locale(): void
    {
        Livewire::test(LocaleSwitcher::class)
            ->call('setLocale', 'zh_CN');

        $this->assertSame('zh_CN', session('ops_locale'));
    }

    public function test_set_locale_ignores_unsupported_locale(): void
    {
        session()->put('ops_locale', 'en');

        Livewire::test(LocaleSwitcher::class)
            ->call('setLocale', 'invalid-locale');

        $this->assertSame('en', session('ops_locale'));
    }
}

