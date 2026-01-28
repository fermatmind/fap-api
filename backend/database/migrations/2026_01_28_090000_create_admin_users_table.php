<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 64);
                $table->string('email', 191)->unique();
                $table->string('password', 255);
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('last_login_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_users', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('admin_users', 'name')) {
                $table->string('name', 64)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'email')) {
                $table->string('email', 191)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'password')) {
                $table->string('password', 255)->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'is_active')) {
                $table->tinyInteger('is_active')->default(1);
            }
            if (!Schema::hasColumn('admin_users', 'last_login_at')) {
                $table->dateTime('last_login_at')->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'remember_token')) {
                $table->rememberToken();
            }
            if (!Schema::hasColumn('admin_users', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('admin_users', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        try {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->unique('email', 'uniq_admin_users_email');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
