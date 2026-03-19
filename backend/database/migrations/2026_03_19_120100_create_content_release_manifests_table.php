<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_release_manifests')) {
            return;
        }

        Schema::create('content_release_manifests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('content_pack_release_id')->nullable();
            $table->char('manifest_hash', 64);
            $table->string('schema_version', 32)->default('storage_manifest.v1');
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 512);
            $table->string('pack_id', 64)->nullable();
            $table->string('pack_version', 64)->nullable();
            $table->char('compiled_hash', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('norms_version', 64)->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->unique(['manifest_hash'], 'crm_hash_uq');
            $table->index(['content_pack_release_id'], 'crm_rel_idx');
            $table->index(['pack_id', 'pack_version'], 'crm_pack_ver_idx');
            $table->foreign('content_pack_release_id', 'crm_rel_fk')
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
