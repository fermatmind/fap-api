<?php

declare(strict_types=1);

namespace App\Livewire\Filament\Ops\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Livewire\Component;

final class LocaleSwitcher extends Component
{
    public string $locale = 'zh_CN';

    /** @var array<string, string> */
    public array $locales = [
        'zh_CN' => '中文',
        'en' => 'English',
    ];

    public function mount(): void
    {
        $current = app()->getLocale();
        $this->locale = array_key_exists($current, $this->locales) ? $current : 'zh_CN';
    }

    public function setLocale(string $locale): void
    {
        if (! array_key_exists($locale, $this->locales)) {
            return;
        }

        session()->put('ops_locale', $locale);

        Cookie::queue(
            Cookie::make(
                name: 'ops_locale',
                value: $locale,
                minutes: 60 * 24 * 365,
                path: '/ops',
                domain: null,
                secure: (bool) config('session.secure'),
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            )
        );

        if (
            Filament::getCurrentPanel()?->getId() === 'ops'
            && Filament::auth()->check()
        ) {
            $user = Filament::auth()->user();
            $user?->forceFill(['preferred_locale' => $locale])->save();
        }

        $this->redirect(request()->fullUrl(), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.filament.ops.livewire.locale-switcher');
    }
}

