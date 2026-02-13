<?php

declare(strict_types=1);

namespace App\Jobs\Ops;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillFmTokenHashJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 10, 20];
    public int $timeout = 120;

    private const BATCH_SIZE = 1000;

    public function handle(): void
    {
        $startedAt = microtime(true);
        $processed = 0;
        $batches = 0;
        $rows = [];

        Log::info('[fm_token_hash_backfill] start');

        $cursor = DB::table('fm_tokens')
            ->select('token')
            ->where(function ($query): void {
                $query->whereNull('token_hash')->orWhere('token_hash', '');
            })
            ->orderBy('token')
            ->cursor();

        foreach ($cursor as $row) {
            $token = trim((string) ($row->token ?? ''));
            if ($token === '') {
                continue;
            }

            $rows[] = [
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'updated_at' => now(),
            ];
            $processed++;

            if (count($rows) < self::BATCH_SIZE) {
                continue;
            }

            $this->flush($rows);
            $rows = [];
            $batches++;
            usleep(50000);
        }

        if ($rows !== []) {
            $this->flush($rows);
            $batches++;
        }

        Log::info('[fm_token_hash_backfill] done', [
            'processed' => $processed,
            'batches' => $batches,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function flush(array $rows): void
    {
        DB::table('fm_tokens')->upsert($rows, ['token'], ['token_hash', 'updated_at']);
    }
}
