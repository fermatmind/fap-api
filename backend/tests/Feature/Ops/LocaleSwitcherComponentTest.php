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
            ->call('setLocale', 'zh_CN', 'https://ops.fermatmind.com/ops')
            ->assertRedirect('/ops');

        $this->assertSame('zh_CN', session('ops_locale'));
        $this->assertTrue((bool) session('ops_locale_explicit'));
    }

    public function test_set_locale_ignores_unsupported_locale(): void
    {
        session()->put('ops_locale', 'en');

        Livewire::test(LocaleSwitcher::class)
            ->call('setLocale', 'invalid-locale');

        $this->assertSame('en', session('ops_locale'));
    }

    public function test_set_locale_redirects_back_to_current_ops_page_instead_of_livewire_endpoint(): void
    {
        Livewire::withQueryParams(['tab' => 'dashboard'])
            ->test(LocaleSwitcher::class)
            ->call('setLocale', 'en', '/ops/content-overview?tab=dashboard')
            ->assertRedirect('/ops/content-overview?tab=dashboard');
    }

    public function test_set_locale_rejects_non_ops_or_cross_origin_return_urls(): void
    {
        Livewire::test(LocaleSwitcher::class)
            ->call('setLocale', 'en', 'https://evil.example.test/livewire/update')
            ->assertRedirect('/ops');
    }
}
