<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('data_code', 96);
            $table->string('slug', 128);
            $table->string('locale', 16);
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->string('hero_kicker', 128)->nullable();
            $table->longText('body_md')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('sample_size_label', 64)->nullable();
            $table->string('time_window_label', 128)->nullable();
            $table->longText('methodology_md')->nullable();
            $table->longText('limitations_md')->nullable();
            $table->longText('summary_statement_md')->nullable();
            $table->text('cover_image_url')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('schema_version', 32)->default('v1');
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'data_code', 'locale'], 'uq_data_page');
            $table->unique(['org_id', 'slug', 'locale'], 'uq_data_page_slug');
            $table->index(['status', 'is_public', 'published_at'], 'idx_data_page_status');
            $table->index(['locale'], 'idx_data_page_locale');
            $table->index(['locale', 'sort_order'], 'idx_data_page_sort');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
