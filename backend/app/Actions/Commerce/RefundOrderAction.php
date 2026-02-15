<?php

declare(strict_types=1);

namespace App\Actions\Commerce;

use App\Jobs\Commerce\RefundOrderJob;
use App\Models\AdminUser;
use App\Models\Order;
use App\Support\Rbac\PermissionNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RefundOrderAction
{
    public function execute(
        AdminUser $actor,
        int $orgId,
        string $orderNo,
        string $reason,
        ?string $correlationId = null,
    ): ActionResult {
        $orderNo = trim($orderNo);
        $reason = trim($reason);
        $correlationId = $this->normalizeCorrelationId($correlationId);

        if ($orderNo === '' || $reason === '') {
            return ActionResult::failure('INVALID_ARGUMENT', 'order_no and reason are required.');
        }

        $order = Order::withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return ActionResult::failure('ORDER_NOT_FOUND', 'order not found.');
        }

        if (! $this->authorize($actor, $order)) {
            return ActionResult::failure('FORBIDDEN', 'permission denied.');
        }

        if (strtolower((string) $order->status) === 'refunded') {
            return ActionResult::success([
                'order_no' => $orderNo,
                'already_refunded' => true,
                'correlation_id' => $correlationId,
            ]);
        }

        DB::transaction(function () use ($actor, $orgId, $order, $reason, $correlationId, $orderNo) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'updated_at' => now(),
                ]);

            $this->writeAudit(
                orgId: $orgId,
                actorAdminId: (int) $actor->id,
                action: 'refund_order_requested',
                targetType: 'Order',
                targetId: (string) $order->id,
                reason: $reason,
                orderNo: $orderNo,
                correlationId: $correlationId,
            );
        });

        RefundOrderJob::dispatch($orgId, $orderNo, $reason, $correlationId)->afterCommit();

        return ActionResult::success([
            'order_no' => $orderNo,
            'queued' => true,
            'correlation_id' => $correlationId,
        ]);
    }

    private function authorize(AdminUser $actor, Order $order): bool
    {
        if (! $actor->hasPermission(PermissionNames::ADMIN_FINANCE_WRITE)) {
            return false;
        }

        return Gate::forUser($actor)->allows('update', $order);
    }

    private function normalizeCorrelationId(?string $correlationId): string
    {
        $value = trim((string) ($correlationId ?? ''));
        if ($value !== '') {
            return $value;
        }

        return (string) Str::uuid();
    }

    private function writeAudit(
        int $orgId,
        int $actorAdminId,
        string $action,
        string $targetType,
        string $targetId,
        string $reason,
        string $orderNo,
        string $correlationId,
    ): void {
        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => $actorAdminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode([
                'actor' => $actorAdminId,
                'org_id' => $orgId,
                'order_no' => $orderNo,
                'reason' => $reason,
                'correlation_id' => $correlationId,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'user_agent' => (string) (request()?->userAgent() ?? ''),
            'request_id' => (string) (request()?->attributes->get('request_id') ?? ''),
            'created_at' => now(),
        ]);
    }
}
