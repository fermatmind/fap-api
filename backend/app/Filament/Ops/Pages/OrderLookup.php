<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class OrderLookup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Order Lookup';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'order-lookup';

    protected static string $view = 'filament.ops.pages.order-lookup';

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

    public string $email = '';

    public string $attemptId = '';

    /** @var array<string,mixed>|null */
    public ?array $order = null;

    /** @var list<array<string,mixed>> */
    public array $paymentEvents = [];

    /** @var list<array<string,mixed>> */
    public array $benefitGrants = [];

    /** @var array<string,mixed>|null */
    public ?array $attempt = null;

    public function search(): void
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $orderNo = trim($this->orderNo);
        $email = trim($this->email);
        $attemptId = trim($this->attemptId);

        $orderQuery = DB::table('orders')->where('org_id', $orgId);

        if ($orderNo !== '') {
            $orderQuery->where('order_no', $orderNo);
        } elseif ($attemptId !== '') {
            $orderQuery->where('target_attempt_id', $attemptId);
        } elseif ($email !== '' && \App\Support\SchemaBaseline::hasTable('users')) {
            $userIds = DB::table('users')
                ->where('email', 'like', '%'.$email.'%')
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            if (count($userIds) === 0) {
                $this->resetResults();

                return;
            }

            $orderQuery->whereIn('user_id', $userIds);
        } else {
            $this->resetResults();

            return;
        }

        $order = $orderQuery->orderByDesc('created_at')->first();
        if (! $order) {
            $this->resetResults();

            return;
        }

        $this->order = (array) $order;

        $resolvedOrderNo = (string) ($order->order_no ?? '');

        $this->paymentEvents = DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('order_no', $resolvedOrderNo)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        $this->benefitGrants = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where(function ($query) use ($resolvedOrderNo, $order): void {
                $query->where('order_no', $resolvedOrderNo);

                $attemptId = trim((string) ($order->target_attempt_id ?? ''));
                if ($attemptId !== '') {
                    $query->orWhere('attempt_id', $attemptId);
                }
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        $resolvedAttemptId = trim((string) ($order->target_attempt_id ?? ''));
        if ($resolvedAttemptId !== '') {
            $attempt = DB::table('attempts')
                ->where('org_id', $orgId)
                ->where('id', $resolvedAttemptId)
                ->first();
            $this->attempt = $attempt ? (array) $attempt : null;
        } else {
            $this->attempt = null;
        }
    }

    private function resetResults(): void
    {
        $this->order = null;
        $this->paymentEvents = [];
        $this->benefitGrants = [];
        $this->attempt = null;
    }
}
