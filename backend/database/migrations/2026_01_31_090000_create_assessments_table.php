<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assessments')) {
            return;
        }

        Schema::create('assessments', function (Blueprint $table) {
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

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
