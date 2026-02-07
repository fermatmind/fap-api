<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationObservabilityService
{
    public function snapshot(int $limit = 20): array
    {
        $limit = $this->sanitizeLimit($limit);

        return [
            'migrations' => $this->migrationSummary($limit),
            'index_audit' => $this->indexAuditSummary($limit),
        ];
    }

    public function rollbackPreview(int $steps = 1): array
    {
        $steps = max(1, min($steps, 20));

        if (!Schema::hasTable('migrations')) {
            return [
                'steps' => $steps,
                'items' => [],
            ];
        }

        $rows = DB::table('migrations')
            ->select(['migration', 'batch'])
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->limit($steps)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'migration' => (string) ($row->migration ?? ''),
                'batch' => (int) ($row->batch ?? 0),
            ];
        }

        return [
            'steps' => $steps,
            'items' => $items,
        ];
    }

    private function migrationSummary(int $limit): array
    {
        if (!Schema::hasTable('migrations')) {
            return [
                'current_batch' => 0,
                'total' => 0,
                'recent' => [],
            ];
        }

        $currentBatch = (int) (DB::table('migrations')->max('batch') ?? 0);
        $total = (int) DB::table('migrations')->count();
        $rows = DB::table('migrations')
            ->select(['migration', 'batch'])
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->limit($limit)
            ->get();

        $recent = [];
        foreach ($rows as $row) {
            $recent[] = [
                'migration' => (string) ($row->migration ?? ''),
                'batch' => (int) ($row->batch ?? 0),
            ];
        }

        return [
            'current_batch' => $currentBatch,
            'total' => $total,
            'recent' => $recent,
        ];
    }

    private function indexAuditSummary(int $limit): array
    {
        if (!Schema::hasTable('migration_index_audits')) {
            return [
                'total' => 0,
                'by_action' => [],
                'by_status' => [],
                'recent' => [],
            ];
        }

        $total = (int) DB::table('migration_index_audits')->count();
        $byAction = DB::table('migration_index_audits')
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->orderBy('action')
            ->get();
        $byStatus = DB::table('migration_index_audits')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();
        $recentRows = DB::table('migration_index_audits')
            ->select([
                'id',
                'migration_name',
                'table_name',
                'index_name',
                'action',
                'phase',
                'driver',
                'status',
                'reason',
                'recorded_at',
                'created_at',
            ])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'total' => $total,
            'by_action' => $this->toBucketRows($byAction, 'action'),
            'by_status' => $this->toBucketRows($byStatus, 'status'),
            'recent' => $this->toRecentRows($recentRows),
        ];
    }

    private function sanitizeLimit(int $limit): int
    {
        return max(1, min($limit, 200));
    }

    /**
     * @param iterable<object> $rows
     * @return list<array{key: string, total: int}>
     */
    private function toBucketRows(iterable $rows, string $field): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'key' => (string) ($row->{$field} ?? ''),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $items;
    }

    /**
     * @param iterable<object> $rows
     * @return list<array<string, mixed>>
     */
    private function toRecentRows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'migration_name' => (string) ($row->migration_name ?? ''),
                'table_name' => (string) ($row->table_name ?? ''),
                'index_name' => (string) ($row->index_name ?? ''),
                'action' => (string) ($row->action ?? ''),
                'phase' => (string) ($row->phase ?? ''),
                'driver' => (string) ($row->driver ?? ''),
                'status' => (string) ($row->status ?? ''),
                'reason' => (string) ($row->reason ?? ''),
                'recorded_at' => $row->recorded_at,
                'created_at' => $row->created_at,
            ];
        }

        return $items;
    }
};
