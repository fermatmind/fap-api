<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_deploy_events')) {
            return;
        }

        Schema::create('ops_deploy_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('env', 32);
            $table->string('revision', 64);
            $table->string('status', 32);
            $table->string('actor', 64)->nullable();
            $table->json('meta_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['env', 'occurred_at'], 'idx_ops_deploy_events_env_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_deploy_events');
    }
};
