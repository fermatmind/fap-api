<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assessments')) {
            return;
        }

        Schema::table('assessments', function (Blueprint $table): void {
            if (! Schema::hasColumn('assessments', 'scale_code_v2')) {
                $table->string('scale_code_v2', 64)->nullable()->after('scale_code');
            }

            if (! Schema::hasColumn('assessments', 'scale_uid')) {
                $table->char('scale_uid', 36)->nullable()->after('scale_code_v2');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled.
    }
};

