<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('attempts', 'answers_json')) {
                $table->json('answers_json')->nullable();
            }

            if (!Schema::hasColumn('attempts', 'answers_hash')) {
                $table->string('answers_hash', 64)->nullable()->index();
            }

            if (!Schema::hasColumn('attempts', 'answers_storage_path')) {
                $table->string('answers_storage_path', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            if (Schema::hasColumn('attempts', 'answers_storage_path')) {
                $table->dropColumn('answers_storage_path');
            }

            if (Schema::hasColumn('attempts', 'answers_hash')) {
                $table->dropColumn('answers_hash');
            }

            if (Schema::hasColumn('attempts', 'answers_json')) {
                $table->dropColumn('answers_json');
            }
        });
    }
};