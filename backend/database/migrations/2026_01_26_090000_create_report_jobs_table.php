<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('attempt_id')->unique();
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('tries')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->longText('last_error_trace')->nullable();
            $table->longText('report_json')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
