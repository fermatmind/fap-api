<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->unique(['permission_id', 'role_id'], 'uniq_permission_role');
                $table->index(['role_id'], 'idx_permission_role_role');
                $table->index(['permission_id'], 'idx_permission_role_permission');
            });
            return;
        }

        Schema::table('permission_role', function (Blueprint $table) {
            if (!Schema::hasColumn('permission_role', 'permission_id')) {
                $table->unsignedBigInteger('permission_id')->nullable();
            }
            if (!Schema::hasColumn('permission_role', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable();
            }
        });

        try {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->unique(['permission_id', 'role_id'], 'uniq_permission_role');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->index(['role_id'], 'idx_permission_role_role');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->index(['permission_id'], 'idx_permission_role_permission');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
    }
};
