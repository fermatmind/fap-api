<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'dsar_requests';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->unsignedBigInteger('subject_user_id');
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('executed_by_user_id')->nullable();
                $table->string('mode', 32)->default('hybrid_anonymize');
                $table->string('status', 24)->default('pending');
                $table->string('reason', 255)->nullable();
                $table->json('payload_json')->nullable();
                $table->json('result_json')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } else {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TABLE, 'id')) {
                    $table->uuid('id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'subject_user_id')) {
                    $table->unsignedBigInteger('subject_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'requested_by_user_id')) {
                    $table->unsignedBigInteger('requested_by_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'executed_by_user_id')) {
                    $table->unsignedBigInteger('executed_by_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'mode')) {
                    $table->string('mode', 32)->default('hybrid_anonymize');
                }
                if (! Schema::hasColumn(self::TABLE, 'status')) {
                    $table->string('status', 24)->default('pending');
                }
                if (! Schema::hasColumn(self::TABLE, 'reason')) {
                    $table->string('reason', 255)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'payload_json')) {
                    $table->json('payload_json')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'result_json')) {
                    $table->json('result_json')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'requested_at')) {
                    $table->timestamp('requested_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'executed_at')) {
                    $table->timestamp('executed_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureIndex(['org_id', 'status'], 'dsar_requests_org_status_idx');
        $this->ensureIndex(['org_id', 'subject_user_id'], 'dsar_requests_org_subject_idx');
        $this->ensureIndex(['requested_at'], 'dsar_requests_requested_at_idx');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
