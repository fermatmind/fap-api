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
        if (app()->environment('production') && Schema::hasTable('articles')) {
            app(CareerInterestsValuesArticleRelease::class)->releaseSeo();
        }
    }

    public function down(): void
    {
        // Compatible CMS render-gate metadata remains readable by the LKG.
    }
};
