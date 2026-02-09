<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'experiment_assignments';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('anon_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('experiment_key');
                $table->string('variant');
                $table->timestamp('assigned_at');
                $table->timestamps();

                $table->unique(['org_id', 'anon_id', 'experiment_key'], 'experiment_assignments_org_anon_experiment_unique');
                $table->index(['org_id', 'user_id', 'experiment_key'], 'experiment_assignments_org_user_experiment_idx');
                $table->index(['org_id', 'experiment_key'], 'experiment_assignments_org_experiment_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn($tableName, 'anon_id')) {
                $table->string('anon_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'experiment_key')) {
                $table->string('experiment_key')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'variant')) {
                $table->string('variant')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'anon_id')
            && Schema::hasColumn($tableName, 'experiment_key')
            && !SchemaIndex::indexExists($tableName, 'experiment_assignments_org_anon_experiment_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique(['org_id', 'anon_id', 'experiment_key'], 'experiment_assignments_org_anon_experiment_unique');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'user_id')
            && Schema::hasColumn($tableName, 'experiment_key')
            && !SchemaIndex::indexExists($tableName, 'experiment_assignments_org_user_experiment_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'user_id', 'experiment_key'], 'experiment_assignments_org_user_experiment_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'experiment_key')
            && !SchemaIndex::indexExists($tableName, 'experiment_assignments_org_experiment_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'experiment_key'], 'experiment_assignments_org_experiment_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
