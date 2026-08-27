<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_weekly_decision_capability_receipts')) {
            return;
        }

        $schema->create('seo_weekly_decision_capability_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receipt_id')->unique();
            $table->string('selection_revision', 80);
            $table->char('capability_revision', 64);
            $table->string('iso_week', 8);
            $table->char('evidence_release_sha', 40);
            $table->dateTime('scheduled_for');
            $table->unsignedTinyInteger('decision_count');
            $table->json('decision_card_ids_json');
            $table->json('decision_revision_ids_json');
            $table->json('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['selection_revision', 'capability_revision'],
                'seo_weekly_receipts_selection_capability_uq',
            );
            $table->index(['iso_week', 'scheduled_for'], 'seo_weekly_capability_week_time_idx');
            $table->index(['capability_revision', 'scheduled_for'], 'seo_weekly_capability_revision_time_idx');
        });
    }

    public function down(): void
    {
        // Expand-only evidence remains readable by previous releases.
    }
};
