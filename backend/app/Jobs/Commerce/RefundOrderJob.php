<?php

declare(strict_types=1);

namespace App\Jobs\Commerce;

use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RefundOrderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $orgId,
        public string $orderNo,
        public string $reason,
        public string $correlationId,
    ) {
        $this->onConnection('database');
        $this->onQueue('commerce');
    }

    public function handle(OrderManager $orders, EntitlementManager $entitlements): void
    {
        $orderNo = trim($this->orderNo);
        if ($orderNo === '') {
            return;
        }

        $order = DB::table('orders')
            ->where('org_id', $this->orgId)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return;
        }

        DB::transaction(function () use ($orders, $entitlements, $order, $orderNo) {
            $transition = $orders->transition($orderNo, 'refunded', $this->orgId);
            if (! ($transition['ok'] ?? false)) {
                throw new \RuntimeException((string) ($transition['message'] ?? 'refund transition failed.'));
            }

            DB::table('orders')
                ->where('org_id', $this->orgId)
                ->where('order_no', $orderNo)
                ->update([
                    'amount_refunded' => (int) ($order->amount_cents ?? 0),
                    'refunded_at' => now(),
                    'updated_at' => now(),
                ]);

            $revoked = $entitlements->revokeByOrderNo($this->orgId, $orderNo);

            DB::table('audit_logs')->insert([
                'org_id' => $this->orgId,
                'actor_admin_id' => null,
                'action' => 'refund_order_executed',
                'target_type' => 'Order',
                'target_id' => (string) ($order->id ?? ''),
                'meta_json' => json_encode([
                    'actor' => 'system',
                    'org_id' => $this->orgId,
                    'order_no' => $orderNo,
                    'reason' => $this->reason,
                    'correlation_id' => $this->correlationId,
                    'revoked' => (int) ($revoked['revoked'] ?? 0),
                ], JSON_UNESCAPED_UNICODE),
                'ip' => null,
                'user_agent' => 'queue:commerce',
                'request_id' => '',
                'created_at' => now(),
            ]);
        });
    }
}
