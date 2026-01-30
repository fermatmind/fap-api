<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchiveColdData extends Command
{
    protected $signature = 'fap:archive:cold-data {--before=}';
    protected $description = 'Archive cold data into JSONL.gz and record audits.';

    public function handle(): int
    {
        $beforeRaw = (string) ($this->option('before') ?? '');
        if ($beforeRaw === '') {
            $this->error('Missing --before=YYYY-MM-DD');
            return 1;
        }

        try {
            $before = Carbon::parse($beforeRaw)->startOfDay();
        } catch (\Throwable $e) {
            $this->error('Invalid --before date.');
            return 1;
        }

        $driver = DB::connection()->getDriverName();
        $tables = [
            'attempt_answer_rows' => 'submitted_at',
            'events' => 'occurred_at',
        ];

        $results = [];
        foreach ($tables as $table => $dateColumn) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $dateColumn)) {
                $results[] = ['table' => $table, 'skipped' => true, 'reason' => 'missing'];
                continue;
            }

            $res = $this->archiveTable($table, $dateColumn, $before, $driver);
            $results[] = array_merge(['table' => $table], $res);
        }

        $this->info(json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 0;
    }

    private function archiveTable(string $table, string $dateColumn, Carbon $before, string $driver): array
    {
        $rowCount = 0;
        $archivePath = $this->archivePath($table, $before);
        $dir = dirname($archivePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $handle = gzopen($archivePath, 'wb9');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'ARCHIVE_OPEN_FAILED'];
        }

        DB::table($table)
            ->where($dateColumn, '<', $before->toDateTimeString())
            ->orderBy($dateColumn)
            ->chunk(1000, function ($rows) use (&$rowCount, $handle) {
                foreach ($rows as $row) {
                    $json = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        $json = '{}';
                    }
                    gzwrite($handle, $json . "\n");
                    $rowCount++;
                }
            });

        gzclose($handle);

        $checksum = hash_file('sha256', $archivePath);
        $objectUri = $this->archiveUri($archivePath);

        if (Schema::hasTable('archive_audits')) {
            DB::table('archive_audits')->insert([
                'table_name' => $table,
                'range_start' => null,
                'range_end' => $before->toDateString(),
                'object_uri' => $objectUri,
                'row_count' => $rowCount,
                'checksum' => $checksum,
                'created_at' => now(),
            ]);
        }

        if ($driver === 'mysql') {
            $this->dropMysqlPartitions($table, $before);
        } else {
            $this->line('sqlite/other driver: skip partition drop for ' . $table);
        }

        return [
            'ok' => true,
            'object_uri' => $objectUri,
            'row_count' => $rowCount,
            'checksum' => $checksum,
        ];
    }

    private function archivePath(string $table, Carbon $before): string
    {
        $driver = (string) config('fap_attempts.archive_driver', 'file');
        if ($driver !== 'file') {
            $driver = 'file';
        }

        $base = (string) config('fap_attempts.archive_path', storage_path('app/archives'));
        $stamp = now()->format('Ymd_His');
        $beforeTag = $before->format('Ymd');
        return rtrim($base, '/') . '/' . $table . '/' . $table . '_before_' . $beforeTag . '_' . $stamp . '.jsonl.gz';
    }

    private function archiveUri(string $path): string
    {
        $driver = (string) config('fap_attempts.archive_driver', 'file');
        if ($driver === 's3') {
            $bucket = (string) config('fap_attempts.archive_bucket', '');
            $prefix = trim((string) config('fap_attempts.archive_prefix', 'archives'), '/');
            $name = basename($path);
            return 's3://' . $bucket . '/' . $prefix . '/' . $name;
        }

        if ($driver === 'oss') {
            $bucket = (string) config('fap_attempts.archive_bucket', '');
            $prefix = trim((string) config('fap_attempts.archive_prefix', 'archives'), '/');
            $name = basename($path);
            return 'oss://' . $bucket . '/' . $prefix . '/' . $name;
        }

        return 'file://' . $path;
    }

    private function dropMysqlPartitions(string $table, Carbon $before): void
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT partition_name, partition_description FROM information_schema.partitions WHERE table_schema = ? AND table_name = ? AND partition_name IS NOT NULL',
            [$db, $table]
        );

        $drop = [];
        foreach ($rows as $row) {
            $name = (string) ($row->partition_name ?? '');
            $desc = (string) ($row->partition_description ?? '');
            if ($name === '' || $name === 'pmax') {
                continue;
            }
            if ($desc === '' || strtoupper($desc) === 'MAXVALUE') {
                continue;
            }
            $descDate = null;
            try {
                $descDate = Carbon::parse($desc);
            } catch (\Throwable $e) {
                $descDate = null;
            }
            if ($descDate && $descDate->lessThanOrEqualTo($before)) {
                if (preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    $drop[] = $name;
                }
            }
        }

        if (empty($drop)) {
            return;
        }

        $sql = 'ALTER TABLE ' . $table . ' DROP PARTITION ' . implode(', ', $drop);
        DB::statement($sql);
    }
}
