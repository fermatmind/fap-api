<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_agent_approval_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('framework', 32);
            $table->string('source_artifact', 160)->nullable();
            $table->string('source_artifact_path', 512)->nullable();
            $table->string('source_package_sha256', 64);
            $table->string('qa_artifact', 160)->nullable();
            $table->string('qa_artifact_path', 512)->nullable();
            $table->string('qa_sha256', 64);
            $table->string('status', 48)->default('pending_review');
            $table->unsignedInteger('planned_item_count')->default(0);
            $table->unsignedInteger('queued_item_count')->default(0);
            $table->unsignedInteger('blocked_item_count')->default(0);
            $table->json('safety_holds_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['framework', 'source_package_sha256', 'qa_sha256'],
                'uq_personality_agent_approval_batch_source'
            );
            $table->index(['framework', 'status'], 'idx_personality_agent_approval_batch_status');
        });

        Schema::create('personality_agent_approval_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->string('framework', 32);
            $table->string('target_url', 512);
            $table->string('path', 255);
            $table->string('locale', 16);
            $table->string('page_type', 64);
            $table->string('recommendation_id', 255)->nullable();
            $table->string('recommendation_sha256', 64);
            $table->string('qa_decision', 64);
            $table->string('approval_state', 32)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('blocked_reason', 255)->nullable();
            $table->json('safety_holds_json')->nullable();
            $table->json('recommendation_json');
            $table->json('qa_json')->nullable();
            $table->timestamps();

            $table->foreign('batch_id', 'fk_personality_agent_approval_items_batch')
                ->references('id')
                ->on('personality_agent_approval_batches')
                ->cascadeOnDelete();
            $table->unique(['batch_id', 'target_url'], 'uq_personality_agent_approval_item_target');
            $table->index(['framework', 'approval_state'], 'idx_personality_agent_approval_item_state');
            $table->index(['target_url'], 'idx_personality_agent_approval_item_url');
        });
    }

    public function down(): void
    {
        // Production safety: approval queue history is forward-only.
        // Schema/data rollback should use a forward fix migration.
    }
};
