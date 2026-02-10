<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_INDEX = 'idx_report_snapshots_status';

    public function up(): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::table('report_snapshots', function (Blueprint $table): void {
            if (!Schema::hasColumn('report_snapshots', 'status')) {
                $table->string('status', 16)->default('ready');
            }

            if (!Schema::hasColumn('report_snapshots', 'last_error')) {
                $table->text('last_error')->nullable();
            }

            if (!Schema::hasColumn('report_snapshots', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (!$this->indexExists(self::STATUS_INDEX)) {
            Schema::table('report_snapshots', function (Blueprint $table): void {
                $table->index(['org_id', 'attempt_id', 'status'], self::STATUS_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            return;
        }

        if ($this->indexExists(self::STATUS_INDEX)) {
            Schema::table('report_snapshots', function (Blueprint $table): void {
                $table->dropIndex(self::STATUS_INDEX);
            });
        }

        Schema::table('report_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('report_snapshots', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            if (Schema::hasColumn('report_snapshots', 'last_error')) {
                $table->dropColumn('last_error');
            }

            if (Schema::hasColumn('report_snapshots', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('report_snapshots')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT indexname FROM pg_indexes WHERE tablename = ?',
                ['report_snapshots']
            );
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$database, 'report_snapshots', $indexName]
        );

        return !empty($rows);
    }
};
