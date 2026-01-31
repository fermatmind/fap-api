<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organization_members')) {
            return;
        }

        DB::table('organization_members')
            ->whereNull('role')
            ->orWhere('role', '')
            ->update([
                'role' => 'member',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // no-op (data backfill only)
    }
};
