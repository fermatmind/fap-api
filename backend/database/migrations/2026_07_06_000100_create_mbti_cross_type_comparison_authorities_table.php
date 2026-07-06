<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mbti_cross_type_comparison_authorities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('locale', 16);
            $table->string('slug', 64);
            $table->string('comparison_type', 32)->default('mbti_cross_type');
            $table->string('left_type_code', 8);
            $table->string('right_type_code', 8);
            $table->string('title', 255);
            $table->string('seo_title', 255);
            $table->text('seo_description');
            $table->text('summary');
            $table->json('content_payload_json');
            $table->string('claim_boundary', 512)->nullable();
            $table->string('source_package_id', 160)->nullable();
            $table->string('source_sha256', 64)->nullable();
            $table->string('authority_contract_version', 96)->default('mbti.cross_type_comparison.authority.v1');
            $table->string('readmodel_contract_version', 96)->default('mbti.cross_type_comparison.readmodel.v1');
            $table->string('review_status', 32)->default('draft');
            $table->string('publish_status', 32)->default('draft');
            $table->string('indexability_status', 48)->default('held_for_indexability_gate');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_indexable')->default(false);
            $table->boolean('sitemap_eligible')->default(false);
            $table->boolean('llms_eligible')->default(false);
            $table->boolean('search_submission_eligible')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'locale', 'slug'], 'uq_mbti_cross_type_authority_slug');
            $table->index(['locale', 'is_public', 'publish_status'], 'idx_mbti_cross_type_authority_public');
            $table->index(
                ['is_indexable', 'sitemap_eligible', 'llms_eligible'],
                'idx_mbti_cross_type_authority_index_gate'
            );
        });
    }

    public function down(): void
    {
        // Forward-only authority migration: rollback should use a forward fix migration.
    }
};
