<?php

declare(strict_types=1);

namespace App\Jobs\Commerce;

use App\Services\Commerce\PaymentWebhookProcessor;
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

    public function handle(PaymentWebhookProcessor $processor): void
    {
        $event = DB::table('payment_events')
            ->where('id', $this->paymentEventId)
            ->where('org_id', $this->orgId)
            ->first();

        if (! $event) {
            return;
        }

        $provider = strtolower(trim((string) ($event->provider ?? '')));
        $payload = $this->decodePayload($event->payload_json ?? null);
        if ($provider === '' || $payload === []) {
            $this->markFailed($event, 'INVALID_EVENT_PAYLOAD', 'provider/payload missing');

            return;
        }

        $signatureOk = (bool) ($event->signature_ok ?? false);
        $result = $processor->handle(
            $provider,
            $payload,
            $this->orgId,
            null,
            null,
            $signatureOk,
            [],
            (string) ($event->payload_sha256 ?? ''),
            (int) ($event->payload_size_bytes ?? -1),
        );

        if (($result['ok'] ?? false) === true) {
            DB::table('payment_events')
                ->where('id', $this->paymentEventId)
                ->update([
                    'status' => 'processed',
                    'handle_status' => ($result['duplicate'] ?? false) ? 'duplicate' : 'reprocessed',
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'processed_at' => now(),
                    'handled_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->writeAudit(
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
                ],
            );

            return;
        }

        $errorCode = (string) ($result['error_code'] ?? $result['error'] ?? 'REPROCESS_FAILED');
        $message = (string) ($result['message'] ?? 'reprocess failed.');

        $this->markFailed($event, $errorCode, $message);
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

    private function markFailed(object $event, string $errorCode, string $message): void
    {
        DB::table('payment_events')
            ->where('id', (string) $event->id)
            ->update([
                'status' => 'failed',
                'handle_status' => 'reprocess_failed',
                'last_error_code' => $errorCode,
                'last_error_message' => mb_substr($message, 0, 255),
                'handled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->writeAudit(
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

    private function writeAudit(string $action, string $targetType, string $targetId, string $orderNo, array $meta): void
    {
        $reason = trim((string) ($meta['reason'] ?? 'reprocess_payment_event'));
        DB::table('audit_logs')->insert([
            'org_id' => $this->orgId,
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode(array_merge([
                'actor' => 'system',
                'org_id' => $this->orgId,
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
}
