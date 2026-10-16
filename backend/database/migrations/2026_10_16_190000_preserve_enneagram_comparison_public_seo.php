<?php

declare(strict_types=1);

use App\Services\Cms\EnneagramComparisonNavigationRefresh;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('articles')) {
            app(EnneagramComparisonNavigationRefresh::class)->publish();
        }
    }

    public function down(): void
    {
        // Keep native publication history; no published revision is overwritten.
    }
};
