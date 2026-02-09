<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assessment_assignments')) {
            return;
        }

        Schema::create('assessment_assignments', function (Blueprint $table) {
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

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
