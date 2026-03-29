<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempt_answer_sets')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table): void {
                if (! Schema::hasColumn('attempt_answer_sets', 'scale_code_v2')) {
                    $table->string('scale_code_v2', 64)->nullable()->after('scale_code');
                }

                if (! Schema::hasColumn('attempt_answer_sets', 'scale_uid')) {
                    $table->char('scale_uid', 36)->nullable()->after('scale_code_v2');
                }
            });
        }

        if (Schema::hasTable('attempt_answer_rows')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table): void {
                if (! Schema::hasColumn('attempt_answer_rows', 'scale_code_v2')) {
                    $table->string('scale_code_v2', 64)->nullable()->after('scale_code');
                }

                if (! Schema::hasColumn('attempt_answer_rows', 'scale_uid')) {
                    $table->char('scale_uid', 36)->nullable()->after('scale_code_v2');
                }
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled.
    }
};
