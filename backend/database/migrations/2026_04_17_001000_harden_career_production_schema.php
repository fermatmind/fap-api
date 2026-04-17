<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->relaxTrustManifestFreshnessColumn();
        $this->widenVisitorIdColumn('context_snapshots');
        $this->widenVisitorIdColumn('profile_projections');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to preserve production authority snapshots.
    }

    private function relaxTrustManifestFreshnessColumn(): void
    {
        if (! Schema::hasTable('trust_manifests') || ! Schema::hasColumn('trust_manifests', 'last_substantive_update_at')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE trust_manifests MODIFY last_substantive_update_at TIMESTAMP NULL'),
            'pgsql' => DB::statement('ALTER TABLE trust_manifests ALTER COLUMN last_substantive_update_at DROP NOT NULL'),
            default => null,
        };
    }

    private function widenVisitorIdColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'visitor_id')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$table} MODIFY visitor_id VARCHAR(191) NULL"),
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN visitor_id TYPE VARCHAR(191)"),
            default => null,
        };
    }
};
