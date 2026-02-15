<?php

declare(strict_types=1);

namespace App\Actions\Commerce;

use App\Jobs\Commerce\ReprocessPaymentEventJob;
use App\Models\AdminUser;
use App\Models\PaymentEvent;
use App\Support\Rbac\PermissionNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ReprocessPaymentEventAction
{
    public function execute(
        AdminUser $actor,
        int $orgId,
        string $paymentEventId,
        string $reason,
        ?string $correlationId = null,
    ): ActionResult {
        $paymentEventId = trim($paymentEventId);
        $reason = trim($reason);
        $correlationId = $this->normalizeCorrelationId($correlationId);

        if ($paymentEventId === '' || $reason === '') {
            return ActionResult::failure('INVALID_ARGUMENT', 'payment_event_id and reason are required.');
        }

        $event = PaymentEvent::withoutGlobalScopes()
            ->where('id', $paymentEventId)
            ->where('org_id', $orgId)
            ->first();

        if (! $event) {
            return ActionResult::failure('EVENT_NOT_FOUND', 'payment event not found.');
        }

        if (! $this->authorize($actor, $event)) {
            return ActionResult::failure('FORBIDDEN', 'permission denied.');
        }

        DB::transaction(function () use ($actor, $orgId, $event, $reason, $correlationId) {
            DB::table('payment_events')
                ->where('id', $event->id)
                ->update([
                    'status' => 'reprocess_requested',
                    'handle_status' => 'queued',
                    'updated_at' => now(),
                ]);

            $this->writeAudit(
                orgId: $orgId,
                actorAdminId: (int) $actor->id,
                action: 'reprocess_payment_event',
                targetType: 'PaymentEvent',
                targetId: (string) $event->id,
                reason: $reason,
                orderNo: (string) ($event->order_no ?? ''),
                correlationId: $correlationId,
                extra: [
                    'provider' => $event->provider,
                    'provider_event_id' => $event->provider_event_id,
                ],
            );
        });

        ReprocessPaymentEventJob::dispatch((string) $event->id, $orgId, $reason, $correlationId)->afterCommit();

        return ActionResult::success([
            'payment_event_id' => (string) $event->id,
            'provider_event_id' => (string) $event->provider_event_id,
            'order_no' => (string) ($event->order_no ?? ''),
            'correlation_id' => $correlationId,
            'queued' => true,
        ]);
    }

    private function authorize(AdminUser $actor, PaymentEvent $event): bool
    {
        if (! $actor->hasPermission(PermissionNames::ADMIN_OPS_WRITE)) {
            return false;
        }

        return Gate::forUser($actor)->allows('update', $event);
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
            'created_at' => now(),
        ]);
    }
}
