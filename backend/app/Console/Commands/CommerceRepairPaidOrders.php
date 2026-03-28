<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Commerce\Repair\OrderRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CommerceRepairPaidOrders extends Command
{
    protected $signature = 'commerce:repair-paid-orders
        {--org_id=0 : Organization id}
        {--order= : Target order number}
        {--limit=50 : Max orders to scan}
        {--older_than_minutes=5 : Ignore very fresh paid orders}
        {--dry-run=0 : Preview candidates only}
        {--json=0 : Output json summary}';

    protected $description = 'Repair paid orders that are still missing active entitlement grants.';

    public function __construct(
        private readonly OrderRepairService $repair,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orgId = max(0, (int) $this->option('org_id'));
        $limit = max(1, (int) $this->option('limit'));
        $olderThanMinutes = max(0, (int) $this->option('older_than_minutes'));
        $orderNo = trim((string) ($this->option('order') ?? ''));
        $dryRun = $this->isTruthy($this->option('dry-run'));

        $orders = $this->collectOrders($orgId, $orderNo, $limit, $olderThanMinutes);

        $summary = [
            'dry_run' => $dryRun,
            'candidate_count' => 0,
            'repaired_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'results' => [],
        ];

        foreach ($orders as $order) {
            if (! $this->repair->requiresPaidOrderRepair($order)) {
                continue;
            }

            $summary['candidate_count']++;

            if ($dryRun) {
                $summary['results'][] = [
                    'order_no' => (string) ($order->order_no ?? ''),
                    'status' => 'candidate',
                ];

                continue;
            }

            $result = $this->repair->repairPaidOrder($order, [
                'source' => 'commerce:repair-paid-orders',
                'reason' => 'paid_no_grant_repair',
            ]);

            $summary['results'][] = $result;

            if (($result['ok'] ?? false) !== true) {
                $summary['failed_count']++;

                continue;
            }

            if (($result['skipped'] ?? false) === true) {
                $summary['skipped_count']++;

                continue;
            }

            $summary['repaired_count']++;
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array<int,object>
     */
    private function collectOrders(int $orgId, string $orderNo, int $limit, int $olderThanMinutes): array
    {
        $query = DB::table('orders')
            ->where('org_id', $orgId)
            ->where('payment_state', Order::PAYMENT_STATE_PAID);

        if ($orderNo !== '') {
            return $query->where('order_no', $orderNo)->limit(1)->get()->all();
        }

        if ($olderThanMinutes > 0) {
            $cutoff = now()->subMinutes($olderThanMinutes);
            $query->where(function ($staleQuery) use ($cutoff): void {
                $staleQuery->where(function ($paidQuery) use ($cutoff): void {
                    $paidQuery->whereNotNull('paid_at')
                        ->where('paid_at', '<=', $cutoff);
                })->orWhere(function ($updatedQuery) use ($cutoff): void {
                    $updatedQuery->whereNull('paid_at')
                        ->where('updated_at', '<=', $cutoff);
                });
            });
        }

        return $query
            ->orderBy('paid_at')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('Commerce paid-order repair summary');
        $this->line('dry_run='.(string) ($summary['dry_run'] ? '1' : '0'));
        $this->line('candidate_count='.(string) $summary['candidate_count']);
        $this->line('repaired_count='.(string) $summary['repaired_count']);
        $this->line('skipped_count='.(string) $summary['skipped_count']);
        $this->line('failed_count='.(string) $summary['failed_count']);
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
