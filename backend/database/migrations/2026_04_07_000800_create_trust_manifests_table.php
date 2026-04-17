<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trust_manifests')) {
            return;
        }

        Schema::create('trust_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('content_version', 64);
            $table->string('data_version', 64);
            $table->string('logic_version', 64);
            $table->json('locale_context');
            $table->json('methodology');
            $table->string('reviewer_status', 64);
            $table->string('reviewer_id', 128)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('ai_assistance')->nullable();
            $table->json('quality')->nullable();
            $table->timestamp('last_substantive_update_at')->nullable();
            $table->timestamp('next_review_due_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['occupation_id', 'created_at'], 'trust_manifests_occ_created_idx');
            $table->index(['reviewer_status', 'next_review_due_at'], 'trust_manifests_review_idx');

            $table->foreign('occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
