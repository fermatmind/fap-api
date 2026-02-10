<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentsPruneEvents extends Command
{
    private const TABLE = 'payment_events';
    private const CHUNK_SIZE = 1000;

    protected $signature = 'payments:prune-events {--days=90}';
    protected $description = 'Archive and prune old payment webhook events.';

    public function handle(): int
    {
        if (!Schema::hasTable(self::TABLE)) {
            $this->info(json_encode([
                'ok' => true,
                'skipped' => true,
                'reason' => 'payment_events_missing',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $days = (int) ($this->option('days') ?? 90);
        if ($days <= 0) {
            $this->error('Invalid --days, must be greater than 0.');
            return 1;
        }

        if (!Schema::hasColumn(self::TABLE, 'id')) {
            $this->error('payment_events.id is required for prune.');
            return 1;
        }

        $dateColumn = $this->resolveDateColumn();
        if ($dateColumn === null) {
            $this->error('payment_events.created_at/received_at missing.');
            return 1;
        }

        $cutoff = now()->subDays($days);
        $cutoffValue = $cutoff->toDateTimeString();

        $pendingCount = (int) DB::table(self::TABLE)
            ->where($dateColumn, '<', $cutoffValue)
            ->count();

        if ($pendingCount === 0) {
            $this->info(json_encode([
                'ok' => true,
                'archived_count' => 0,
                'deleted_count' => 0,
                'object_uri' => null,
                'checksum' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $archivePath = $this->archivePath($cutoff);
        $archiveDir = dirname($archivePath);
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0777, true);
        }

        $handle = gzopen($archivePath, 'wb9');
        if ($handle === false) {
            $this->error('Failed to open archive file.');
            return 1;
        }

        $archivedCount = 0;
        $deletedCount = 0;
        $columns = $this->archiveColumns();

        try {
            while (true) {
                $rows = DB::table(self::TABLE)
                    ->select($columns)
                    ->where($dateColumn, '<', $cutoffValue)
                    ->orderBy($dateColumn)
                    ->limit(self::CHUNK_SIZE)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $ids = [];
                foreach ($rows as $row) {
                    $line = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($line === false) {
                        $line = '{}';
                    }

                    if (gzwrite($handle, $line . "\n") === false) {
                        throw new \RuntimeException('failed to write archive line');
                    }

                    $archivedCount++;
                    $id = trim((string) ($row->id ?? ''));
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }

                if ($ids !== []) {
                    $deletedCount += (int) DB::table(self::TABLE)
                        ->whereIn('id', $ids)
                        ->delete();
                }
            }
        } catch (\Throwable $e) {
            gzclose($handle);
            @unlink($archivePath);

            $this->error('Archive failed: ' . $e->getMessage());
            return 1;
        }

        gzclose($handle);

        $checksum = hash_file('sha256', $archivePath);
        $objectUri = 'file://' . $archivePath;

        if (Schema::hasTable('archive_audits')) {
            DB::table('archive_audits')->insert([
                'table_name' => self::TABLE,
                'range_start' => null,
                'range_end' => $cutoff->toDateString(),
                'object_uri' => $objectUri,
                'row_count' => $archivedCount,
                'checksum' => $checksum !== false ? $checksum : null,
                'created_at' => now(),
            ]);
        }

        $this->info(json_encode([
            'ok' => true,
            'archived_count' => $archivedCount,
            'deleted_count' => $deletedCount,
            'object_uri' => $objectUri,
            'checksum' => $checksum !== false ? $checksum : null,
            'cutoff' => $cutoff->toDateTimeString(),
            'days' => $days,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    private function resolveDateColumn(): ?string
    {
        if (Schema::hasColumn(self::TABLE, 'created_at')) {
            return 'created_at';
        }

        if (Schema::hasColumn(self::TABLE, 'received_at')) {
            return 'received_at';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function archiveColumns(): array
    {
        $columns = [
            'id',
            'provider',
            'provider_event_id',
            'order_no',
            'event_type',
            'status',
            'signature_ok',
            'payload_size_bytes',
            'payload_sha256',
            'payload_s3_key',
            'received_at',
            'processed_at',
            'created_at',
        ];

        $available = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                $available[] = $column;
            }
        }

        if ($available === []) {
            return ['id'];
        }

        return $available;
    }

    private function archivePath(Carbon $cutoff): string
    {
        $base = (string) config('fap_attempts.archive_path', storage_path('app/archives'));
        $stamp = now()->format('Ymd_His');
        $beforeTag = $cutoff->format('Ymd');

        return rtrim($base, '/') . '/payment_events/payment_events_before_' . $beforeTag . '_' . $stamp . '.jsonl.gz';
    }
}
