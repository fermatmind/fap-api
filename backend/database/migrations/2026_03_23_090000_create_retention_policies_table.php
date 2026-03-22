<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('retention_policies')) {
            return;
        }

        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64);
            $table->string('subject_scope', 64);
            $table->string('artifact_scope', 64);
            $table->unsignedInteger('archive_after_days')->nullable();
            $table->unsignedInteger('shrink_after_days')->nullable();
            $table->unsignedInteger('purge_after_days')->nullable();
            $table->string('delete_behavior', 64)->default('retain_catalog_only');
            $table->boolean('delete_remote_archive')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['code'], 'retention_policies_code_unique');
            $table->index(['active'], 'retention_policies_active_idx');
            $table->index(['subject_scope', 'artifact_scope'], 'retention_policies_scope_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
