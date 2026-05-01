<?php

declare(strict_types=1);

namespace App\Services\Commerce\Repair;

use App\Models\Order;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\SkuCatalog;
use Illuminate\Support\Facades\DB;

final class OrderRepairService
{
    private const BLOCKING_REJECT_CODES = [
        'ATTEMPT_OWNER_MISMATCH',
        'ATTEMPT_SCALE_MISMATCH',
    ];

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly OrderManager $orders,
        private readonly SkuCatalog $skus,
    ) {}

    /**
     * @param  Order|object  $order
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function repairPaidOrder(object $order, array $context = []): array
    {
        $freshOrder = $this->reloadOrder($order);
        if ($freshOrder === null) {
            return $this->failure('ORDER_NOT_FOUND', 'order not found.', $order, $context);
        }

        $paymentState = strtolower(trim((string) ($freshOrder->payment_state ?? '')));
        if ($paymentState !== Order::PAYMENT_STATE_PAID) {
            return $this->skip('PAYMENT_NOT_PAID', 'order payment_state is not paid.', $freshOrder, $context);
        }

        $skuRow = $this->resolveSkuRow($freshOrder);
        if ($skuRow === null) {
            return $this->failure('SKU_NOT_FOUND', 'sku not found for paid-order repair.', $freshOrder, $context);
        }

        $kind = strtolower(trim((string) ($skuRow->kind ?? '')));
        if ($kind !== 'report_unlock') {
            return $this->skip('NON_UNLOCK_SKU', 'paid order does not require report unlock repair.', $freshOrder, $context, [
                'sku' => (string) ($skuRow->sku ?? ''),
                'kind' => $kind,
            ]);
        }

        $attemptId = trim((string) ($freshOrder->target_attempt_id ?? ''));
        if ($attemptId === '') {
            return $this->failure('ATTEMPT_REQUIRED', 'target attempt is required for paid-order repair.', $freshOrder, $context);
        }

        $benefitCode = strtoupper(trim((string) ($skuRow->benefit_code ?? '')));
        if ($benefitCode === '') {
            return $this->failure('BENEFIT_CODE_NOT_FOUND', 'benefit code cannot be resolved.', $freshOrder, $context, [
                'sku' => (string) ($skuRow->sku ?? ''),
            ]);
        }

        $attemptMeta = $this->resolveAttemptMeta((int) $freshOrder->org_id, $attemptId);
        $ownerGuard = $this->validateAttemptOwnershipForOrder($freshOrder, $attemptMeta);
        if (! ($ownerGuard['ok'] ?? false)) {
            return $this->failure(
                (string) ($ownerGuard['error'] ?? 'ATTEMPT_OWNER_MISMATCH'),
                (string) ($ownerGuard['message'] ?? 'order owner mismatch.'),
                $freshOrder,
                $context,
                [
                    'benefit_code' => $benefitCode,
                    'attempt_id' => $attemptId,
                ]
            );
        }

        $scaleGuard = $this->validateAttemptScaleForSku($skuRow, $attemptMeta);
        if (! ($scaleGuard['ok'] ?? false)) {
            return $this->failure(
                (string) ($scaleGuard['error'] ?? 'ATTEMPT_SCALE_MISMATCH'),
                (string) ($scaleGuard['message'] ?? 'attempt scale does not match sku scale.'),
                $freshOrder,
                $context,
                [
                    'benefit_code' => $benefitCode,
                    'attempt_id' => $attemptId,
                ]
            );
        }

        $blockingEvent = $this->resolveBlockingSemanticRejectEvent($freshOrder);
        if ($blockingEvent !== null) {
            return $this->skip(
                'BLOCKED_BY_SEMANTIC_REJECT',
                'paid-order repair is blocked by unresolved semantic reject event.',
                $freshOrder,
                $context,
                [
                    'blocking_payment_event_id' => (string) ($blockingEvent->id ?? ''),
                    'blocking_last_error_code' => (string) ($blockingEvent->last_error_code ?? ''),
                    'blocking_handle_status' => (string) ($blockingEvent->handle_status ?? ''),
                ]
            );
        }

        $activeGrant = $this->resolveActiveGrantForOrder($freshOrder, $benefitCode);
        if ($activeGrant !== null) {
            $this->orders->syncGrantState((string) $freshOrder->order_no, (int) $freshOrder->org_id, Order::GRANT_STATE_GRANTED);
            $transition = $this->ensureFulfilled($freshOrder);
            if (! ($transition['ok'] ?? false)) {
                return $this->failure(
                    (string) ($transition['error'] ?? 'FULFILL_TRANSITION_FAILED'),
                    (string) ($transition['message'] ?? 'failed to sync fulfilled lifecycle.'),
                    $freshOrder,
                    $context,
                    [
                        'grant_id' => (string) ($activeGrant->id ?? ''),
                        'benefit_code' => $benefitCode,
                    ]
                );
            }

            $this->writeAudit('commerce_order_repair_skipped', $freshOrder, $context, [
                'reason_code' => 'active_grant_exists',
                'grant_id' => (string) ($activeGrant->id ?? ''),
                'benefit_code' => $benefitCode,
                'attempt_id' => $attemptId,
                'idempotent' => true,
            ], 'success');

            return [
                'ok' => true,
                'skipped' => true,
                'repaired' => false,
                'idempotent' => true,
                'reason' => 'active_grant_exists',
                'order_no' => (string) $freshOrder->order_no,
                'grant_id' => (string) ($activeGrant->id ?? ''),
            ];
        }

        [$scopeOverride, $expiresAt, $modulesIncluded] = $this->resolveGrantOptions($skuRow);
        $grant = $this->entitlements->grantAttemptUnlock(
            (int) $freshOrder->org_id,
            $freshOrder->user_id ? (string) $freshOrder->user_id : null,
            $freshOrder->anon_id ? (string) $freshOrder->anon_id : null,
            $benefitCode,
            $attemptId,
            (string) $freshOrder->order_no,
            $scopeOverride,
            $expiresAt,
            $modulesIncluded
        );

        if (! ($grant['ok'] ?? false)) {
            $this->orders->syncGrantState((string) $freshOrder->order_no, (int) $freshOrder->org_id, Order::GRANT_STATE_GRANT_FAILED);

            return $this->failure(
                (string) ($grant['error'] ?? 'GRANT_FAILED'),
                (string) ($grant['message'] ?? 'grant repair failed.'),
                $freshOrder,
                $context,
                [
                    'benefit_code' => $benefitCode,
                    'attempt_id' => $attemptId,
                ]
            );
        }

        $transition = $this->ensureFulfilled($freshOrder);
        if (! ($transition['ok'] ?? false)) {
            return $this->failure(
                (string) ($transition['error'] ?? 'FULFILL_TRANSITION_FAILED'),
                (string) ($transition['message'] ?? 'failed to transition repaired order to fulfilled.'),
                $freshOrder,
                $context,
                [
                    'benefit_code' => $benefitCode,
                    'attempt_id' => $attemptId,
                    'grant_id' => is_object($grant['grant'] ?? null) ? (string) (($grant['grant'])->id ?? '') : null,
                ]
            );
        }

        $grantRecord = $grant['grant'] ?? null;
        $this->writeAudit('commerce_order_repair_repaired', $freshOrder, $context, [
            'benefit_code' => $benefitCode,
            'attempt_id' => $attemptId,
            'grant_id' => is_object($grantRecord) ? (string) ($grantRecord->id ?? '') : null,
            'idempotent' => (bool) ($grant['idempotent'] ?? false),
        ], 'success');

        return [
            'ok' => true,
            'skipped' => false,
            'repaired' => true,
            'idempotent' => (bool) ($grant['idempotent'] ?? false),
            'order_no' => (string) $freshOrder->order_no,
            'grant_id' => is_object($grantRecord) ? (string) ($grantRecord->id ?? '') : null,
            'benefit_code' => $benefitCode,
        ];
    }

    /**
     * @param  Order|object  $order
     */
    public function hasActiveGrantForOrder(object $order): bool
    {
        return $this->resolveActiveGrantForOrder($order, $this->resolveBenefitCode($order)) !== null;
    }

    /**
     * @param  Order|object  $order
     */
    public function resolveActiveGrantForOrder(object $order, ?string $benefitCode = null): ?object
    {
        $orgId = (int) ($order->org_id ?? 0);
        $orderNo = trim((string) ($order->order_no ?? ''));
        $attemptId = trim((string) ($order->target_attempt_id ?? ''));
        $benefitCode = strtoupper(trim((string) ($benefitCode ?? '')));

        if ($orgId < 0 || ($orderNo === '' && $attemptId === '')) {
            return null;
        }

        $query = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('status', 'active')
            ->where(function ($query) use ($orderNo, $attemptId): void {
                $applied = false;
                if ($orderNo !== '') {
                    $query->where('order_no', $orderNo);
                    $applied = true;
                }
                if ($attemptId !== '') {
                    if ($applied) {
                        $query->orWhere('attempt_id', $attemptId);
                    } else {
                        $query->where('attempt_id', $attemptId);
                    }
                }
            })
            ->orderByDesc('created_at');

        if ($benefitCode !== '') {
            $query->where('benefit_code', $benefitCode);
        }

        return $query->first();
    }

    /**
     * @param  Order|object  $order
     */
    public function requiresPaidOrderRepair(object $order): bool
    {
        $freshOrder = $this->reloadOrder($order);
        if ($freshOrder === null) {
            return false;
        }

        if (! $this->isPaidReportUnlockOrder($freshOrder)) {
            return false;
        }

        if ($this->resolveBlockingSemanticRejectEvent($freshOrder) !== null) {
            return false;
        }

        return ! $this->hasActiveGrantForOrder($freshOrder);
    }

    /**
     * @param  Order|object  $order
     */
    public function isPaidReportUnlockOrder(object $order): bool
    {
        $freshOrder = $this->reloadOrder($order);
        if ($freshOrder === null) {
            return false;
        }

        if (strtolower(trim((string) ($freshOrder->payment_state ?? ''))) !== Order::PAYMENT_STATE_PAID) {
            return false;
        }

        $skuRow = $this->resolveSkuRow($freshOrder);
        if ($skuRow === null) {
            return false;
        }

        return strtolower(trim((string) ($skuRow->kind ?? ''))) === 'report_unlock';
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     * @return array{ok:bool,error?:string,message?:string}
     */
    private function validateAttemptOwnershipForOrder(object $order, array $attemptMeta): array
    {
        $attemptId = trim((string) ($attemptMeta['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'target attempt not found.',
            ];
        }

        $orderUserId = trim((string) ($order->user_id ?? ''));
        $orderAnonId = trim((string) ($order->anon_id ?? ''));
        $attemptUserId = trim((string) ($attemptMeta['user_id'] ?? ''));
        $attemptAnonId = trim((string) ($attemptMeta['anon_id'] ?? ''));

        if ($orderUserId !== '') {
            if ($attemptUserId !== '' && $attemptUserId === $orderUserId) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'error' => 'ATTEMPT_OWNER_MISMATCH',
                'message' => 'attempt owner user_id does not match order user_id.',
            ];
        }

        if ($orderAnonId !== '') {
            if ($attemptAnonId !== '' && $attemptAnonId === $orderAnonId) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'error' => 'ATTEMPT_OWNER_MISMATCH',
                'message' => 'attempt owner anon_id does not match order anon_id.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     * @return array{ok:bool,error?:string,message?:string}
     */
    private function validateAttemptScaleForSku(object $skuRow, array $attemptMeta): array
    {
        $skuScaleCode = strtoupper(trim((string) ($skuRow->scale_code ?? '')));
        $attemptScaleCode = strtoupper(trim((string) ($attemptMeta['scale_code'] ?? '')));

        if ($attemptScaleCode === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'target attempt not found.',
            ];
        }

        if ($skuScaleCode === '' || $skuScaleCode === $attemptScaleCode) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'error' => 'ATTEMPT_SCALE_MISMATCH',
            'message' => 'attempt scale_code does not match sku scale_code.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveAttemptMeta(int $orgId, string $attemptId): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return $this->emptyAttemptMeta();
        }

        $row = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (! $row) {
            return $this->emptyAttemptMeta();
        }

        return [
            'attempt_id' => (string) ($row->id ?? $attemptId),
            'scale_code' => (string) ($row->scale_code ?? ''),
            'user_id' => isset($row->user_id) ? (string) ($row->user_id ?? '') : null,
            'anon_id' => isset($row->anon_id) ? (string) ($row->anon_id ?? '') : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyAttemptMeta(): array
    {
        return [
            'attempt_id' => null,
            'scale_code' => null,
            'user_id' => null,
            'anon_id' => null,
        ];
    }

    /**
     * @param  Order|object  $order
     */
    private function reloadOrder(object $order): ?object
    {
        $orgId = (int) ($order->org_id ?? 0);
        $orderNo = trim((string) ($order->order_no ?? ''));

        if ($orderNo === '') {
            $id = trim((string) ($order->id ?? ''));
            if ($id === '') {
                return null;
            }

            return DB::table('orders')
                ->where('id', $id)
                ->when($orgId > 0 || isset($order->org_id), fn ($query) => $query->where('org_id', $orgId))
                ->first();
        }

        return DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->first();
    }

    private function resolveSkuRow(object $order): ?object
    {
        $sku = strtoupper(trim((string) (
            $order->effective_sku
            ?? $order->sku
            ?? $order->item_sku
            ?? ''
        )));

        if ($sku === '') {
            return null;
        }

        return $this->skus->getActiveSku($sku, null, (int) ($order->org_id ?? 0));
    }

    private function resolveBenefitCode(object $order): ?string
    {
        $skuRow = $this->resolveSkuRow($order);
        if ($skuRow === null) {
            return null;
        }

        $benefitCode = strtoupper(trim((string) ($skuRow->benefit_code ?? '')));

        return $benefitCode !== '' ? $benefitCode : null;
    }

    private function resolveBlockingSemanticRejectEvent(object $order): ?object
    {
        $orderNo = trim((string) ($order->order_no ?? ''));
        if ($orderNo === '') {
            return null;
        }

        return DB::table('payment_events')
            ->where('order_no', $orderNo)
            ->where('status', 'rejected')
            ->whereIn('last_error_code', self::BLOCKING_REJECT_CODES)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array{0:?string,1:?string,2:?array}
     */
    private function resolveGrantOptions(object $skuRow): array
    {
        $scopeOverride = trim((string) ($skuRow->scope ?? ''));
        if ($scopeOverride === '') {
            $scopeOverride = 'attempt';
        }

        $expiresAt = null;
        $modulesIncluded = null;
        $skuMeta = is_string($skuRow->meta_json ?? null)
            ? json_decode((string) $skuRow->meta_json, true)
            : (is_array($skuRow->meta_json ?? null) ? $skuRow->meta_json : []);

        if (is_array($skuMeta)) {
            $modules = $skuMeta['modules_included'] ?? null;
            if (is_array($modules)) {
                $modulesIncluded = array_values(array_filter(array_map(
                    static fn (mixed $module): string => trim((string) $module),
                    $modules
                )));
            }

            $durationDays = isset($skuMeta['duration_days']) ? (int) $skuMeta['duration_days'] : 0;
            if ($durationDays > 0) {
                $expiresAt = now()->addDays($durationDays)->toISOString();
            }
        }

        return [$scopeOverride, $expiresAt, $modulesIncluded];
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureFulfilled(object $order): array
    {
        $status = strtolower(trim((string) ($order->status ?? '')));
        if ($status === Order::STATUS_FULFILLED) {
            return ['ok' => true, 'skipped' => true];
        }

        return $this->orders->transition(
            (string) ($order->order_no ?? ''),
            Order::STATUS_FULFILLED,
            (int) ($order->org_id ?? 0)
        );
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function skip(string $reasonCode, string $message, object $order, array $context, array $extra = []): array
    {
        $this->writeAudit('commerce_order_repair_skipped', $order, $context, array_merge([
            'reason_code' => $reasonCode,
            'message' => $message,
        ], $extra), 'success');

        return [
            'ok' => true,
            'skipped' => true,
            'repaired' => false,
            'reason' => strtolower($reasonCode),
            'message' => $message,
            'order_no' => (string) ($order->order_no ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function failure(string $errorCode, string $message, object $order, array $context, array $extra = []): array
    {
        $this->writeAudit('commerce_order_repair_failed', $order, $context, array_merge([
            'error_code' => $errorCode,
            'message' => $message,
        ], $extra), 'failed');

        return [
            'ok' => false,
            'error' => $errorCode,
            'message' => $message,
            'order_no' => (string) ($order->order_no ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $extra
     */
    private function writeAudit(string $action, object $order, array $context, array $extra, string $result): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => (int) ($order->org_id ?? 0),
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => 'Order',
            'target_id' => (string) ($order->id ?? ''),
            'meta_json' => json_encode(array_merge([
                'actor' => 'system',
                'order_no' => (string) ($order->order_no ?? ''),
                'payment_state' => (string) ($order->payment_state ?? ''),
                'grant_state' => (string) ($order->grant_state ?? ''),
                'source' => (string) ($context['source'] ?? 'order_repair'),
                'reason' => (string) ($context['reason'] ?? ''),
                'correlation_id' => (string) ($context['correlation_id'] ?? ''),
                'payment_event_id' => $context['payment_event_id'] ?? null,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'system:commerce-repair',
            'request_id' => '',
            'reason' => trim((string) ($context['reason'] ?? 'commerce_order_repair')) ?: 'commerce_order_repair',
            'result' => $result,
            'created_at' => now(),
        ]);
    }
}
