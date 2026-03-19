<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storage_blobs')) {
            return;
        }

        Schema::create('storage_blobs', function (Blueprint $table): void {
            $table->char('hash', 64)->primary();
            $table->string('disk', 32);
            $table->string('storage_path', 512);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_type', 128)->nullable();
            $table->string('encoding', 32)->default('identity');
            $table->unsignedBigInteger('ref_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'storage_path'], 'sb_disk_path_uq');
            $table->index(['last_verified_at'], 'sb_last_ver_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
