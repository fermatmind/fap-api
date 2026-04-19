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
            if (! Schema::hasColumn('articles', 'related_test_slug')) {
                $table->string('related_test_slug', 127)->nullable()->index('articles_related_test_slug_idx');
            }
            if (! Schema::hasColumn('articles', 'voice')) {
                $table->string('voice', 32)->nullable();
            }
            if (! Schema::hasColumn('articles', 'voice_order')) {
                $table->unsignedSmallInteger('voice_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
