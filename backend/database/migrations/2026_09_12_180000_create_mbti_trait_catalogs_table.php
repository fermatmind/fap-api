<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mbti_trait_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10)->unique();
            $table->longText('document');
            $table->string('content_hash', 64);
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Forward-only: retain published content if application code is rolled back.
    }
};
