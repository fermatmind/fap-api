<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_editorial_package_imports')) {
            return;
        }

        Schema::create('article_editorial_package_imports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('slug', 127);
            $table->string('locale', 16);
            $table->string('title', 255)->nullable();
            $table->string('content_track', 64)->nullable();
            $table->string('status', 32);
            $table->string('intended_status', 32)->nullable();
            $table->json('validation_summary_json')->nullable();
            $table->json('claim_result_json')->nullable();
            $table->json('exactness_json')->nullable();
            $table->json('references_json')->nullable();
            $table->json('media_json')->nullable();
            $table->json('graph_json')->nullable();
            $table->json('answer_surface_json')->nullable();
            $table->string('body_hash', 64)->nullable();
            $table->json('heading_sequence_json')->nullable();
            $table->unsignedInteger('references_count')->default(0);
            $table->json('missing_fields_json')->nullable();
            $table->json('blocked_reasons_json')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'locale', 'status'], 'article_pkg_imports_org_locale_status_idx');
            $table->index(['org_id', 'slug', 'locale'], 'article_pkg_imports_slug_locale_idx');
            $table->index('article_id', 'article_pkg_imports_article_idx');
            $table->index('created_at', 'article_pkg_imports_created_idx');
        });
    }

    public function down(): void
    {
        // Forward-only operational telemetry table. Use a follow-up migration to retire it safely.
    }
};
