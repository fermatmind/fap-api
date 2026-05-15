<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_test_edges')) {
            return;
        }

        Schema::create('article_test_edges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0)->index('article_test_edges_org_idx');
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();
            $table->string('locale', 16)->index('article_test_edges_locale_idx');
            $table->string('test_slug', 127);
            $table->string('role', 32)->default('contextual');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->string('safety_level', 32)->default('normal');
            $table->string('visibility', 32)->default('public');
            $table->string('source', 64)->default('editorial_package');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'locale', 'test_slug'], 'article_test_edges_article_locale_test_unique');
            $table->index(['org_id', 'locale', 'test_slug', 'visibility'], 'article_test_edges_public_lookup_idx');
            $table->index(['article_id', 'visibility', 'sort_order'], 'article_test_edges_article_visible_idx');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Production rollback must preserve article graph data.
    }
};
