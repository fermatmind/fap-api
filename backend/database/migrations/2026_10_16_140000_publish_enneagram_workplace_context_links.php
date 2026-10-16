<?php

declare(strict_types=1);

use App\Services\Cms\EnneagramWorkplaceArticleRefresh;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The service owns its bilingual transaction; cache acknowledgements must
    // run after the real commit, with idempotent closeout after a failure.
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('articles')) {
            app(EnneagramWorkplaceArticleRefresh::class)->publishContextLinks();
        }
    }

    public function down(): void
    {
        // Existing readers remain compatible. Retain immutable published history.
    }
};
