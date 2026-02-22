<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (! Schema::hasTable('big5_psychometrics_reports')) {
            Schema::create('big5_psychometrics_reports', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->string('scale_code', 32)->default('BIG5_OCEAN');
                $table->string('locale', 16)->default('zh-CN');
                $table->string('region', 32)->nullable();
                $table->string('norms_version', 64)->nullable();
                $table->string('time_window', 64)->default('last_90_days');
                $table->unsignedInteger('sample_n')->default(0);
                if ($isSqlite) {
                    $table->text('metrics_json')->nullable();
                } else {
                    $table->json('metrics_json')->nullable();
                }
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['scale_code', 'locale'], 'idx_big5_psy_scale_locale');
                $table->index(['generated_at'], 'idx_big5_psy_generated_at');
            });
        }

        Schema::table('big5_psychometrics_reports', function (Blueprint $table) use ($isSqlite) {
            if (! Schema::hasColumn('big5_psychometrics_reports', 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'scale_code')) {
                $table->string('scale_code', 32)->default('BIG5_OCEAN');
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'locale')) {
                $table->string('locale', 16)->default('zh-CN');
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'region')) {
                $table->string('region', 32)->nullable();
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'norms_version')) {
                $table->string('norms_version', 64)->nullable();
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'time_window')) {
                $table->string('time_window', 64)->default('last_90_days');
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'sample_n')) {
                $table->unsignedInteger('sample_n')->default(0);
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'metrics_json')) {
                if ($isSqlite) {
                    $table->text('metrics_json')->nullable();
                } else {
                    $table->json('metrics_json')->nullable();
                }
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'generated_at')) {
                $table->timestamp('generated_at')->nullable();
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('big5_psychometrics_reports', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};

