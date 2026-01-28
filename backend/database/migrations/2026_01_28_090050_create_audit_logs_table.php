<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('actor_admin_id')->nullable();
                $table->string('action', 64);
                $table->string('target_type', 64)->nullable();
                $table->string('target_id', 64)->nullable();
                $table->json('meta_json')->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('request_id', 128)->nullable();
                $table->dateTime('created_at');
            });

            try {
                Schema::table('audit_logs', function (Blueprint $table) {
                    $table->index(['actor_admin_id', 'created_at'], 'idx_audit_actor_time');
                });
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('audit_logs', function (Blueprint $table) {
                    $table->index(['action', 'created_at'], 'idx_audit_action_time');
                });
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('audit_logs', function (Blueprint $table) {
                    $table->index(['target_type', 'target_id'], 'idx_audit_target');
                });
            } catch (\Throwable $e) {
            }
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('audit_logs', 'actor_admin_id')) {
                $table->unsignedBigInteger('actor_admin_id')->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'action')) {
                $table->string('action', 64)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'target_type')) {
                $table->string('target_type', 64)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'target_id')) {
                $table->string('target_id', 64)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'ip')) {
                $table->string('ip', 64)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->string('user_agent', 255)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'request_id')) {
                $table->string('request_id', 128)->nullable();
            }
            if (!Schema::hasColumn('audit_logs', 'created_at')) {
                $table->dateTime('created_at')->nullable();
            }
        });

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['actor_admin_id', 'created_at'], 'idx_audit_actor_time');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['action', 'created_at'], 'idx_audit_action_time');
            });
        } catch (\Throwable $e) {
        }
        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['target_type', 'target_id'], 'idx_audit_target');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
