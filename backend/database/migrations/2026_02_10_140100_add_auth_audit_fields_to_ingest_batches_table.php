<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ingest_batches')) {
            return;
        }

        Schema::table('ingest_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('ingest_batches', 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable();
            }
            if (!Schema::hasColumn('ingest_batches', 'auth_mode')) {
                $table->enum('auth_mode', ['sanctum', 'signature'])->nullable();
            }
            if (!Schema::hasColumn('ingest_batches', 'signature_ok')) {
                $table->boolean('signature_ok')->default(false);
            }
            if (!Schema::hasColumn('ingest_batches', 'source_ip')) {
                $table->string('source_ip', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ingest_batches')) {
            return;
        }

        Schema::table('ingest_batches', function (Blueprint $table) {
            if (Schema::hasColumn('ingest_batches', 'source_ip')) {
                $table->dropColumn('source_ip');
            }
            if (Schema::hasColumn('ingest_batches', 'signature_ok')) {
                $table->dropColumn('signature_ok');
            }
            if (Schema::hasColumn('ingest_batches', 'auth_mode')) {
                $table->dropColumn('auth_mode');
            }
            if (Schema::hasColumn('ingest_batches', 'actor_user_id')) {
                $table->dropColumn('actor_user_id');
            }
        });
    }
};
