<?php

declare(strict_types=1);

use App\Services\Cms\EnneagramArticleRefresh;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('articles')) {
            app(EnneagramArticleRefresh::class)->publish();
        }
    }

    public function down(): void
    {
        // Native Article metadata and new immutable revisions remain readable by LKG code.
        // Do not reverse editorial publication during application rollback.
    }
};
