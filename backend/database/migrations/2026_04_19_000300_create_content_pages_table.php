<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_pages')) {
            return;
        }

        Schema::create('content_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('slug', 128);
            $table->string('path', 160);
            $table->string('kind', 32)->default('company');
            $table->string('title', 255);
            $table->string('kicker', 96)->nullable();
            $table->text('summary')->nullable();
            $table->string('template', 64)->default('company');
            $table->string('animation_profile', 64)->default('none');
            $table->string('locale', 16);
            $table->date('published_at')->nullable();
            $table->date('source_updated_at')->nullable();
            $table->date('effective_at')->nullable();
            $table->string('source_doc', 255)->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->json('headings_json')->nullable();
            $table->longText('content_md')->nullable();
            $table->longText('content_html')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('status', 32)->default('published');
            $table->timestamps();

            $table->unique(['org_id', 'slug', 'locale'], 'uq_content_pages_slug_locale');
            $table->index(['org_id', 'locale', 'kind'], 'idx_content_pages_kind');
            $table->index(['org_id', 'status', 'is_public', 'is_indexable'], 'idx_content_pages_visibility');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content loss in production.
    }
};
