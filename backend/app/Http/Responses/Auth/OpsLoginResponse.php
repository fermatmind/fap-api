<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class OpsLoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        if (Filament::getCurrentPanel()?->getId() !== 'ops') {
            return redirect()->intended(Filament::getCurrentPanel()?->getUrl() ?? Filament::getUrl());
        }

        $request->session()->forget('ops_admin_totp_verified_user_id');

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if (
            $user
            && (bool) config('admin.totp.enabled', true)
            && $user->totp_enabled_at !== null
        ) {
            return redirect()->to('/ops/two-factor-challenge');
        }

        return redirect()->to('/ops/select-org');
    }
}
