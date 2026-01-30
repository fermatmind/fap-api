<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_flags')) {
            return;
        }

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique('feature_flags_key_unique');
            $table->json('rules_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
