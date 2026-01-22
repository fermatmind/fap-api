<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('benefit_grants')) {
            Schema::create('benefit_grants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('user_id', 64)->index();
                $table->string('benefit_type', 64);
                $table->string('benefit_ref', 128);
                $table->uuid('source_order_id');
                $table->uuid('source_event_id')->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['source_order_id', 'benefit_type', 'benefit_ref'], 'uq_benefit_grants_source');
            });
            return;
        }

        Schema::table('benefit_grants', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_grants', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('benefit_grants', 'user_id')) {
                $table->string('user_id', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('benefit_grants', 'benefit_type')) {
                $table->string('benefit_type', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'benefit_ref')) {
                $table->string('benefit_ref', 128)->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'source_order_id')) {
                $table->uuid('source_order_id')->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'source_event_id')) {
                $table->uuid('source_event_id')->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'status')) {
                $table->string('status', 32)->default('active')->index();
            }
            if (!Schema::hasColumn('benefit_grants', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_grants');
    }
};
