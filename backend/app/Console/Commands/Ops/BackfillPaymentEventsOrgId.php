<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillPaymentEventsOrgId extends Command
{
    protected $signature = 'ops:backfill-payment-events-org-id {--sync : Run synchronously in current process} {--chunk=1000 : Chunk size}';

    protected $description = 'Backfill payment_events.org_id by joining orders.order_no';

    public function handle(): int
    {
        if (! Schema::hasTable('payment_events') || ! Schema::hasTable('orders')) {
            $this->warn('required tables are missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $updated = 0;

        do {
            $rows = DB::table('payment_events')
                ->select('payment_events.id', 'orders.org_id')
                ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
                ->where(function ($q): void {
                    $q->whereNull('payment_events.org_id')->orWhere('payment_events.org_id', 0);
                })
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $updated += DB::table('payment_events')
                    ->where('id', (string) $row->id)
                    ->update([
                        'org_id' => (int) ($row->org_id ?? 0),
                        'updated_at' => now(),
                    ]);
            }

            $this->line('updated chunk: '.$rows->count());
        } while (true);

        $this->info('backfill_payment_events_org_id updated='.$updated);

        return self::SUCCESS;
    }
}
