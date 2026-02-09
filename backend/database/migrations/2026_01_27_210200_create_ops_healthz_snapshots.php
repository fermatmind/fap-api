<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'ops_healthz_snapshots';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('env', 32);
                $table->string('revision', 64);
                $table->unsignedTinyInteger('ok');
                $table->json('deps_json');
                $table->json('error_codes_json')->nullable();
                $table->dateTime('occurred_at');
                $table->timestamps();

                $table->index(['env', 'occurred_at'], 'idx_ops_healthz_env_time');
                $table->index(['env', 'ok', 'occurred_at'], 'idx_ops_healthz_ok');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'env')) {
                $table->string('env', 32)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'revision')) {
                $table->string('revision', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'ok')) {
                $table->unsignedTinyInteger('ok')->default(0);
            }
            if (!Schema::hasColumn($tableName, 'deps_json')) {
                $table->json('deps_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'error_codes_json')) {
                $table->json('error_codes_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'occurred_at')) {
                $table->dateTime('occurred_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'env')
            && Schema::hasColumn($tableName, 'occurred_at')
            && !SchemaIndex::indexExists($tableName, 'idx_ops_healthz_env_time')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['env', 'occurred_at'], 'idx_ops_healthz_env_time');
            });
        }

        if (Schema::hasColumn($tableName, 'env')
            && Schema::hasColumn($tableName, 'ok')
            && Schema::hasColumn($tableName, 'occurred_at')
            && !SchemaIndex::indexExists($tableName, 'idx_ops_healthz_ok')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['env', 'ok', 'occurred_at'], 'idx_ops_healthz_ok');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
