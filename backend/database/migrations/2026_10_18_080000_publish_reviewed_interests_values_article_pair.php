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
            // The existing Media Library contract writes only on the production runner.
            app(CareerInterestsValuesArticleRelease::class)->run(app()->environment('staging'));
        }
    }

    public function down(): void
    {
        // Additive CMS publication: preserve reviewed content and revision history.
    }
};
