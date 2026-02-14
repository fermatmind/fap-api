<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'organization_members';

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'is_active')) {
                $table->tinyInteger('is_active')->default(1);
            }
        });

        if (Schema::hasColumn($tableName, 'is_active')) {
            DB::table($tableName)
                ->whereNull('is_active')
                ->update(['is_active' => 1]);
        }

        if (Schema::hasColumn($tableName, 'user_id')
            && Schema::hasColumn($tableName, 'is_active')
            && !SchemaIndex::indexExists($tableName, 'organization_members_user_active_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['user_id', 'is_active'], 'organization_members_user_active_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
