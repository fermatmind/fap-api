<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            return;
        }

        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('report_snapshots', function (Blueprint $table) use ($isSqlite): void {
            if (!Schema::hasColumn('report_snapshots', 'report_free_json')) {
                if ($isSqlite) {
                    $table->text('report_free_json')->nullable();
                } else {
                    $table->json('report_free_json')->nullable();
                }
            }

            if (!Schema::hasColumn('report_snapshots', 'report_full_json')) {
                if ($isSqlite) {
                    $table->text('report_full_json')->nullable();
                } else {
                    $table->json('report_full_json')->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};

