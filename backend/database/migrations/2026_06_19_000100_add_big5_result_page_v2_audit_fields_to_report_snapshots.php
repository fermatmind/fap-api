<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::table('report_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('report_snapshots', 'big5_result_page_v2_status')) {
                $table->string('big5_result_page_v2_status', 32)->nullable()->after('report_engine_version');
            }

            if (! Schema::hasColumn('report_snapshots', 'big5_result_page_v2_fallback_reason')) {
                $table->string('big5_result_page_v2_fallback_reason', 64)->nullable()->after('big5_result_page_v2_status');
            }

            if (! Schema::hasColumn('report_snapshots', 'big5_result_page_v2_validation_error_count')) {
                $table->unsignedSmallInteger('big5_result_page_v2_validation_error_count')->default(0)->after('big5_result_page_v2_fallback_reason');
            }

            if (! Schema::hasColumn('report_snapshots', 'big5_result_page_v2_audited_at')) {
                $table->timestamp('big5_result_page_v2_audited_at')->nullable()->after('big5_result_page_v2_validation_error_count');
            }
        });

        Schema::table('report_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('report_snapshots', 'scale_code')
                && Schema::hasColumn('report_snapshots', 'big5_result_page_v2_status')
                && ! $this->indexExists('report_snapshots', 'report_snapshots_big5_v2_status_idx')) {
                $table->index(['scale_code', 'big5_result_page_v2_status'], 'report_snapshots_big5_v2_status_idx');
            }

            if (Schema::hasColumn('report_snapshots', 'big5_result_page_v2_fallback_reason')
                && ! $this->indexExists('report_snapshots', 'report_snapshots_big5_v2_reason_idx')) {
                $table->index('big5_result_page_v2_fallback_reason', 'report_snapshots_big5_v2_reason_idx');
            }
        });
    }

    public function down(): void
    {
        // Audit fields are append-only for production observability; rollback is intentionally non-destructive.
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select('PRAGMA index_list('.$table.')');

            return collect($indexes)->contains(fn (object $row): bool => (string) ($row->name ?? '') === $index);
        }

        $database = DB::getDatabaseName();
        $rows = DB::select(
            'select index_name from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $table, $index]
        );

        return $rows !== [];
    }
};
