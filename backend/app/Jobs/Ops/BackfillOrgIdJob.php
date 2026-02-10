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
        public int $batchSize = 1000
    ) {
        $this->batchSize = max(1, $batchSize);
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

        if (!Schema::hasTable('migration_backfills')
            || !Schema::hasTable($this->table)
            || !Schema::hasColumn($this->table, $this->idColumn)
            || !Schema::hasColumn($this->table, $this->orgIdColumn)) {
            Log::warning('[org_id_backfill] table/column missing', [
                'table' => $this->table,
                'id_column' => $this->idColumn,
                'org_id_column' => $this->orgIdColumn,
            ]);

            return;
        }

        $lock = Cache::lock($this->lockKey(), 900);
        if (!$lock->get()) {
            Log::info('[org_id_backfill] lock busy, skip', ['table' => $this->table]);

            return;
        }

        try {
            [$lastId, $lastCursor] = $this->loadState();
            $mode = $this->determineMode($lastCursor);

            if ($mode === 'string') {
                $this->runStringBackfill($lastId, $lastCursor);

                return;
            }

            $this->runNumericBackfill($lastId);
        } finally {
            $lock->release();
        }
    }

    private function runNumericBackfill(int $lastId): void
    {
        while (true) {
            $rows = DB::table($this->table)
                ->select($this->idColumn)
                ->whereNull($this->orgIdColumn)
                ->where($this->idColumn, '>', $lastId)
                ->orderBy($this->idColumn)
                ->limit($this->batchSize)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row->{$this->idColumn};
            }

            DB::table($this->table)
                ->whereIn($this->idColumn, $ids)
                ->update([$this->orgIdColumn => 0]);

            $lastId = max($ids);
            $this->persistState($lastId, null);

            Log::info('[org_id_backfill] chunk', [
                'table' => $this->table,
                'mode' => 'numeric',
                'updated' => count($ids),
                'last_id' => $lastId,
            ]);

            usleep(50000);
        }
    }

    private function runStringBackfill(int $lastId, ?string $lastCursor): void
    {
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

            DB::table($this->table)
                ->whereIn($this->idColumn, $ids)
                ->update([$this->orgIdColumn => 0]);

            $cursor = (string) end($ids);
            if (ctype_digit($cursor)) {
                $lastId = (int) $cursor;
            }

            $this->persistState($lastId, $cursor);

            Log::info('[org_id_backfill] chunk', [
                'table' => $this->table,
                'mode' => 'string',
                'updated' => count($ids),
                'last_cursor' => $cursor,
            ]);

            usleep(50000);
        }
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
        $row = DB::table('migration_backfills')
            ->where('key', $this->stateKey())
            ->first();

        if ($row === null) {
            $this->persistState(0, null);

            return [0, null];
        }

        return [
            (int) ($row->last_id ?? 0),
            $row->last_cursor !== null ? (string) $row->last_cursor : null,
        ];
    }

    private function persistState(int $lastId, ?string $lastCursor): void
    {
        DB::table('migration_backfills')->updateOrInsert(
            ['key' => $this->stateKey()],
            [
                'last_id' => $lastId,
                'last_cursor' => $lastCursor,
                'updated_at' => now(),
            ]
        );
    }

    private function stateKey(): string
    {
        return "org_id_backfill:{$this->table}";
    }

    private function lockKey(): string
    {
        return "org_id_backfill_lock:{$this->table}";
    }

    private function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $value);
    }
}
