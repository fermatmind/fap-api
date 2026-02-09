<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'assessments';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id');
                $table->string('scale_code', 64);
                $table->string('title', 255);
                $table->unsignedBigInteger('created_by');
                $table->timestamp('due_at')->nullable();
                $table->string('status', 32)->default('open');
                $table->timestamps();

                $table->index(['org_id', 'created_at'], 'assessments_org_created_idx');
                $table->index(['org_id', 'status'], 'assessments_org_status_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'org_id')) {
                $table->unsignedBigInteger('org_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'scale_code')) {
                $table->string('scale_code', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'title')) {
                $table->string('title', 255)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'due_at')) {
                $table->timestamp('due_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'status')) {
                $table->string('status', 32)->default('open');
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'created_at')
            && !SchemaIndex::indexExists($tableName, 'assessments_org_created_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'created_at'], 'assessments_org_created_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'status')
            && !SchemaIndex::indexExists($tableName, 'assessments_org_status_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['org_id', 'status'], 'assessments_org_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
