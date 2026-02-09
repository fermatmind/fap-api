<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'ai_insight_feedback';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('insight_id');
                $table->integer('rating');
                $table->string('reason', 64)->nullable();
                $table->text('comment')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['insight_id'], 'ai_insight_feedback_insight_id_index');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'insight_id')) {
                $table->uuid('insight_id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'rating')) {
                $table->integer('rating')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'reason')) {
                $table->string('reason', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'comment')) {
                $table->text('comment')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable()->useCurrent();
            }
        });

        if (Schema::hasColumn($tableName, 'insight_id')
            && !SchemaIndex::indexExists($tableName, 'ai_insight_feedback_insight_id_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['insight_id'], 'ai_insight_feedback_insight_id_index');
            });
        }
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::drop('ai_insight_feedback');
    }
};
