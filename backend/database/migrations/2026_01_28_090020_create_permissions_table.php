<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 128)->unique();
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('permissions', 'name')) {
                $table->string('name', 128)->nullable();
            }
            if (!Schema::hasColumn('permissions', 'description')) {
                $table->string('description', 255)->nullable();
            }
            if (!Schema::hasColumn('permissions', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('permissions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        try {
            Schema::table('permissions', function (Blueprint $table) {
                $table->unique('name', 'uniq_permissions_name');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
