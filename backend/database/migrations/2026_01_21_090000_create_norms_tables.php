<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('norms_versions')) {
            Schema::create('norms_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('pack_id');
                $table->timestamp('window_start_at')->nullable();
                $table->timestamp('window_end_at')->nullable();
                $table->integer('sample_n');
                $table->string('rank_rule', 16);
                $table->string('status', 16);
                $table->timestamp('computed_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['pack_id', 'status'], 'idx_norms_versions_pack_status');
                $table->index('created_at', 'idx_norms_versions_created_at');
            });
        } else {
            Schema::table('norms_versions', function (Blueprint $table) {
                if (!Schema::hasColumn('norms_versions', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('norms_versions', 'pack_id')) {
                    $table->string('pack_id');
                }
                if (!Schema::hasColumn('norms_versions', 'window_start_at')) {
                    $table->timestamp('window_start_at')->nullable();
                }
                if (!Schema::hasColumn('norms_versions', 'window_end_at')) {
                    $table->timestamp('window_end_at')->nullable();
                }
                if (!Schema::hasColumn('norms_versions', 'sample_n')) {
                    $table->integer('sample_n');
                }
                if (!Schema::hasColumn('norms_versions', 'rank_rule')) {
                    $table->string('rank_rule', 16);
                }
                if (!Schema::hasColumn('norms_versions', 'status')) {
                    $table->string('status', 16);
                }
                if (!Schema::hasColumn('norms_versions', 'computed_at')) {
                    $table->timestamp('computed_at')->nullable();
                }
                if (!Schema::hasColumn('norms_versions', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('norms_table')) {
            Schema::create('norms_table', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('norms_version_id');
                $table->string('metric_key', 8);
                $table->integer('score_int');
                $table->integer('leq_count');
                $table->decimal('percentile', 8, 6);
                $table->timestamp('created_at')->nullable();

                $table->index(
                    ['norms_version_id', 'metric_key', 'score_int'],
                    'idx_norms_table_version_metric_score'
                );
            });
        } else {
            Schema::table('norms_table', function (Blueprint $table) {
                if (!Schema::hasColumn('norms_table', 'id')) {
                    $table->bigIncrements('id');
                }
                if (!Schema::hasColumn('norms_table', 'norms_version_id')) {
                    $table->uuid('norms_version_id');
                }
                if (!Schema::hasColumn('norms_table', 'metric_key')) {
                    $table->string('metric_key', 8);
                }
                if (!Schema::hasColumn('norms_table', 'score_int')) {
                    $table->integer('score_int');
                }
                if (!Schema::hasColumn('norms_table', 'leq_count')) {
                    $table->integer('leq_count');
                }
                if (!Schema::hasColumn('norms_table', 'percentile')) {
                    $table->decimal('percentile', 8, 6);
                }
                if (!Schema::hasColumn('norms_table', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('norms_table');
        Schema::dropIfExists('norms_versions');
    }
};
