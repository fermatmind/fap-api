<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_release_manifest_files')) {
            return;
        }

        Schema::create('content_release_manifest_files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('content_release_manifest_id');
            $table->string('logical_path', 512);
            $table->char('blob_hash', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('role', 64)->nullable();
            $table->string('content_type', 128)->nullable();
            $table->string('encoding', 32)->default('identity');
            $table->string('checksum', 128)->nullable();
            $table->timestamps();

            $table->unique(['content_release_manifest_id', 'logical_path'], 'crmf_manifest_path_uq');
            $table->index(['blob_hash'], 'crmf_blob_hash_idx');
            $table->foreign('content_release_manifest_id', 'crmf_manifest_fk')
                ->references('id')
                ->on('content_release_manifests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
