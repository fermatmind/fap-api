<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_content_runtime_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('route_family', 64);
            $table->string('priority', 2);
            $table->string('locale', 16);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('success_count')->default(0);
            $table->unsignedBigInteger('not_found_count')->default(0);
            $table->unsignedBigInteger('rate_limited_count')->default(0);
            $table->unsignedBigInteger('client_error_count')->default(0);
            $table->unsignedBigInteger('server_error_count')->default(0);
            $table->unsignedBigInteger('timeout_count')->default(0);
            $table->unsignedBigInteger('duration_count')->default(0);
            $table->double('duration_sum_ms')->default(0);
            $table->double('duration_max_ms')->default(0);
            $table->json('duration_histogram');
            $table->json('rolled_minutes');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['day', 'route_family', 'locale'],
                'public_content_runtime_daily_scope_unique'
            );
            $table->index(
                ['route_family', 'locale', 'day'],
                'public_content_runtime_daily_query_idx'
            );
            $table->index(['priority', 'day'], 'public_content_runtime_daily_priority_idx');
        });
    }

    public function down(): void
    {
        // Forward-only migration: retain aggregate operational history.
        // Schema rollback must use a separately reviewed forward fix migration.
    }
};
