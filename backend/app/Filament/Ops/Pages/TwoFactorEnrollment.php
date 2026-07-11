<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Auth\AdminTotpService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TwoFactorEnrollment extends Page
{
    protected static ?string $slug = 'two-factor-enrollment';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.ops.pages.two-factor-enrollment';

    public string $secret = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public string $code = '';

    public function mount(AdminTotpService $totp): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if ($user?->totp_enabled_at !== null) {
            $this->redirectRoute('filament.ops.pages.two-factor-challenge', navigate: true);

            return;
        }

        $this->secret = $totp->generateSecret();
        $this->recoveryCodes = $totp->generateRecoveryCodes();
    }

    public function enroll(AdminTotpService $totp): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if (! $user || $this->secret === '' || $this->recoveryCodes === []) {
            abort(403);
        }

        $user->forceFill(['totp_secret' => $this->secret]);
        if (! $totp->verify($user, $this->code)) {
            Notification::make()->title('Invalid verification code')->danger()->send();

            return;
        }

        $totp->enableForUser($user, $this->secret, $this->recoveryCodes);
        session(['ops_admin_totp_verified_user_id' => (int) $user->id]);
        Notification::make()->title('2FA enrollment complete')->success()->send();
        $this->redirect('/ops/select-org', navigate: true);
    }

    public static function canAccess(): bool
    {
        return auth((string) config('admin.guard', 'admin'))->check();
    }
}
