<?php

declare(strict_types=1);

namespace App\Jobs\Commerce;

use App\Services\Commerce\PaymentWebhookProcessor;
use App\Services\Commerce\Repair\OrderRepairService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ReprocessPaymentEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [5, 10, 20];

    public function __construct(
        public string $paymentEventId,
        public int $orgId,
        public string $reason,
        public string $correlationId,
    ) {
        $this->onConnection('database');
        $this->onQueue('commerce');
    }

    public function handle(PaymentWebhookProcessor $processor, OrderRepairService $orderRepair): void
    {
        $event = DB::table('payment_events')
            ->where('id', $this->paymentEventId)
            ->first();

        if (! $event) {
            return;
        }

        $effectiveOrgId = $this->resolveEffectiveOrgId($event);

        $provider = strtolower(trim((string) ($event->provider ?? '')));
        $payload = $this->decodePayload($event->payload_json ?? null);
        if ($provider === '' || $payload === []) {
            $this->markFailed($event, $effectiveOrgId, 'INVALID_EVENT_PAYLOAD', 'provider/payload missing');

            return;
        }

        $signatureOk = (bool) ($event->signature_ok ?? false);
        $originalStatus = strtolower(trim((string) ($event->status ?? '')));
        $result = $processor->process($provider, $payload, $signatureOk);

        if (($result['ok'] ?? false) === true) {
            $repair = $this->repairOrderIfNeeded($event, $orderRepair, $effectiveOrgId);
            if (($repair['ok'] ?? true) !== true) {
                $this->markFailed(
                    $event,
                    $effectiveOrgId,
                    (string) ($repair['error'] ?? 'ORDER_REPAIR_FAILED'),
                    (string) ($repair['message'] ?? 'order repair failed after payment-event reprocess.'),
                    $this->retryableStatus($originalStatus)
                );

                return;
            }

            DB::table('payment_events')
                ->where('id', $this->paymentEventId)
                ->update([
                    'org_id' => $effectiveOrgId,
                    'status' => 'processed',
                    'handle_status' => ($result['duplicate'] ?? false) ? 'duplicate' : 'reprocessed',
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'processed_at' => now(),
                    'handled_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->writeAudit(
                orgId: $effectiveOrgId,
                action: 'reprocess_payment_event_executed',
                targetType: 'PaymentEvent',
                targetId: (string) $event->id,
                orderNo: (string) ($event->order_no ?? ''),
                meta: [
                    'provider' => $provider,
                    'provider_event_id' => $event->provider_event_id ?? null,
                    'reason' => $this->reason,
                    'correlation_id' => $this->correlationId,
                    'duplicate' => (bool) ($result['duplicate'] ?? false),
                    'order_repair' => $repair,
                ],
            );

            return;
        }

        $errorCode = (string) ($result['error_code'] ?? $result['error'] ?? 'REPROCESS_FAILED');
        $message = (string) ($result['message'] ?? 'reprocess failed.');

        $this->markFailed($event, $effectiveOrgId, $errorCode, $message, $this->retryableStatus($originalStatus));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function markFailed(object $event, int $orgId, string $errorCode, string $message, ?string $preserveStatus = null): void
    {
        DB::table('payment_events')
            ->where('id', (string) $event->id)
            ->update([
                'org_id' => $orgId,
                'status' => $preserveStatus ?? 'failed',
                'handle_status' => 'reprocess_failed',
                'last_error_code' => $errorCode,
                'last_error_message' => mb_substr($message, 0, 255),
                'handled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->writeAudit(
            orgId: $orgId,
            action: 'reprocess_payment_event_failed',
            targetType: 'PaymentEvent',
            targetId: (string) $event->id,
            orderNo: (string) ($event->order_no ?? ''),
            meta: [
                'provider' => $event->provider ?? null,
                'provider_event_id' => $event->provider_event_id ?? null,
                'reason' => $this->reason,
                'correlation_id' => $this->correlationId,
                'error_code' => $errorCode,
                'error_message' => mb_substr($message, 0, 255),
            ],
        );
    }

    private function writeAudit(int $orgId, string $action, string $targetType, string $targetId, string $orderNo, array $meta): void
    {
        $reason = trim((string) ($meta['reason'] ?? 'reprocess_payment_event'));
        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode(array_merge([
                'actor' => 'system',
                'org_id' => $orgId,
                'order_no' => $orderNo,
                'correlation_id' => $this->correlationId,
            ], $meta), JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'user_agent' => 'queue:commerce',
            'request_id' => '',
            'reason' => $reason,
            'result' => str_contains($action, 'failed') ? 'failed' : 'success',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function repairOrderIfNeeded(object $event, OrderRepairService $orderRepair, int $orgId): array
    {
        $orderNo = trim((string) ($event->order_no ?? ''));
        if ($orderNo === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'missing_order_no'];
        }

        $order = DB::table('orders')
            ->where('org_id', $orgId)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'order_not_found'];
        }

        if (! $orderRepair->isPaidReportUnlockOrder($order)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'order_repair_not_required'];
        }

        return $orderRepair->repairPaidOrder($order, [
            'source' => 'reprocess_payment_event_job',
            'reason' => $this->reason,
            'correlation_id' => $this->correlationId,
            'payment_event_id' => (string) ($event->id ?? ''),
        ]);
    }

    private function resolveEffectiveOrgId(object $event): int
    {
        $orderNo = trim((string) ($event->order_no ?? ''));
        if ($orderNo !== '') {
            $resolvedOrgId = DB::table('orders')
                ->where('order_no', $orderNo)
                ->value('org_id');

            if ($resolvedOrgId !== null && (int) $resolvedOrgId > 0) {
                return (int) $resolvedOrgId;
            }
        }

        $eventOrgId = (int) ($event->org_id ?? 0);
        if ($eventOrgId > 0) {
            return $eventOrgId;
        }

        return $this->orgId;
    }

    private function retryableStatus(string $status): ?string
    {
        return in_array($status, ['post_commit_failed', 'rejected', 'orphan'], true)
            ? $status
            : null;
    }
}
