<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Auth\AdminTotpService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TwoFactorChallenge extends Page
{
    protected static ?string $slug = 'two-factor-challenge';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.ops.pages.two-factor-challenge';

    public string $code = '';

    public function verify(AdminTotpService $totp): void
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if (! $user) {
            $this->redirect('/ops/login', navigate: true);

            return;
        }

        if (! (bool) config('admin.totp.enabled', true) || $user->totp_enabled_at === null) {
            session(['ops_admin_totp_verified_user_id' => (int) $user->id]);
            $this->redirect('/ops/select-org', navigate: true);

            return;
        }

        $ok = $totp->verify($user, $this->code);
        if (! $ok) {
            Notification::make()
                ->title('Invalid verification code')
                ->danger()
                ->send();

            return;
        }

        session(['ops_admin_totp_verified_user_id' => (int) $user->id]);

        Notification::make()
            ->title('2FA verified')
            ->success()
            ->send();

        $this->redirect('/ops/select-org', navigate: true);
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');

        return auth($guard)->check();
    }
}
