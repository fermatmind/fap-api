<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('career_job_revisions', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_id');
            $table->unsignedInteger('revision_no');
            if ($isSqlite) {
                $table->text('snapshot_json');
            } else {
                $table->json('snapshot_json');
            }
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['job_id', 'revision_no'], 'uq_career_job_revision');
            $table->index(['job_id', 'created_at'], 'idx_career_job_revision_created');
            $table->foreign('job_id', 'fk_career_job_revision_job')
                ->references('id')
                ->on('career_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
