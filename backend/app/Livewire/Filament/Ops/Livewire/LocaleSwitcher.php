<?php

declare(strict_types=1);

namespace App\Livewire\Filament\Ops\Livewire;

use App\Http\Middleware\SetOpsLocale;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
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

    public function setLocale(string $locale, ?string $returnUrl = null): void
    {
        if (! array_key_exists($locale, $this->locales)) {
            return;
        }

        session()->put(SetOpsLocale::SESSION_KEY, $locale);
        session()->put(SetOpsLocale::EXPLICIT_SESSION_KEY, true);

        Cookie::queue(
            Cookie::make(
                name: SetOpsLocale::COOKIE_KEY,
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
        Cookie::queue(
            Cookie::make(
                name: SetOpsLocale::EXPLICIT_COOKIE_KEY,
                value: '1',
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

        $this->redirect($this->resolveReturnUrl($returnUrl), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.filament.ops.livewire.locale-switcher');
    }

    private function resolveReturnUrl(?string $returnUrl): string
    {
        $returnUrl = trim((string) $returnUrl);
        if ($returnUrl === '') {
            return $this->fallbackReturnUrl();
        }

        if (Str::startsWith($returnUrl, '/')) {
            return Str::startsWith($returnUrl, '/ops') ? $returnUrl : $this->fallbackReturnUrl();
        }

        $parts = parse_url($returnUrl);
        $path = trim((string) ($parts['path'] ?? ''));
        if ($path === '' || ! Str::startsWith($path, '/ops')) {
            return $this->fallbackReturnUrl();
        }

        $requestOrigin = request()->getSchemeAndHttpHost();
        $candidateOrigin = sprintf(
            '%s://%s',
            (string) ($parts['scheme'] ?? request()->getScheme()),
            (string) ($parts['host'] ?? request()->getHost())
        );

        if ($candidateOrigin !== $requestOrigin) {
            return $this->fallbackReturnUrl();
        }

        $query = trim((string) ($parts['query'] ?? ''));
        $fragment = trim((string) ($parts['fragment'] ?? ''));

        return $path
            .($query !== '' ? '?'.$query : '')
            .($fragment !== '' ? '#'.$fragment : '');
    }

    private function fallbackReturnUrl(): string
    {
        $panelPath = trim((string) (Filament::getCurrentPanel()?->getPath() ?? 'ops'), '/');

        return '/'.($panelPath !== '' ? $panelPath : 'ops');
    }
}
