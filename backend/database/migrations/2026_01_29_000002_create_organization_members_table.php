<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'organization_members';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 32);
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['org_id', 'user_id'], 'organization_members_org_user_unique');
                $table->index('org_id', 'organization_members_org_id_idx');
                $table->index('user_id', 'organization_members_user_id_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'org_id')) {
                $table->unsignedBigInteger('org_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'role')) {
                $table->string('role', 32)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'joined_at')) {
                $table->timestamp('joined_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'org_id')
            && Schema::hasColumn($tableName, 'user_id')
            && !SchemaIndex::indexExists($tableName, 'organization_members_org_user_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique(['org_id', 'user_id'], 'organization_members_org_user_unique');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && !SchemaIndex::indexExists($tableName, 'organization_members_org_id_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('org_id', 'organization_members_org_id_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'user_id')
            && !SchemaIndex::indexExists($tableName, 'organization_members_user_id_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('user_id', 'organization_members_user_id_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
