<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'events';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('event_code', 64);
                $table->string('anon_id', 128)->nullable();
                $table->string('attempt_id', 64);
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->char('id', 36)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'event_code')) {
                $table->string('event_code', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'anon_id')) {
                $table->string('anon_id', 128)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
