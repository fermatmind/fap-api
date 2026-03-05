<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_tag_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();

            $table->unique(['org_id', 'article_id', 'tag_id'], 'article_tag_map_org_article_tag_unique');
            $table->index(['org_id', 'tag_id', 'article_id'], 'article_tag_map_org_tag_article_idx');
            $table->index(['org_id', 'article_id'], 'article_tag_map_org_article_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
