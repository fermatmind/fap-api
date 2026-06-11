<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'sitemap_eligible')) {
                $table->boolean('sitemap_eligible')->default(false)->after('is_indexable');
            }

            if (! Schema::hasColumn('articles', 'llms_eligible')) {
                $table->boolean('llms_eligible')->default(false)->after('sitemap_eligible');
            }
        });

        DB::table('articles')
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->update([
                'sitemap_eligible' => true,
                'llms_eligible' => true,
            ]);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent SEO exposure drift.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
