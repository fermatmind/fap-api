<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'admin_approvals';

    private const ORG_STATUS_CREATED_INDEX = 'admin_approvals_org_status_created_idx';

    private const CORRELATION_INDEX = 'admin_approvals_correlation_id_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('type', 64);
                $table->string('status', 24)->default('PENDING');
                $table->unsignedBigInteger('requested_by_admin_user_id')->nullable();
                $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
                $table->text('reason');
                $table->json('payload_json')->nullable();
                $table->uuid('correlation_id');
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->string('error_message', 255)->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamps();

                $table->index(['org_id', 'status', 'created_at'], self::ORG_STATUS_CREATED_INDEX);
                $table->index('correlation_id', self::CORRELATION_INDEX);
                $table->index('type', 'admin_approvals_type_idx');
                $table->index('requested_by_admin_user_id', 'admin_approvals_requested_by_idx');
                $table->index('approved_by_admin_user_id', 'admin_approvals_approved_by_idx');
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'type')) {
                $table->string('type', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'status')) {
                $table->string('status', 24)->default('PENDING');
            }
            if (! Schema::hasColumn(self::TABLE, 'requested_by_admin_user_id')) {
                $table->unsignedBigInteger('requested_by_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'approved_by_admin_user_id')) {
                $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'reason')) {
                $table->text('reason')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'payload_json')) {
                $table->json('payload_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'correlation_id')) {
                $table->uuid('correlation_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'executed_at')) {
                $table->timestamp('executed_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'error_code')) {
                $table->string('error_code', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'error_message')) {
                $table->string('error_message', 255)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (
            Schema::hasColumn(self::TABLE, 'org_id')
            && Schema::hasColumn(self::TABLE, 'status')
            && Schema::hasColumn(self::TABLE, 'created_at')
            && ! SchemaIndex::indexExists(self::TABLE, self::ORG_STATUS_CREATED_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id', 'status', 'created_at'], self::ORG_STATUS_CREATED_INDEX);
            });
        }

        if (
            Schema::hasColumn(self::TABLE, 'correlation_id')
            && ! SchemaIndex::indexExists(self::TABLE, self::CORRELATION_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('correlation_id', self::CORRELATION_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
