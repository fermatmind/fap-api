<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillBenefitGrantsOrderNo extends Command
{
    protected $signature = 'ops:backfill-benefit-grants-order-no {--sync : Run synchronously in current process} {--chunk=1000 : Chunk size}';

    protected $description = 'Best-effort backfill benefit_grants.order_no using order references';

    public function handle(): int
    {
        if (! Schema::hasTable('benefit_grants') || ! Schema::hasTable('orders')) {
            $this->warn('required tables are missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $updated = 0;

        do {
            $rows = DB::table('benefit_grants')
                ->where(function ($q): void {
                    $q->whereNull('order_no')->orWhere('order_no', '');
                })
                ->orderBy('created_at')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $grant) {
                $orderNo = $this->resolveOrderNo($grant);
                if ($orderNo === null || $orderNo === '') {
                    continue;
                }

                $updated += DB::table('benefit_grants')
                    ->where('id', (string) $grant->id)
                    ->update([
                        'order_no' => $orderNo,
                        'updated_at' => now(),
                    ]);
            }

            $this->line('updated chunk: '.$rows->count());
        } while (true);

        $this->info('backfill_benefit_grants_order_no updated='.$updated);

        return self::SUCCESS;
    }

    private function resolveOrderNo(object $grant): ?string
    {
        $orgId = (int) ($grant->org_id ?? 0);
        $sourceOrderId = trim((string) ($grant->source_order_id ?? ''));

        if ($sourceOrderId !== '') {
            $direct = DB::table('orders')
                ->where('org_id', $orgId)
                ->where(function ($q) use ($sourceOrderId): void {
                    $q->where('id', $sourceOrderId)
                        ->orWhere('order_no', $sourceOrderId);
                })
                ->orderByDesc('created_at')
                ->value('order_no');

            if (is_string($direct) && $direct !== '') {
                return $direct;
            }
        }

        $attemptId = trim((string) ($grant->attempt_id ?? ''));
        if ($attemptId !== '') {
            $byAttempt = DB::table('orders')
                ->where('org_id', $orgId)
                ->where('target_attempt_id', $attemptId)
                ->whereIn('status', ['paid', 'fulfilled', 'refunded'])
                ->orderByDesc('created_at')
                ->value('order_no');

            if (is_string($byAttempt) && $byAttempt !== '') {
                return $byAttempt;
            }
        }

        return null;
    }
}
