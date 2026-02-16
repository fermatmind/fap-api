<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) use ($isSqlite): void {
                if (!Schema::hasColumn('orders', 'meta_json')) {
                    if ($isSqlite) {
                        $table->text('meta_json')->nullable();
                    } else {
                        $table->json('meta_json')->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('benefit_grants')) {
            Schema::table('benefit_grants', function (Blueprint $table) use ($isSqlite): void {
                if (!Schema::hasColumn('benefit_grants', 'meta_json')) {
                    if ($isSqlite) {
                        $table->text('meta_json')->nullable();
                    } else {
                        $table->json('meta_json')->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};

