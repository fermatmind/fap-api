<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_healthz_snapshots')) {
            return;
        }

        Schema::create('ops_healthz_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('env', 32);
            $table->string('revision', 64);
            $table->unsignedTinyInteger('ok');
            $table->json('deps_json');
            $table->json('error_codes_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['env', 'occurred_at'], 'idx_ops_healthz_env_time');
            $table->index(['env', 'ok', 'occurred_at'], 'idx_ops_healthz_ok');
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
