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

class BackfillOrgIdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $table,
        public string $idColumn = 'id',
        public string $orgIdColumn = 'org_id',
        public int $batchSize = 1000,
        public ?string $progressKey = null
    ) {
        $this->batchSize = max(1, $batchSize);
        $this->progressKey = $progressKey !== null && $progressKey !== ''
            ? $progressKey
            : "org_id_backfill:{$table}";
    }

    public function handle(): void
    {
        if (!$this->isSafeIdentifier($this->table)
            || !$this->isSafeIdentifier($this->idColumn)
            || !$this->isSafeIdentifier($this->orgIdColumn)) {
            Log::warning('[org_id_backfill] invalid identifier', [
                'table' => $this->table,
                'id_column' => $this->idColumn,
                'org_id_column' => $this->orgIdColumn,
            ]);
            return;
        }

        if (!\App\Support\SchemaBaseline::hasTable('migration_backfills')
            || !\App\Support\SchemaBaseline::hasTable($this->table)
            || !\App\Support\SchemaBaseline::hasColumn($this->table, $this->idColumn)
            || !\App\Support\SchemaBaseline::hasColumn($this->table, $this->orgIdColumn)) {
            Log::warning('[org_id_backfill] table/column missing', [
                'table' => $this->table,
                'id_column' => $this->idColumn,
                'org_id_column' => $this->orgIdColumn,
            ]);
            return;
        }

        $lock = Cache::lock("backfill:org_id:{$this->table}", 300);
        if (!$lock->get()) {
            Log::info('[org_id_backfill] lock busy, skip', ['table' => $this->table]);
            return;
        }

        $startedAt = microtime(true);
        $chunks = 0;
        $rowsUpdated = 0;

        try {
            [$lastId, $lastCursor] = $this->loadState();
            $mode = $this->determineMode($lastCursor);

            if ($mode === 'string') {
                $this->runStringBackfill($lastId, $lastCursor, $chunks, $rowsUpdated, $startedAt);
            } else {
                $this->runNumericBackfill($lastId, $chunks, $rowsUpdated, $startedAt);
            }

            Log::info('[org_id_backfill] done', [
                'table' => $this->table,
                'mode' => $mode,
                'chunks' => $chunks,
                'rows_updated' => $rowsUpdated,
                'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function runNumericBackfill(int $lastId, int &$chunks, int &$rowsUpdated, float $startedAt): void
    {
        DB::table($this->table)
            ->select($this->idColumn)
            ->whereNull($this->orgIdColumn)
            ->where($this->idColumn, '>', $lastId)
            ->chunkById($this->batchSize, function ($rows) use (&$lastId, &$chunks, &$rowsUpdated, $startedAt): void {
                $ids = [];
                foreach ($rows as $row) {
                    $ids[] = (int) $row->{$this->idColumn};
                }

                if ($ids === []) {
                    return;
                }

                $updated = DB::table($this->table)
                    ->whereIn($this->idColumn, $ids)
                    ->whereNull($this->orgIdColumn)
                    ->update([$this->orgIdColumn => 0]);

                $lastId = max($ids);
                $rowsUpdated += (int) $updated;
                $chunks++;

                $this->persistState($lastId, null);
                $this->logProgressEveryN($chunks, $rowsUpdated, $lastId, null, $startedAt);
                usleep(50000);
            }, $this->idColumn);
    }

    private function runStringBackfill(
        int $lastId,
        ?string $lastCursor,
        int &$chunks,
        int &$rowsUpdated,
        float $startedAt
    ): void {
        $cursor = $lastCursor;

        while (true) {
            $query = DB::table($this->table)
                ->select($this->idColumn)
                ->whereNull($this->orgIdColumn);

            if ($cursor !== null && $cursor !== '') {
                $query->where($this->idColumn, '>', $cursor);
            }

            $rows = $query
                ->orderBy($this->idColumn)
                ->limit($this->batchSize)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (string) $row->{$this->idColumn};
            }

            $updated = DB::table($this->table)
                ->whereIn($this->idColumn, $ids)
                ->whereNull($this->orgIdColumn)
                ->update([$this->orgIdColumn => 0]);

            $cursor = (string) end($ids);
            if (ctype_digit($cursor)) {
                $lastId = (int) $cursor;
            }

            $rowsUpdated += (int) $updated;
            $chunks++;

            $this->persistState($lastId, $cursor);
            $this->logProgressEveryN($chunks, $rowsUpdated, $lastId, $cursor, $startedAt);
            usleep(50000);
        }
    }

    private function logProgressEveryN(
        int $chunks,
        int $rowsUpdated,
        int $lastId,
        ?string $lastCursor,
        float $startedAt
    ): void {
        if ($chunks % 10 !== 0) {
            return;
        }

        Log::info('[org_id_backfill] progress', [
            'table' => $this->table,
            'chunks' => $chunks,
            'rows_updated' => $rowsUpdated,
            'last_id' => $lastId,
            'last_cursor' => $lastCursor,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function determineMode(?string $lastCursor): string
    {
        if ($lastCursor !== null && $lastCursor !== '') {
            return 'string';
        }

        $probe = DB::table($this->table)
            ->whereNull($this->orgIdColumn)
            ->orderBy($this->idColumn)
            ->value($this->idColumn);

        if ($probe === null) {
            return 'numeric';
        }

        return is_numeric((string) $probe) ? 'numeric' : 'string';
    }

    /**
     * @return array{0:int, 1:?string}
     */
    private function loadState(): array
    {
        $hasCursor = \App\Support\SchemaBaseline::hasColumn('migration_backfills', 'last_cursor');

        DB::table('migration_backfills')->insertOrIgnore([
            'key' => (string) $this->progressKey,
            'last_id' => 0,
            'updated_at' => now(),
        ]);

        $query = DB::table('migration_backfills')->where('key', (string) $this->progressKey);
        $row = $hasCursor
            ? $query->select(['last_id', 'last_cursor'])->first()
            : $query->select(['last_id'])->first();

        if ($row === null) {
            return [0, null];
        }

        return [
            (int) ($row->last_id ?? 0),
            $hasCursor ? (($row->last_cursor ?? null) !== null ? (string) $row->last_cursor : null) : null,
        ];
    }

    private function persistState(int $lastId, ?string $lastCursor): void
    {
        $payload = [
            'last_id' => $lastId,
            'updated_at' => now(),
        ];

        if (\App\Support\SchemaBaseline::hasColumn('migration_backfills', 'last_cursor')) {
            $payload['last_cursor'] = $lastCursor;
        }

        DB::table('migration_backfills')
            ->where('key', (string) $this->progressKey)
            ->update($payload);
    }

    private function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $value);
    }
}
