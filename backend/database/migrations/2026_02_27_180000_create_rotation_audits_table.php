<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'rotation_audits';

    public function up(): void
    {
        $this->createOrConvergeTable();
        $this->ensureIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeTable(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('actor', 64)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('scope', 64)->default('pii');
                $table->unsignedSmallInteger('key_version')->default(1);
                $table->string('batch_ref', 64)->nullable();
                $table->string('result', 32)->default('ok');
                $table->json('meta_json')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'actor')) {
                $table->string('actor', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'scope')) {
                $table->string('scope', 64)->default('pii');
            }
            if (! Schema::hasColumn(self::TABLE, 'key_version')) {
                $table->unsignedSmallInteger('key_version')->default(1);
            }
            if (! Schema::hasColumn(self::TABLE, 'batch_ref')) {
                $table->string('batch_ref', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'result')) {
                $table->string('result', 32)->default('ok');
            }
            if (! Schema::hasColumn(self::TABLE, 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $this->ensureIndex(['org_id', 'created_at'], 'rotation_audits_org_created_idx');
        $this->ensureIndex(['org_id', 'scope', 'created_at'], 'rotation_audits_org_scope_created_idx');
        $this->ensureIndex(['org_id', 'key_version', 'created_at'], 'rotation_audits_org_key_version_created_idx');
        $this->ensureIndex(['org_id', 'result', 'created_at'], 'rotation_audits_org_result_created_idx');
        $this->ensureIndex(['batch_ref'], 'rotation_audits_batch_ref_idx');
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE) || SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
