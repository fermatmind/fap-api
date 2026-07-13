<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('personality_public_content_assets')
            || Schema::hasColumn('personality_public_content_assets', 'authority_json')
        ) {
            return;
        }

        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            $table->json('authority_json')->nullable()->after('evidence_notes_json');
        });
    }

    public function down(): void
    {
        // Forward-only authority migration: rollback is handled by a reviewed follow-up migration.
        // Existing public content authority data must never be dropped by an automated rollback.
    }
};
