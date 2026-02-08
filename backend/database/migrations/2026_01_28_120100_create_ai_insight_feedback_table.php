<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_insight_feedback')) {
            return;
        }

        Schema::create('ai_insight_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('insight_id');
            $table->integer('rating');
            $table->string('reason', 64)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['insight_id']);
        });
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::drop('ai_insight_feedback');
    }
};
