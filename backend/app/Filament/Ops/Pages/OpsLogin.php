<?php

namespace App\Filament\Ops\Pages;

use App\Services\Ops\OpsDistributedLimiter;
use App\Services\Ops\OpsLoginAudit;
use App\Services\Ops\OpsLoginTracer;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;

class OpsLogin extends BaseLogin
{
    protected static string $view = 'filament.ops.pages.auth.login';

    public function authenticate(): ?LoginResponse
    {
        $email = (string) data_get($this->data, 'email', '');
        $trace = OpsLoginTracer::context(request(), $email);

        OpsLoginTracer::start($trace);

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            OpsLoginAudit::fail($trace, 'rate_limited', [
                'seconds_until_available' => $exception->secondsUntilAvailable,
                'minutes_until_available' => $exception->minutesUntilAvailable,
            ]);

            return null;
        }

        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            OpsLoginAudit::fail($trace, 'invalid_credentials');

            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();

            OpsLoginAudit::fail($trace, 'blocked', [
                'user_id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
            ]);

            $this->throwFailureValidationException();
        }

        session()->regenerate();

        $this->clearDistributedLoginLimiters($data);

        OpsLoginTracer::success($trace, [
            'user_id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
        ]);

        return app(LoginResponse::class);
    }

    protected function getEmailFormComponent(): Component
    {
        /** @var Component $component */
        $component = parent::getEmailFormComponent();

        return $component
            ->autocomplete('username')
            ->extraInputAttributes([
                'autocapitalize' => 'none',
                'tabindex' => 1,
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var Component $component */
        $component = parent::getPasswordFormComponent();

        return $component
            ->autocomplete('current-password')
            ->extraInputAttributes([
                'tabindex' => 2,
            ]);
    }

    protected function getRateLimitKey($method, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];
        $component ??= static::class;

        return 'ops-login-rate-limiter:'.sha1($component.'|'.$method.'|'.(request()->ip() ?? 'unknown').'|'.$this->loginIdentifier());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function clearDistributedLoginLimiters(array $data): void
    {
        $ip = (string) (request()->ip() ?? 'unknown');
        $email = $this->loginIdentifier($data);

        OpsDistributedLimiter::clear('ops:login:ip:'.$ip);
        OpsDistributedLimiter::clear('ops:login:user:'.$email);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function loginIdentifier(?array $data = null): string
    {
        $data ??= is_array($this->data) ? $this->data : [];

        $identifier = mb_strtolower(trim((string) ($data['email'] ?? '')));

        return $identifier !== ''
            ? $identifier
            : 'anonymous:'.(request()->ip() ?? 'unknown');
    }
}
