<?php

declare(strict_types=1);

use App\Services\Cms\MbtiReportArticleRelease;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('articles')) {
            app(MbtiReportArticleRelease::class)->publish();
        }
    }

    public function down(): void
    {
        // Preserve immutable CMS history and compatibility with both body versions.
    }
};
