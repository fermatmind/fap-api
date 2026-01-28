<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_insights')) {
            return;
        }

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('anon_id')->nullable();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('input_hash', 64)->index();
            $table->string('prompt_version', 64);
            $table->string('model', 64);
            $table->string('provider', 32);
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('status', 16)->index();
            $table->json('output_json')->nullable();
            $table->json('evidence_json')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_insights')) {
            return;
        }

        Schema::drop('ai_insights');
    }
};
