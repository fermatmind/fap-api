<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('career_job_display_assets')) {
            return;
        }

        Schema::create('career_job_display_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('canonical_slug', 160);
            $table->string('surface_version', 64)->default('display.surface.v1');
            $table->string('asset_version', 64);
            $table->string('template_version', 64);
            $table->string('asset_type', 96);
            $table->string('asset_role', 96);
            $table->string('status', 64);
            $table->json('component_order_json');
            $table->json('page_payload_json');
            $table->json('seo_payload_json')->nullable();
            $table->json('sources_json')->nullable();
            $table->json('structured_data_json')->nullable();
            $table->json('implementation_contract_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->uuid('import_run_id')->nullable();
            $table->timestamps();

            $table->unique(['canonical_slug', 'asset_version'], 'career_job_display_assets_slug_version_unique');
            $table->index('occupation_id', 'career_job_display_assets_occupation_idx');
            $table->index('canonical_slug', 'career_job_display_assets_slug_idx');
            $table->index('status', 'career_job_display_assets_status_idx');

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
