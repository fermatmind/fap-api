<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** @review-surface public_topic_edge */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_topic_edges')) {
            return;
        }

        Schema::create('public_topic_edges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_locale', 16);
            $table->string('relation_type', 64);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('target_locale', 16);
            $table->string('visible_label', 255);
            $table->text('context')->nullable();
            $table->unsignedInteger('position')->default(100);
            $table->boolean('active')->default(false);
            $table->boolean('proposed_active_state')->default(false);
            $table->boolean('publication_allowed')->default(false);
            $table->string('blocker', 64)->nullable();
            $table->string('review_state', 32)->default('draft');
            $table->json('evidence_refs')->nullable();
            $table->string('version', 64);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->boolean('target_publication_eligible')->default(false);
            $table->text('target_canonical');
            $table->timestamps();

            $table->unique(
                [
                    'org_id',
                    'source_type',
                    'source_id',
                    'source_locale',
                    'relation_type',
                    'target_type',
                    'target_id',
                    'target_locale',
                ],
                'public_topic_edges_identity_unique'
            );
            $table->index(
                ['org_id', 'source_type', 'source_id', 'source_locale', 'active', 'review_state'],
                'public_topic_edges_public_lookup'
            );
            $table->index(
                ['target_type', 'target_id', 'target_locale'],
                'public_topic_edges_target_lookup'
            );
        });
    }

    public function down(): void
    {
        // Forward-only authority table: public edge audit history must not be dropped automatically.
    }
};
