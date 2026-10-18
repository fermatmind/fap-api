<?php

declare(strict_types=1);

use App\Services\Cms\CareerInterestsValuesArticleRelease;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! app()->environment(['local', 'testing']) && Schema::hasTable('articles')) {
            app(CareerInterestsValuesArticleRelease::class)->run();
        }
    }

    public function down(): void
    {
        // Additive CMS publication: preserve reviewed content and revision history.
    }
};
