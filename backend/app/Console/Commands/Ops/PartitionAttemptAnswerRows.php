<?php

namespace App\Console\Commands\Ops;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartitionAttemptAnswerRows extends Command
{
    private const TABLE = 'attempt_answer_rows';

    protected $signature = 'ops:partition-attempt-answer-rows
        {--months=12 : Number of future monthly partitions}
        {--dry-run : Print SQL without executing}
        {--execute : Execute ALTER TABLE explicitly}';

    protected $description = 'Apply MySQL monthly RANGE partitions for attempt_answer_rows in maintenance windows';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->info('skip: current driver is not mysql');
            return self::SUCCESS;
        }

        if (!Schema::hasTable(self::TABLE)) {
            $this->error('table missing: ' . self::TABLE);
            return self::FAILURE;
        }

        if ($this->hasAnyPartition()) {
            $this->info('already partitioned: ' . self::TABLE);
            return self::SUCCESS;
        }

        $months = (int) $this->option('months');
        if ($months <= 0) {
            $this->error('--months must be greater than 0');
            return self::FAILURE;
        }

        $constraintError = $this->partitionConstraintError();
        if ($constraintError !== null) {
            $this->error($constraintError);
            return self::FAILURE;
        }

        $sql = $this->buildPartitionSql($months);
        $this->line('SQL:');
        $this->line($sql);

        $shouldExecute = (bool) $this->option('execute');
        if (!$shouldExecute) {
            $this->info('dry-run mode (pass --execute to apply DDL)');
            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        DB::statement($sql);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->info('partition DDL executed successfully. elapsed_ms=' . $elapsedMs);

        return self::SUCCESS;
    }

    private function hasAnyPartition(): bool
    {
        $database = DB::getDatabaseName();
        if (!is_string($database) || $database === '') {
            return false;
        }

        $row = DB::selectOne(
            'SELECT partition_name
             FROM information_schema.partitions
             WHERE table_schema = ?
               AND table_name = ?
               AND partition_name IS NOT NULL
             LIMIT 1',
            [$database, self::TABLE]
        );

        return $row !== null;
    }

    private function partitionConstraintError(): ?string
    {
        $database = DB::getDatabaseName();
        if (!is_string($database) || $database === '') {
            return 'cannot resolve current database name';
        }

        $column = DB::selectOne(
            'SELECT is_nullable
             FROM information_schema.columns
             WHERE table_schema = ?
               AND table_name = ?
               AND column_name = ?
             LIMIT 1',
            [$database, self::TABLE, 'submitted_at']
        );

        if ($column === null) {
            return 'constraint failed: submitted_at column not found';
        }

        $isNullable = strtoupper((string) ($column->is_nullable ?? $column->IS_NULLABLE ?? 'YES'));
        if ($isNullable !== 'NO') {
            return 'constraint failed: submitted_at must be NOT NULL for partitioning';
        }

        $pkRows = DB::select(
            'SELECT column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = ?
               AND table_name = ?
               AND constraint_name = ?
             ORDER BY ordinal_position',
            [$database, self::TABLE, 'PRIMARY']
        );

        $pkColumns = array_map(
            static fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME ?? ''),
            $pkRows
        );

        if (!in_array('submitted_at', $pkColumns, true)) {
            return 'constraint failed: PRIMARY KEY must include submitted_at';
        }

        return null;
    }

    private function buildPartitionSql(int $months): string
    {
        $cursor = Carbon::now()->startOfMonth();
        $parts = [];

        for ($i = 0; $i < $months; $i++) {
            $next = (clone $cursor)->addMonth();
            $name = 'p' . $cursor->format('Ym');
            $parts[] = sprintf(
                "PARTITION %s VALUES LESS THAN ('%s 00:00:00')",
                $name,
                $next->format('Y-m-d')
            );
            $cursor = $next;
        }

        $parts[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        return 'ALTER TABLE ' . self::TABLE
            . ' PARTITION BY RANGE COLUMNS(submitted_at) ('
            . implode(', ', $parts)
            . ')';
    }
}
