<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendship_browser_grants', function (Blueprint $table) {
            $table->char('session_hash', 64);
            $table->uuid('challenge_id')->index();
            $table->string('role', 6);
            $table->text('encrypted_key');
            $table->dateTime('expires_at')->index();
            $table->primary(['session_hash', 'challenge_id', 'role']);
        });
    }

    // Older releases ignore the additive table.
    public function down(): void {}
};
