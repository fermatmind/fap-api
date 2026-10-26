<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendship_progress', function (Blueprint $table) {
            $table->uuid('challenge_id');
            $table->foreign('challenge_id')->references('id')->on('friendship_challenges')->cascadeOnDelete();
            $table->string('player_hash', 64);
            $table->string('nickname', 32);
            $table->json('guesses');
            $table->dateTime('created_at');
            $table->primary(['challenge_id', 'player_hash']);
        });
    }

    // Older releases ignore progress; no existing quiz or completed entry is changed.
    public function down(): void {}
};
