<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('slug', 127);
            $table->string('name', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['org_id', 'slug'], 'article_tags_org_slug_unique');
            $table->unique(['org_id', 'name'], 'article_tags_org_name_unique');
            $table->index(['org_id', 'is_active'], 'article_tags_org_active_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
