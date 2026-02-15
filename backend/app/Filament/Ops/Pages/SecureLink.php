<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Audit\AuditLogger;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SecureLink extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Secure Link';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'secure-link';

    protected static string $view = 'filament.ops.pages.secure-link';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.support');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.secure_link');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_SUPPORT)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public string $orderNo = '';

    public int $ttlMinutes = 15;

    public string $generatedLink = '';

    public string $statusMessage = '';

    public function generate(): void
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $orderNo = trim($this->orderNo);
        if ($orderNo === '') {
            $this->statusMessage = 'order_no is required.';

            return;
        }

        $order = DB::table('orders')
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            $this->statusMessage = 'order not found.';

            return;
        }

        $ttl = min(120, max(1, (int) $this->ttlMinutes));
        $token = 'secure_'.Str::random(32);

        cache()->put('ops_secure_link:'.$token, [
            'org_id' => $orgId,
            'order_no' => $orderNo,
            'attempt_id' => (string) ($order->target_attempt_id ?? ''),
            'created_by' => auth((string) config('admin.guard', 'admin'))->id(),
        ], now()->addMinutes($ttl));

        $base = rtrim((string) config('app.url', request()?->getSchemeAndHttpHost() ?? ''), '/');
        $this->generatedLink = $base.'/api/v0.2/claim/report?token='.urlencode($token);

        app(AuditLogger::class)->log(
            request(),
            'secure_link_generated',
            'Order',
            (string) ($order->id ?? ''),
            [
                'org_id' => $orgId,
                'order_no' => $orderNo,
                'correlation_id' => (string) Str::uuid(),
                'token_prefix' => substr($token, 0, 12),
                'ttl_minutes' => $ttl,
            ],
            'support_secure_link',
            'success',
        );

        $this->statusMessage = 'Secure link generated.';
    }
}
