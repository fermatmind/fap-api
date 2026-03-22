<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_artifact_versions')) {
            return;
        }

        Schema::create('report_artifact_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('artifact_slot_id');
            $table->unsignedInteger('version_no');
            $table->string('source_type', 64);
            $table->string('report_snapshot_id', 64)->nullable();
            $table->char('storage_blob_id', 64)->nullable();
            $table->unsignedBigInteger('created_from_receipt_id')->nullable();
            $table->unsignedBigInteger('supersedes_version_id')->nullable();
            $table->string('manifest_hash', 128)->nullable();
            $table->string('dir_version', 128)->nullable();
            $table->string('scoring_spec_version', 64)->nullable();
            $table->string('report_engine_version', 64)->nullable();
            $table->string('content_hash', 128)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['artifact_slot_id', 'version_no'], 'report_artifact_versions_slot_version_unique');
            $table->index('artifact_slot_id', 'report_artifact_versions_artifact_slot_id_idx');
            $table->index('source_type', 'report_artifact_versions_source_type_idx');
            $table->index('report_snapshot_id', 'report_artifact_versions_report_snapshot_id_idx');
            $table->index('storage_blob_id', 'report_artifact_versions_storage_blob_id_idx');
            $table->index('created_from_receipt_id', 'report_artifact_versions_created_from_receipt_id_idx');
            $table->index('supersedes_version_id', 'report_artifact_versions_supersedes_version_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
