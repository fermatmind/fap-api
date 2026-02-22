<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CommerceReconcile extends Command
{
    protected $signature = 'commerce:reconcile
        {--date= : Target business date (YYYY-MM-DD)}
        {--org_id=0 : Organization id}
        {--json=0 : Output json summary}';

    protected $description = 'Reconcile paid orders and entitlement grants by day.';

    public function handle(): int
    {
        $tz = 'Asia/Shanghai';
        $orgId = max(0, (int) $this->option('org_id'));
        $date = trim((string) ($this->option('date') ?? ''));
        if ($date === '') {
            $date = Carbon::now($tz)->format('Y-m-d');
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --date, expected YYYY-MM-DD.');

            return self::FAILURE;
        }
        $end = (clone $start)->addDay();

        $paidOrders = DB::table('orders')
            ->select(['order_no', 'status'])
            ->where('org_id', $orgId)
            ->whereNotNull('order_no')
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($q) use ($start, $end): void {
                    $q->whereNotNull('paid_at')
                        ->where('paid_at', '>=', $start)
                        ->where('paid_at', '<', $end);
                })->orWhere(function ($q) use ($start, $end): void {
                    $q->whereNull('paid_at')
                        ->whereIn('status', ['paid', 'fulfilled', 'refunded', 'chargeback'])
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end);
                });
            })
            ->orderBy('order_no')
            ->get();

        $activeGrants = DB::table('benefit_grants')
            ->select(['order_no'])
            ->where('org_id', $orgId)
            ->whereNotNull('order_no')
            ->where('status', 'active')
            ->groupBy('order_no')
            ->pluck('order_no')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();
        $activeGrantSet = array_fill_keys($activeGrants, true);

        $paidOrderSet = [];
        $paidCount = 0;
        $unlockedCount = 0;
        $mismatches = [];

        foreach ($paidOrders as $order) {
            $orderNo = trim((string) ($order->order_no ?? ''));
            if ($orderNo === '') {
                continue;
            }
            $status = strtolower(trim((string) ($order->status ?? '')));
            $paidOrderSet[$orderNo] = true;
            $paidCount++;

            $hasActiveGrant = isset($activeGrantSet[$orderNo]);
            if ($hasActiveGrant) {
                $unlockedCount++;
                continue;
            }

            if (in_array($status, ['refunded', 'chargeback'], true)) {
                continue;
            }

            $mismatches[] = [
                'order_no' => $orderNo,
                'reason' => 'PAID_WITHOUT_ACTIVE_GRANT',
            ];
        }

        $grantsInWindow = DB::table('benefit_grants')
            ->select(['order_no'])
            ->where('org_id', $orgId)
            ->whereNotNull('order_no')
            ->where('status', 'active')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('order_no')
            ->pluck('order_no')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        foreach ($grantsInWindow as $orderNo) {
            if (isset($paidOrderSet[$orderNo])) {
                continue;
            }
            $mismatches[] = [
                'order_no' => $orderNo,
                'reason' => 'ACTIVE_GRANT_WITHOUT_PAID_ORDER',
            ];
        }

        $result = [
            'ok' => true,
            'date' => $date,
            'timezone' => $tz,
            'org_id' => $orgId,
            'window' => [
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
            ],
            'paid_count' => $paidCount,
            'unlocked_count' => $unlockedCount,
            'mismatch_count' => count($mismatches),
            'mismatches' => $mismatches,
        ];

        DB::table('payment_reconcile_snapshots')->insert([
            'org_id' => $orgId,
            'snapshot_date' => $date,
            'paid_orders_count' => $paidCount,
            'paid_without_benefit_count' => count(array_filter(
                $mismatches,
                static fn (array $row): bool => ($row['reason'] ?? '') === 'PAID_WITHOUT_ACTIVE_GRANT'
            )),
            'benefit_without_report_count' => count(array_filter(
                $mismatches,
                static fn (array $row): bool => ($row['reason'] ?? '') === 'ACTIVE_GRANT_WITHOUT_PAID_ORDER'
            )),
            'webhook_replay_count' => 0,
            'meta_json' => json_encode([
                'unlocked_count' => $unlockedCount,
                'mismatches' => $mismatches,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Commerce reconcile summary');
            $this->line('date='.$date.' org_id='.(string) $orgId);
            $this->line('paid_count='.(string) $paidCount);
            $this->line('unlocked_count='.(string) $unlockedCount);
            $this->line('mismatch_count='.(string) count($mismatches));
        }

        return self::SUCCESS;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

