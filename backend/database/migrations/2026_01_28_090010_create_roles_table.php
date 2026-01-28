<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 64)->unique();
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('roles', 'name')) {
                $table->string('name', 64)->nullable();
            }
            if (!Schema::hasColumn('roles', 'description')) {
                $table->string('description', 255)->nullable();
            }
            if (!Schema::hasColumn('roles', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('roles', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        try {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique('name', 'uniq_roles_name');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
