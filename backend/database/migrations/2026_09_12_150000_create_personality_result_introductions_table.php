<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_result_introductions', function (Blueprint $table): void {
            $table->id();
            $table->string('full_code', 6);
            $table->string('locale', 10);
            $table->json('paragraphs');
            $table->char('content_hash', 64);
            $table->unsignedInteger('revision');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['full_code', 'locale'], 'personality_result_intro_identity_unique');
        });
    }

    public function down(): void
    {
        // Forward-only: retain published editorial data when application code rolls back.
    }
};
