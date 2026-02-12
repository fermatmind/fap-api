<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'assessment_assignments';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id');
                $table->unsignedBigInteger('assessment_id');
                $table->string('subject_type', 16);
                $table->string('subject_value', 255);
                $table->string('invite_token', 64);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('attempt_id', 64)->nullable();
                $table->timestamps();

                $table->unique('invite_token', 'assessment_assignments_invite_unique');
                $table->index(['org_id', 'assessment_id'], 'assessment_assignments_org_assessment_idx');
                $table->index(['org_id', 'invite_token'], 'assessment_assignments_org_invite_idx');
                $table->index('attempt_id', 'assessment_assignments_attempt_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'org_id')) {
                $table->unsignedBigInteger('org_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'assessment_id')) {
                $table->unsignedBigInteger('assessment_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'subject_type')) {
                $table->string('subject_type', 16)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'subject_value')) {
                $table->string('subject_value', 255)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'invite_token')) {
                $table->string('invite_token', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'invite_token')
            && !SchemaIndex::indexExists($tableName, 'assessment_assignments_invite_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('invite_token', 'assessment_assignments_invite_unique');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'assessment_id')
            && !SchemaIndex::indexExists($tableName, 'assessment_assignments_org_assessment_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'assessment_id'], 'assessment_assignments_org_assessment_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'invite_token')
            && !SchemaIndex::indexExists($tableName, 'assessment_assignments_org_invite_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'invite_token'], 'assessment_assignments_org_invite_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'attempt_id')
            && !SchemaIndex::indexExists($tableName, 'assessment_assignments_attempt_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('attempt_id', 'assessment_assignments_attempt_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
