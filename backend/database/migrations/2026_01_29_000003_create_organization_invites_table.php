<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'organization_invites';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id');
                $table->string('email', 255);
                $table->string('token', 128)->unique('organization_invites_token_unique');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->index('org_id', 'organization_invites_org_id_idx');
                $table->index('email', 'organization_invites_email_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'org_id')) {
                $table->unsignedBigInteger('org_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'email')) {
                $table->string('email', 255)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'token')) {
                $table->string('token', 128)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'token')
            && !SchemaIndex::indexExists($tableName, 'organization_invites_token_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('token', 'organization_invites_token_unique');
            });
        }

        if (Schema::hasColumn($tableName, 'org_id')
            && !SchemaIndex::indexExists($tableName, 'organization_invites_org_id_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('org_id', 'organization_invites_org_id_idx');
            });
        }

        if (Schema::hasColumn($tableName, 'email')
            && !SchemaIndex::indexExists($tableName, 'organization_invites_email_idx')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('email', 'organization_invites_email_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
