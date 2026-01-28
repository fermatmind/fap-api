<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('admin_user_id');
                $table->unique(['role_id', 'admin_user_id'], 'uniq_role_user');
                $table->index(['admin_user_id'], 'idx_role_user_admin');
                $table->index(['role_id'], 'idx_role_user_role');
            });
            return;
        }

        Schema::table('role_user', function (Blueprint $table) {
            if (!Schema::hasColumn('role_user', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable();
            }
            if (!Schema::hasColumn('role_user', 'admin_user_id')) {
                $table->unsignedBigInteger('admin_user_id')->nullable();
            }
        });

        try {
            Schema::table('role_user', function (Blueprint $table) {
                $table->unique(['role_id', 'admin_user_id'], 'uniq_role_user');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('role_user', function (Blueprint $table) {
                $table->index(['admin_user_id'], 'idx_role_user_admin');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('role_user', function (Blueprint $table) {
                $table->index(['role_id'], 'idx_role_user_role');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
