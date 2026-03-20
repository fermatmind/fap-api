<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storage_blob_locations')) {
            return;
        }

        Schema::create('storage_blob_locations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('blob_hash', 64);
            $table->string('disk', 32);
            $table->string('storage_path', 1024);
            $table->string('location_kind', 32)->default('remote_copy');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 128)->nullable();
            $table->string('etag', 256)->nullable();
            $table->string('storage_class', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'storage_path'], 'sbl_disk_path_uq');
            $table->index(['blob_hash'], 'sbl_blob_hash_idx');
            $table->index(['verified_at'], 'sbl_verified_idx');
            $table->foreign('blob_hash', 'sbl_blob_hash_fk')
                ->references('hash')
                ->on('storage_blobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
