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
            $this->convergeExistingTable();

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
            $table->boolean('cross_locale_approved')->default(false);
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
            $table->text('source_canonical');
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

    private function convergeExistingTable(): void
    {
        Schema::table('public_topic_edges', function (Blueprint $table): void {
            $columns = [
                'org_id' => fn () => $table->unsignedBigInteger('org_id')->default(0),
                'source_type' => fn () => $table->string('source_type', 64),
                'source_id' => fn () => $table->unsignedBigInteger('source_id'),
                'source_locale' => fn () => $table->string('source_locale', 16),
                'relation_type' => fn () => $table->string('relation_type', 64),
                'target_type' => fn () => $table->string('target_type', 64),
                'target_id' => fn () => $table->unsignedBigInteger('target_id'),
                'target_locale' => fn () => $table->string('target_locale', 16),
                'cross_locale_approved' => fn () => $table->boolean('cross_locale_approved')->default(false),
                'visible_label' => fn () => $table->string('visible_label', 255),
                'context' => fn () => $table->text('context')->nullable(),
                'position' => fn () => $table->unsignedInteger('position')->default(100),
                'active' => fn () => $table->boolean('active')->default(false),
                'proposed_active_state' => fn () => $table->boolean('proposed_active_state')->default(false),
                'publication_allowed' => fn () => $table->boolean('publication_allowed')->default(false),
                'blocker' => fn () => $table->string('blocker', 64)->nullable(),
                'review_state' => fn () => $table->string('review_state', 32)->default('draft'),
                'evidence_refs' => fn () => $table->json('evidence_refs')->nullable(),
                'version' => fn () => $table->string('version', 64),
                'valid_from' => fn () => $table->timestamp('valid_from')->nullable(),
                'valid_until' => fn () => $table->timestamp('valid_until')->nullable(),
                'created_by_admin_user_id' => fn () => $table->unsignedBigInteger('created_by_admin_user_id')->nullable(),
                'updated_by_admin_user_id' => fn () => $table->unsignedBigInteger('updated_by_admin_user_id')->nullable(),
                'source_canonical' => fn () => $table->text('source_canonical'),
                'target_publication_eligible' => fn () => $table->boolean('target_publication_eligible')->default(false),
                'target_canonical' => fn () => $table->text('target_canonical'),
                'created_at' => fn () => $table->timestamp('created_at')->nullable(),
                'updated_at' => fn () => $table->timestamp('updated_at')->nullable(),
            ];

            foreach ($columns as $column => $addColumn) {
                if (! Schema::hasColumn('public_topic_edges', $column)) {
                    $addColumn();
                }
            }
        });

        if (! Schema::hasIndex('public_topic_edges', 'public_topic_edges_identity_unique')) {
            Schema::table('public_topic_edges', function (Blueprint $table): void {
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
            });
        }
        if (! Schema::hasIndex('public_topic_edges', 'public_topic_edges_public_lookup')) {
            Schema::table('public_topic_edges', function (Blueprint $table): void {
                $table->index(
                    ['org_id', 'source_type', 'source_id', 'source_locale', 'active', 'review_state'],
                    'public_topic_edges_public_lookup'
                );
            });
        }
        if (! Schema::hasIndex('public_topic_edges', 'public_topic_edges_target_lookup')) {
            Schema::table('public_topic_edges', function (Blueprint $table): void {
                $table->index(
                    ['target_type', 'target_id', 'target_locale'],
                    'public_topic_edges_target_lookup'
                );
            });
        }
    }

    public function down(): void
    {
        // Forward-only authority table: public edge audit history must not be dropped automatically.
    }
};
