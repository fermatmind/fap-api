<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('research_reports')) {
            return;
        }

        Schema::create('research_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('slug', 128);
            $table->string('locale', 16)->default('en');
            $table->string('title', 255);
            $table->text('executive_summary')->nullable();
            $table->longText('body_md')->nullable();
            $table->string('research_type', 64);
            $table->longText('methodology')->nullable();
            $table->text('sample_disclaimer')->nullable();
            $table->text('claim_boundary')->nullable();
            $table->string('author_name', 128)->nullable();
            $table->string('reviewer_name', 128)->nullable();
            $table->json('references')->nullable();
            $table->string('downloadable_asset_placeholder', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('review_state', 64)->default('draft');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_indexable')->default(false);
            $table->dateTime('last_reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_path', 255)->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'slug', 'locale'], 'uq_research_reports_slug_locale');
            $table->index(['org_id', 'locale', 'status', 'is_indexable'], 'idx_research_reports_public_read');
            $table->index(['org_id', 'research_type', 'review_state'], 'idx_research_reports_review_scope');
        });
    }

    public function down(): void
    {
        // Forward-only migration: destructive rollback is intentionally disabled.
    }
};
