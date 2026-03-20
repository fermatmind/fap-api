<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_release_exact_manifests')) {
            return;
        }

        Schema::create('content_release_exact_manifests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('content_pack_release_id')->nullable();
            $table->char('source_identity_hash', 64);
            $table->char('manifest_hash', 64);
            $table->string('schema_version', 32)->default('storage_exact_manifest.v1');
            $table->string('source_kind', 64);
            $table->string('source_disk', 32)->default('local');
            $table->string('source_storage_path', 1024);
            $table->string('pack_id', 64)->nullable();
            $table->string('pack_version', 64)->nullable();
            $table->char('compiled_hash', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('norms_version', 64)->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedBigInteger('total_size_bytes')->default(0);
            $table->json('payload_json')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['source_identity_hash', 'manifest_hash'], 'crem_source_manifest_uq');
            $table->index(['content_pack_release_id'], 'crem_rel_idx');
            $table->index(['pack_id', 'pack_version'], 'crem_pack_ver_idx');
            $table->index(['source_kind', 'source_disk'], 'crem_source_kind_idx');
            $table->foreign('content_pack_release_id', 'crem_rel_fk')
                ->references('id')
                ->on('content_pack_releases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
