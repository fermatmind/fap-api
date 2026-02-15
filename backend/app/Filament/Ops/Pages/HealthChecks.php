<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthChecks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'SRE';

    protected static ?string $navigationLabel = 'Health Checks';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'health-checks';

    protected static string $view = 'filament.ops.pages.health-checks';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.sre');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.health_checks');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_SRE)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    /** @var array<string,mixed> */
    public array $checks = [];

    public function mount(): void
    {
        $this->refreshChecks();
    }

    public function refreshChecks(): void
    {
        $this->checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'mailer' => $this->checkMailer(),
        ];
    }

    /** @return array<string,mixed> */
    private function checkDb(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function checkRedis(): array
    {
        try {
            $driver = (string) config('cache.default', 'file');
            $queueDriver = (string) config('queue.default', 'sync');
            if ($driver !== 'redis' && $queueDriver !== 'redis') {
                return ['ok' => true, 'message' => 'not required'];
            }

            $pong = Redis::connection()->ping();

            return ['ok' => true, 'message' => (string) $pong];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function checkQueue(): array
    {
        try {
            $driver = (string) config('queue.default', 'sync');
            $failed = \App\Support\SchemaBaseline::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0;

            return ['ok' => true, 'message' => 'driver='.$driver.', failed_jobs='.$failed];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function checkMailer(): array
    {
        try {
            $mailer = trim((string) config('mail.default', ''));
            $host = trim((string) config('mail.mailers.smtp.host', ''));
            $port = trim((string) config('mail.mailers.smtp.port', ''));
            $encryption = trim((string) config('mail.mailers.smtp.encryption', ''));
            $fromAddress = trim((string) config('mail.from.address', ''));
            $fromName = trim((string) config('mail.from.name', ''));
            $usernameSet = trim((string) config('mail.mailers.smtp.username', '')) !== '';
            $passwordSet = trim((string) config('mail.mailers.smtp.password', '')) !== '';

            $summary = implode(', ', array_filter([
                'mailer=' . ($mailer !== '' ? $mailer : 'unknown'),
                'host=' . ($host !== '' ? $host : 'n/a'),
                'port=' . ($port !== '' ? $port : 'n/a'),
                'encryption=' . ($encryption !== '' ? $encryption : 'none'),
                'from=' . ($fromAddress !== '' ? $fromAddress : 'n/a'),
                'from_name=' . ($fromName !== '' ? $fromName : 'n/a'),
                'smtp_username_set=' . ($usernameSet ? 'yes' : 'no'),
                'smtp_password_set=' . ($passwordSet ? 'yes' : 'no'),
            ]));

            $ok = $mailer !== '';
            if ($mailer === 'smtp') {
                $ok = $ok && $host !== '' && $port !== '' && $fromAddress !== '';
            }

            return ['ok' => $ok, 'message' => $summary];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
