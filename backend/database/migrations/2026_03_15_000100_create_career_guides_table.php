<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('career_guides', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('guide_code', 96);
            $table->string('slug', 128);
            $table->string('locale', 16);
            $table->string('title', 255);
            $table->text('excerpt')->nullable();
            $table->string('category_slug', 128)->nullable();
            $table->longText('body_md')->nullable();
            $table->longText('body_html')->nullable();

            if ($isSqlite) {
                $table->text('related_industry_slugs_json')->nullable();
            } else {
                $table->json('related_industry_slugs_json')->nullable();
            }

            $table->string('status', 32)->default('draft');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('schema_version', 32)->default('v1');
            $table->timestamps();

            $table->unique(['org_id', 'slug', 'locale'], 'uq_career_guide_slug');
            $table->index(['org_id', 'guide_code', 'locale'], 'idx_career_guide_code');
            $table->index(['org_id', 'status', 'is_public', 'is_indexable'], 'idx_career_guide_visibility');
            $table->index(['locale'], 'idx_career_guide_locale');
            $table->index(['published_at'], 'idx_career_guide_published_at');
            $table->index(['category_slug'], 'idx_career_guide_category');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
