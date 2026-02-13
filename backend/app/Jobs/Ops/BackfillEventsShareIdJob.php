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

class BackfillEventsShareIdJob implements ShouldQueue
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
        $backfilled = 0;
        $batches = 0;
        $rows = [];

        Log::info('[events_share_id_backfill] start');

        $cursor = DB::table('events')
            ->select(['id', 'meta_json'])
            ->whereNull('share_id')
            ->whereIn('event_code', ['share_generate', 'share_view', 'share_click'])
            ->orderBy('id')
            ->cursor();

        foreach ($cursor as $event) {
            $processed++;

            $shareId = $this->extractShareId($event->meta_json ?? null);
            if ($shareId === null) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($event->id ?? ''),
                'share_id' => $shareId,
                'updated_at' => now(),
            ];

            if (count($rows) < self::BATCH_SIZE) {
                continue;
            }

            $backfilled += $this->flush($rows);
            $rows = [];
            $batches++;
            usleep(50000);
        }

        if ($rows !== []) {
            $backfilled += $this->flush($rows);
            $batches++;
        }

        Log::info('[events_share_id_backfill] done', [
            'processed' => $processed,
            'backfilled' => $backfilled,
            'batches' => $batches,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * @param array<int, array{id:string,share_id:string,updated_at:mixed}> $rows
     */
    private function flush(array $rows): int
    {
        DB::table('events')->upsert($rows, ['id'], ['share_id', 'updated_at']);

        return count($rows);
    }

    private function extractShareId(mixed $meta): ?string
    {
        $payload = [];

        if (is_array($meta)) {
            $payload = $meta;
        } elseif (is_object($meta)) {
            $payload = (array) $meta;
        } elseif (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $shareId = trim((string) ($payload['share_id'] ?? ''));
        if ($shareId === '' || strlen($shareId) > 64) {
            return null;
        }

        return $shareId;
    }
}
