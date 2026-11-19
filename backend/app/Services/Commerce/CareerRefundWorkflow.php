<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Actions\Commerce\ActionResult;
use App\Jobs\ExecuteApprovalJob;
use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Models\CareerRefundRequest;
use App\Models\Order;
use App\Services\Approvals\HighRiskApprovalService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @review-surface refund_approval */
final class CareerRefundWorkflow
{
    public static function canOperate(?AdminUser $actor): bool
    {
        return $actor && $actor->is_active && config('review_governance.mode') === 'solo_owner'
            && (int) $actor->id === (int) config('review_governance.solo_owner_admin_user_id')
            && $actor->hasPermission(PermissionNames::ADMIN_APPROVAL_REVIEW)
            && $actor->hasPermission(PermissionNames::ADMIN_FINANCE_WRITE)
            && $actor->hasPermission(PermissionNames::ADMIN_OPS_WRITE)
            && $actor->hasPermission(PermissionNames::ADMIN_OPS_READ);
    }

    public function ownedOrder(string $number, string $owner): object
    {
        $order = CareerAssessmentPurchase::scopeOwner(DB::table('orders')->where('org_id', 0)->where('order_no', $number), $owner)
            ->whereIn('sku', CareerAssessmentPurchase::SKUS)->first();
        abort_unless($order, 404);

        return $order;
    }

    public function forOwner(string $number, string $owner): array
    {
        $order = $this->ownedOrder($number, $owner);
        $row = CareerRefundRequest::where('org_id', 0)->where('order_no', $number)->first();
        $state = Order::normalizePaymentState($order->payment_state, $order->status);
        $paid = $state === 'paid' && (int) $order->amount_refunded === 0;

        return [
            'order_no' => $number, 'provider' => $order->provider, 'currency' => $order->currency,
            'amount_cents' => (int) $order->amount_cents, 'amount_refunded' => (int) $order->amount_refunded,
            'payment_state' => $state,
            'can_request' => $paid && (! $row || $row->status === 'withdrawn'),
            'request' => $row ? [
                'id' => $row->id,
                'status' => $state === 'refunded' ? 'succeeded' : $row->status,
                'reason_code' => $row->reason_code, 'detail' => $row->detail,
                'decision_note' => $row->decision_note, 'created_at' => $row->created_at->toIso8601String(),
                'resolved_at' => $row->resolved_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function request(string $number, string $owner, array $data): void
    {
        DB::transaction(function () use ($number, $owner, $data): void {
            $order = DB::table('orders')->where('org_id', 0)->where('order_no', $number)->lockForUpdate()->first();
            $this->ownedOrder($number, $owner);
            abort_unless(Order::normalizePaymentState($order->payment_state, $order->status) === 'paid'
                && (int) $order->amount_refunded === 0, 409, 'Order is not refundable.');
            $row = CareerRefundRequest::where('org_id', 0)->where('order_no', $number)->first();
            if ($row && $row->status !== 'withdrawn') {
                return;
            }
            $row ??= new CareerRefundRequest;
            $row->fill([
                'org_id' => 0, 'order_no' => $number, 'owner_key' => $owner, 'provider' => $order->provider,
                'currency' => $order->currency, 'amount_cents' => (int) $order->amount_cents,
                'reason_code' => $data['reason_code'], 'detail' => $data['detail'] ?? null,
                'status' => 'pending', 'decision_note' => null,
            ])->save();
        });
    }

    public function withdraw(string $number, string $owner): void
    {
        $this->ownedOrder($number, $owner);
        DB::transaction(function () use ($number): void {
            $row = CareerRefundRequest::where('org_id', 0)->where('order_no', $number)->lockForUpdate()->first();
            abort_unless($row && in_array($row->status, ['pending', 'withdrawn'], true), 409);
            $row->update(['status' => 'withdrawn']);
        });
    }

    public function review(array $ids, AdminUser $actor, string $code, string $decision, string $note = ''): string
    {
        abort_unless(self::canOperate($actor), 403);
        abort_unless(count($ids) > 0 && count($ids) <= 50 && count($ids) === count(array_unique($ids)), 422);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        abort_unless($decision !== 'reject' || (trim($note) !== '' && mb_strlen($note) <= 255), 422);
        $note = $decision === 'reject' ? trim($note) : '';
        $batchId = (string) Str::uuid();
        $this->publicContext(function () use ($ids, $actor, $code, $decision, $note, $batchId): void {
            DB::transaction(function () use ($ids, $actor, $code, $decision, $note, $batchId): void {
                $rows = CareerRefundRequest::where('org_id', 0)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                abort_unless($rows->count() === count($ids) && $rows->every(fn ($r) => $r->status === 'pending' || ($decision === 'approve' && $r->status === 'attention' && $r->submitted_at === null)), 409, 'Selection changed. Refresh and select pending requests.');
                $approvalIds = [];
                foreach ($rows as $row) {
                    $order = $this->ownedOrder($row->order_no, $row->owner_key);
                    abort_unless(Order::normalizePaymentState($order->payment_state, $order->status) === 'paid'
                        && (int) $order->amount_refunded === 0 && (int) $order->amount_cents === $row->amount_cents
                        && $order->currency === $row->currency && $order->provider === $row->provider, 409, 'Order changed. Refresh the selection.');
                    $approval = AdminApproval::create([
                        'org_id' => 0, 'type' => AdminApproval::TYPE_REFUND, 'status' => AdminApproval::STATUS_PENDING,
                        'requested_by_admin_user_id' => $actor->id,
                        'reason' => 'Customer refund request '.$row->id,
                        'correlation_id' => (string) Str::uuid(),
                        'payload_json' => ['order_no' => $row->order_no, 'refund_request_id' => $row->id,
                            'amount_cents' => $row->amount_cents, 'currency' => $row->currency, 'batch_id' => $batchId],
                    ]);
                    $approvalIds[] = $approval->id;
                    $row->update(['approval_id' => $approval->id, 'batch_id' => $batchId,
                        'decision_note' => $decision === 'reject' ? trim($note) : null]);
                }
                $governance = app(HighRiskApprovalService::class);
                // One fresh verification, then exact-target per-order approval and execution evidence.
                $governance->reviewRefundBatch($approvalIds, (int) $actor->id, $code, $decision, $note);
                foreach ($rows as $row) {
                    if ($decision === 'reject') {
                        $row->update(['status' => 'rejected', 'resolved_at' => now()]);
                    } else {
                        $row->update(['status' => 'approved']);
                        ExecuteApprovalJob::dispatch($row->fresh()->approval_id)->afterCommit();
                    }
                }
                DB::table('audit_logs')->insert([
                    'org_id' => 0, 'actor_admin_id' => $actor->id, 'action' => 'career_refund_batch_'.$decision,
                    'target_type' => 'CareerRefundBatch', 'target_id' => $batchId,
                    'meta_json' => json_encode(['request_ids' => $ids, 'decision' => $decision, 'note' => $note], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            });
        });

        return $batchId;
    }

    public function queueApproved(AdminApproval $approval): ActionResult
    {
        \App\Jobs\Commerce\ProcessCareerRefundRequestJob::dispatch($approval->id)->afterCommit();

        return ActionResult::success(['refund_queued' => true]);
    }

    public function executeApproved(AdminApproval $approval): ActionResult
    {
        $payload = $approval->payload_json;
        $row = CareerRefundRequest::where('org_id', 0)->whereKey($payload['refund_request_id'] ?? '')->first();
        if (! $row || $row->approval_id !== $approval->id || $row->order_no !== ($payload['order_no'] ?? '')
            || $row->amount_cents !== ($payload['amount_cents'] ?? null) || $row->currency !== ($payload['currency'] ?? '')) {
            return ActionResult::failure('REFUND_TARGET_MISMATCH', 'Refund target changed.');
        }

        return Cache::lock('career-refund:'.$row->id, 180)->block(1, function () use ($row, $approval): ActionResult {
            $row->refresh();
            if ($row->approval_id !== $approval->id) {
                return ActionResult::failure('REFUND_TARGET_CHANGED', 'Refund approval was superseded.');
            }
            if ($row->submitted_at !== null || $row->status === 'succeeded') {
                $this->reconcile($row);

                return ActionResult::success(['refund_request_id' => $row->id, 'reconciled' => true]);
            }
            try {
                $order = $this->ownedOrder($row->order_no, $row->owner_key);
                DB::transaction(function () use ($row, $approval): void {
                    $current = CareerRefundRequest::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->approval_id === $approval->id && $current->status === 'approved' && $current->submitted_at === null, 409);
                    $row->update(['status' => 'processing', 'submitted_at' => now()]);
                });
                app(CareerAssessmentRefund::class)->submit($order, 'Customer requested refund');
                $this->syncLocal($row);

                return ActionResult::success(['refund_request_id' => $row->id, 'submitted' => true]);
            } catch (\Throwable) {
                $row->update(['status' => 'attention', 'error_code' => 'CHANNEL_CONFIRMATION_REQUIRED']);

                return ActionResult::failure('REFUND_CHANNEL_UNCONFIRMED', 'Refund requires channel reconciliation.');
            }
        });
    }

    public function reconcile(CareerRefundRequest $row): void
    {
        if ($this->syncLocal($row)) {
            return;
        }
        if ($row->submitted_at === null) {
            if ($row->status === 'approved' && $row->updated_at->lt(now()->subMinutes(15))) {
                $row->update(['status' => 'attention', 'error_code' => 'EXECUTION_NOT_STARTED']);
            }
            $row->update(['checked_at' => now()]);

            return;
        }
        try {
            $order = $this->ownedOrder($row->order_no, $row->owner_key);
            $service = app(CareerAssessmentRefund::class);
            if ($row->provider === 'alipay') {
                $service->reconcileAlipayRefund($order, 'career94-'.substr(hash('sha256', $order->order_no), 0, 32));
            } else {
                if (! $service->reconcileWaffoRefund($order)) {
                    $delayed = $row->submitted_at->lt(now()->subDays(14));
                    $row->update(['checked_at' => now(), 'status' => $delayed ? 'attention' : 'processing',
                        'error_code' => $delayed ? 'CHANNEL_DELAYED' : null]);

                    return;
                }
            }
            $this->syncLocal($row);
            $row->update(['checked_at' => now()]);
        } catch (\Throwable) {
            $row->update(['checked_at' => now()]);
            if ($row->submitted_at->lt(now()->subDay())) {
                $row->update(['status' => 'attention', 'error_code' => 'CHANNEL_CONFIRMATION_REQUIRED']);
            }
        }
    }

    private function syncLocal(CareerRefundRequest $row): bool
    {
        $order = $this->ownedOrder($row->order_no, $row->owner_key);
        if (Order::normalizePaymentState($order->payment_state, $order->status) !== 'refunded') {
            return false;
        }
        $revocation = app(EntitlementManager::class)->revokeByOrderNo(0, $row->order_no);
        if (! ($revocation['ok'] ?? false)) {
            $row->update(['status' => 'attention', 'error_code' => 'ACCESS_SYNC_REQUIRED', 'checked_at' => now()]);

            return false;
        }
        $row->update(['status' => 'succeeded', 'error_code' => null, 'resolved_at' => $row->resolved_at ?? now(), 'checked_at' => now()]);

        return true;
    }

    private function publicContext(callable $callback): mixed
    {
        $context = app(OrgContext::class);
        $old = [$context->orgId(), $context->userId(), $context->role(), $context->anonId(), $context->contextKind()];
        $request = request();
        $attributes = $request->attributes->all();
        $context->set(0, null, 'public', null, OrgContext::KIND_PUBLIC);
        $request->attributes->add(['org_context_resolved' => true, 'org_context_kind' => 'public', 'fm_org_id' => 0, 'org_id' => 0]);
        try {
            return $callback();
        } finally {
            $context->set(...$old);
            $request->attributes->replace($attributes);
        }
    }
}
