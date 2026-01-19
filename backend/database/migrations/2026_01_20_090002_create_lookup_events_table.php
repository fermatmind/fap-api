<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lookup_events')) {
            Schema::create('lookup_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('method', 64);
                $table->boolean('success');
                $table->string('user_id', 64)->nullable();
                $table->string('ip', 64)->nullable();
                $table->text('meta_json')->nullable();
                $table->string('request_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            return;
        }

        Schema::table('lookup_events', function (Blueprint $table) {
            if (!Schema::hasColumn('lookup_events', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('lookup_events', 'method')) {
                $table->string('method', 64);
            }
            if (!Schema::hasColumn('lookup_events', 'success')) {
                $table->boolean('success');
            }
            if (!Schema::hasColumn('lookup_events', 'user_id')) {
                $table->string('user_id', 64)->nullable();
            }
            if (!Schema::hasColumn('lookup_events', 'ip')) {
                $table->string('ip', 64)->nullable();
            }
            if (!Schema::hasColumn('lookup_events', 'meta_json')) {
                $table->text('meta_json')->nullable();
            }
            if (!Schema::hasColumn('lookup_events', 'request_id')) {
                $table->string('request_id', 64)->nullable();
            }
            if (!Schema::hasColumn('lookup_events', 'created_at')) {
                $table->timestamp('created_at')->nullable()->useCurrent();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_events');
    }
};
