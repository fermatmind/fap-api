<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendship_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_hash', 64);
            $table->string('nickname', 32);
            $table->string('locale', 5);
            $table->string('version', 40);
            $table->json('questions');
            $table->json('answers');
            $table->string('payload_hash', 64);
            $table->boolean('closed')->default(false);
            $table->boolean('contribute')->default(false);
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
        });
        Schema::create('friendship_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('challenge_id');
            $table->foreign('challenge_id')->references('id')->on('friendship_challenges')->cascadeOnDelete();
            $table->string('player_hash', 64);
            $table->string('nickname', 32);
            $table->json('guesses');
            $table->unsignedSmallInteger('correct');
            $table->json('categories');
            $table->boolean('hidden')->default(false);
            $table->timestamp('created_at');
            $table->unique(['challenge_id', 'player_hash']);
        });
    }

    // Rollback is intentionally additive: older application releases safely ignore these tables.
    public function down(): void {}
};
