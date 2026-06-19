<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('career_job_ai_impact_assets')) {
            return;
        }

        Schema::create('career_job_ai_impact_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('career_job_slug', 160);
            $table->string('locale', 16);
            $table->string('asset_version', 96);
            $table->string('status', 64);
            $table->boolean('preview_allowlisted')->default(false);
            $table->json('asset_payload_json');
            $table->json('sources_json')->nullable();
            $table->json('evidence_used_json')->nullable();
            $table->json('derived_from_synthesis_json')->nullable();
            $table->json('audit_fields_json')->nullable();
            $table->string('asset_row_hash', 64);
            $table->string('source_artifact_sha256', 64)->nullable();
            $table->string('evidence_artifact_sha256', 64)->nullable();
            $table->string('synthesis_artifact_sha256', 64)->nullable();
            $table->uuid('import_run_id')->nullable();
            $table->timestamps();

            $table->unique(['career_job_slug', 'locale', 'asset_version'], 'career_ai_impact_assets_slug_locale_version_unique');
            $table->index(['status', 'preview_allowlisted'], 'career_ai_impact_assets_status_preview_idx');
            $table->index('asset_version', 'career_ai_impact_assets_version_idx');
            $table->index('import_run_id', 'career_ai_impact_assets_import_run_idx');
            $table->index('occupation_id', 'career_ai_impact_assets_occupation_idx');

            $table->foreign('occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
