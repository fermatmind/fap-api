<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOpsLocale
{
    public const SESSION_KEY = 'ops_locale';

    public const COOKIE_KEY = 'ops_locale';

    public const EXPLICIT_SESSION_KEY = 'ops_locale_explicit';

    public const EXPLICIT_COOKIE_KEY = 'ops_locale_explicit';

    public const DEFAULT_LOCALE = 'zh_CN';

    public function handle(Request $request, Closure $next): Response
    {
        $picked = (string) ($request->query('locale') ?: $request->query('lang') ?: '');
        $explicit = $picked !== '';

        if (
            $picked === ''
            && Filament::getCurrentPanel()?->getId() === 'ops'
            && Filament::auth()->check()
        ) {
            $user = Filament::auth()->user();
            $picked = (string) ($user?->preferred_locale ?? '');
            $explicit = $picked !== '';
        }

        if ($picked === '') {
            $picked = (string) session(self::SESSION_KEY, '');
            $explicit = $picked !== '' && (bool) session(self::EXPLICIT_SESSION_KEY, false);
        }

        if ($picked === '') {
            $picked = (string) $request->cookie(self::COOKIE_KEY, '');
            $explicit = false;
        }

        $locale = $this->normalizeAndWhitelist($picked);

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        $request->attributes->set('ops_locale_explicit', $explicit);
        session()->put(self::SESSION_KEY, $locale);

        $response = $next($request);

        $cookie = cookie(
            name: self::COOKIE_KEY,
            value: $locale,
            minutes: 60 * 24 * 365,
            path: '/ops',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );

        $explicitCookie = cookie(
            name: self::EXPLICIT_COOKIE_KEY,
            value: $explicit ? '1' : '0',
            minutes: 60 * 24 * 365,
            path: '/ops',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );

        return $response->withCookie($cookie)->withCookie($explicitCookie);
    }

    private function normalizeAndWhitelist(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));

        return match ($locale) {
            'zh', 'zh_CN' => 'zh_CN',
            'en', 'en_US', 'en_GB' => 'en',
            default => self::DEFAULT_LOCALE,
        };
    }
}
