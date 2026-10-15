<?php

declare(strict_types=1);

use App\Services\Cms\EnneagramWorkplaceArticleRefresh;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('articles')) {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
        }
    }

    public function down(): void
    {
        // Existing readers can consume the new revisions; retain immutable editorial history.
    }
};
