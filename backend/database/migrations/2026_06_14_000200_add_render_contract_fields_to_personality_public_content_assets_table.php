<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            $table->string('robots', 32)->default('noindex,follow')->after('seo_json');
            $table->json('internal_links_json')->nullable()->after('evidence_notes_json');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content authority loss.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
