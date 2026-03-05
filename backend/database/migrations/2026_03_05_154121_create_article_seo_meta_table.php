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

        Schema::create('article_seo_meta', function (Blueprint $table) use ($isSqlite) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('article_id');
            $table->string('locale', 16)->default('en');
            $table->string('seo_title', 60)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->string('og_title', 90)->nullable();
            $table->string('og_description', 200)->nullable();
            $table->string('og_image_url', 255)->nullable();
            $table->string('robots', 64)->nullable();
            if ($isSqlite) {
                $table->text('schema_json')->nullable();
            } else {
                $table->json('schema_json')->nullable();
            }
            $table->boolean('is_indexable')->default(true);
            $table->timestamps();

            $table->unique(['org_id', 'article_id', 'locale'], 'article_seo_meta_org_article_locale_unique');
            $table->index(['org_id', 'locale', 'is_indexable'], 'article_seo_meta_org_locale_indexable_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
