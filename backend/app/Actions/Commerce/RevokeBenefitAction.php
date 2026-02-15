<?php

declare(strict_types=1);

namespace App\Actions\Commerce;

use App\Models\AdminUser;
use App\Models\Order;
use App\Services\Commerce\EntitlementManager;
use App\Support\Rbac\PermissionNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RevokeBenefitAction
{
    public function __construct(
        private readonly EntitlementManager $entitlements,
    ) {}

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

        $result = DB::transaction(function () use ($actor, $orgId, $order, $orderNo, $reason, $correlationId) {
            $revoked = $this->entitlements->revokeByOrderNo($orgId, $orderNo);
            if (! ($revoked['ok'] ?? false)) {
                return ActionResult::failure(
                    (string) ($revoked['error'] ?? 'REVOKE_FAILED'),
                    (string) ($revoked['message'] ?? 'revoke failed.')
                );
            }

            $this->writeAudit(
                orgId: $orgId,
                actorAdminId: (int) $actor->id,
                action: 'revoke_benefit',
                targetType: 'Order',
                targetId: (string) $order->id,
                reason: $reason,
                orderNo: $orderNo,
                correlationId: $correlationId,
                extra: [
                    'revoked' => (int) ($revoked['revoked'] ?? 0),
                    'benefit_code' => $revoked['benefit_code'] ?? null,
                    'attempt_id' => $revoked['attempt_id'] ?? null,
                ],
            );

            return ActionResult::success([
                'order_no' => $orderNo,
                'revoked' => (int) ($revoked['revoked'] ?? 0),
            ]);
        });

        return $result;
    }

    private function authorize(AdminUser $actor, Order $order): bool
    {
        if (! $actor->hasPermission(PermissionNames::ADMIN_OPS_WRITE)) {
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
        array $extra = [],
    ): void {
        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => $actorAdminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode(array_merge([
                'actor' => $actorAdminId,
                'org_id' => $orgId,
                'order_no' => $orderNo,
                'reason' => $reason,
                'correlation_id' => $correlationId,
            ], $extra), JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'user_agent' => (string) (request()?->userAgent() ?? ''),
            'request_id' => (string) (request()?->attributes->get('request_id') ?? ''),
            'reason' => $reason,
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
