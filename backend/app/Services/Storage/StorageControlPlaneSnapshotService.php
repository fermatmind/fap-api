<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageControlPlaneSnapshotService
{
    private const SNAPSHOT_SCHEMA = 'storage_control_plane_snapshot.v1';

    private const SNAPSHOT_DIR = 'app/private/control_plane_snapshots';

    public function __construct(
        private readonly StorageControlPlaneStatusService $statusService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function createSnapshot(): array
    {
        $statusPayload = $this->statusService->buildStatus();
        $generatedAt = (string) ($statusPayload['generated_at'] ?? now()->toIso8601String());
        $snapshotPath = $this->nextSnapshotPath();

        $snapshot = array_merge($statusPayload, [
            'snapshot_schema' => self::SNAPSHOT_SCHEMA,
            'status' => 'snapshotted',
            'generated_at' => $generatedAt,
            'snapshot_path' => $snapshotPath,
        ]);

        File::ensureDirectoryExists(dirname($snapshotPath));
        File::put($snapshotPath, $this->encodeSnapshot($snapshot).PHP_EOL);

        $this->recordAudit($snapshotPath, $generatedAt, $snapshot);

        return $snapshot;
    }

    private function nextSnapshotPath(): string
    {
        $filename = now()->format('Ymd_His').'_control_plane_snapshot_'.bin2hex(random_bytes(4)).'.json';

        return storage_path(self::SNAPSHOT_DIR.'/'.$filename);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function encodeSnapshot(array $snapshot): string
    {
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (! is_string($encoded)) {
            throw new \RuntimeException('Failed to encode storage control-plane snapshot JSON.');
        }

        return $encoded;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function recordAudit(string $snapshotPath, string $generatedAt, array $snapshot): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_control_plane_snapshot',
            'target_type' => 'storage',
            'target_id' => 'control_plane_snapshot',
            'meta_json' => json_encode([
                'snapshot_schema' => self::SNAPSHOT_SCHEMA,
                'generated_at' => $generatedAt,
                'snapshot_path' => $snapshotPath,
                'status_schema_version' => $snapshot['schema_version'] ?? null,
                'sections' => array_values(array_filter(array_keys($snapshot), static fn (string $key): bool => ! in_array($key, [
                    'snapshot_schema',
                    'status',
                    'generated_at',
                    'snapshot_path',
                    'schema_version',
                ], true))),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_control_plane_snapshot',
            'request_id' => null,
            'reason' => 'control_plane_snapshot',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
