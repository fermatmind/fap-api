<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'editorial_reviews';

    private const CONTENT_UNIQUE = 'editorial_reviews_content_unique';

    private const ORG_STATE_UPDATED_INDEX = 'editorial_reviews_org_state_updated_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('content_type', 32);
                $table->unsignedBigInteger('content_id');
                $table->string('workflow_state', 32)->default('drafting');
                $table->unsignedBigInteger('owner_admin_user_id')->nullable();
                $table->unsignedBigInteger('reviewer_admin_user_id')->nullable();
                $table->unsignedBigInteger('submitted_by_admin_user_id')->nullable();
                $table->unsignedBigInteger('reviewed_by_admin_user_id')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('last_transition_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(['content_type', 'content_id'], self::CONTENT_UNIQUE);
                $table->index(['org_id', 'workflow_state', 'updated_at'], self::ORG_STATE_UPDATED_INDEX);
                $table->index('owner_admin_user_id', 'editorial_reviews_owner_idx');
                $table->index('reviewer_admin_user_id', 'editorial_reviews_reviewer_idx');
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'content_type')) {
                $table->string('content_type', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'content_id')) {
                $table->unsignedBigInteger('content_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'workflow_state')) {
                $table->string('workflow_state', 32)->default('drafting');
            }
            if (! Schema::hasColumn(self::TABLE, 'owner_admin_user_id')) {
                $table->unsignedBigInteger('owner_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'reviewer_admin_user_id')) {
                $table->unsignedBigInteger('reviewer_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'submitted_by_admin_user_id')) {
                $table->unsignedBigInteger('submitted_by_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'reviewed_by_admin_user_id')) {
                $table->unsignedBigInteger('reviewed_by_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'last_transition_at')) {
                $table->timestamp('last_transition_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'note')) {
                $table->string('note', 255)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (
            Schema::hasColumn(self::TABLE, 'content_type')
            && Schema::hasColumn(self::TABLE, 'content_id')
            && ! SchemaIndex::indexExists(self::TABLE, self::CONTENT_UNIQUE)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['content_type', 'content_id'], self::CONTENT_UNIQUE);
            });
        }

        if (
            Schema::hasColumn(self::TABLE, 'org_id')
            && Schema::hasColumn(self::TABLE, 'workflow_state')
            && Schema::hasColumn(self::TABLE, 'updated_at')
            && ! SchemaIndex::indexExists(self::TABLE, self::ORG_STATE_UPDATED_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id', 'workflow_state', 'updated_at'], self::ORG_STATE_UPDATED_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
    }
};
