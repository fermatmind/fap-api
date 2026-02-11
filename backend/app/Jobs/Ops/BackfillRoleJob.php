<?php

namespace App\Jobs\Ops;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackfillRoleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $lock = Cache::lock('backfill:organization_members:role', 300);

        if (!$lock->get()) {
            Log::info('[backfill_role] lock busy, skip');
            return;
        }

        $totalUpdated = 0;
        $lastId = 0;

        try {
            if (!Schema::hasTable('organization_members')
                || !Schema::hasColumn('organization_members', 'id')
                || !Schema::hasColumn('organization_members', 'role')) {
                Log::warning('[backfill_role] table/column missing');
                return;
            }

            DB::table('organization_members')
                ->select('id')
                ->where(function ($query): void {
                    $query->whereNull('role')->orWhere('role', '');
                })
                ->chunkById(1000, function ($rows) use (&$totalUpdated, &$lastId): void {
                    $ids = [];
                    foreach ($rows as $row) {
                        $ids[] = (int) $row->id;
                    }

                    if ($ids === []) {
                        return;
                    }

                    $updated = DB::table('organization_members')
                        ->whereIn('id', $ids)
                        ->where(function ($query): void {
                            $query->whereNull('role')->orWhere('role', '');
                        })
                        ->update([
                            'role' => 'member',
                            'updated_at' => now(),
                        ]);

                    $lastId = max($ids);
                    $totalUpdated += (int) $updated;

                    Log::info('[backfill_role] progress', [
                        'batch_updated' => (int) $updated,
                        'total_updated' => $totalUpdated,
                        'last_id' => $lastId,
                    ]);

                    usleep(50000);
                }, 'id');

            Log::info('[backfill_role] done', [
                'total_updated' => $totalUpdated,
                'last_id' => $lastId,
            ]);
        } finally {
            $lock->release();
        }
    }
}
