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
        if ($schema->hasTable('seo_weekly_decision_receipts')) {
            return;
        }

        $schema->create('seo_weekly_decision_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receipt_id')->unique();
            $table->string('selection_revision', 80)->unique();
            $table->string('iso_week', 8);
            $table->char('release_sha', 40);
            $table->dateTime('scheduled_for');
            $table->unsignedTinyInteger('decision_count');
            $table->json('decision_card_ids_json');
            $table->json('decision_revision_ids_json');
            $table->json('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['iso_week', 'scheduled_for'], 'seo_weekly_receipts_week_time_idx');
            $table->index(['release_sha', 'scheduled_for'], 'seo_weekly_receipts_release_time_idx');
        });
    }

    public function down(): void
    {
        // Expand-only closeout evidence remains readable by previous releases.
    }
};
