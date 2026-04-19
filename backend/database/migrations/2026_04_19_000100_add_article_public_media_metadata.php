<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'author_name')) {
                $table->string('author_name', 128)->nullable();
            }
            if (! Schema::hasColumn('articles', 'reviewer_name')) {
                $table->string('reviewer_name', 128)->nullable();
            }
            if (! Schema::hasColumn('articles', 'reading_minutes')) {
                $table->unsignedSmallInteger('reading_minutes')->nullable();
            }
            if (! Schema::hasColumn('articles', 'cover_image_alt')) {
                $table->string('cover_image_alt', 255)->nullable();
            }
            if (! Schema::hasColumn('articles', 'cover_image_width')) {
                $table->unsignedInteger('cover_image_width')->nullable();
            }
            if (! Schema::hasColumn('articles', 'cover_image_height')) {
                $table->unsignedInteger('cover_image_height')->nullable();
            }
            if (! Schema::hasColumn('articles', 'cover_image_variants')) {
                $table->json('cover_image_variants')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
