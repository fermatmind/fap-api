<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('author_admin_user_id')->nullable();
            $table->string('slug', 127);
            $table->string('locale', 16)->default('en');
            $table->string('title', 255);
            $table->text('excerpt')->nullable();
            $table->longText('content_md');
            $table->longText('content_html')->nullable();
            $table->string('cover_image_url', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['org_id', 'locale', 'slug'], 'articles_org_locale_slug_unique');
            $table->index(['org_id', 'status', 'published_at'], 'articles_org_status_published_idx');
            $table->index(['org_id', 'category_id', 'status'], 'articles_org_category_status_idx');
            $table->index(['org_id', 'updated_at'], 'articles_org_updated_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
